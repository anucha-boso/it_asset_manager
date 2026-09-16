<?php
/**
 * Access Request — Detail / Approve / Reject / Action
 * public/access-requests/detail.php?id=<request_id>
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/require_employee_link.php';
require_once __DIR__ . '/../../includes/employee_log.php';
require_once __DIR__ . '/../../config/notification.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);

$pdo        = db();
$employeeId = require_employee_link();
$myRole     = $_SESSION['iam_role'] ?? '';
$isItAdmin  = in_array($myRole, ['it_admin', 'it_staff'], true);

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$stmt = $pdo->prepare("
    SELECT r.*, a.app_name, s.site_name, lv.level_name
    FROM access_requests r
    JOIN access_applications a ON a.id = r.application_id
    LEFT JOIN sites s ON s.id = r.site_id
    LEFT JOIN access_application_levels lv ON lv.id = r.access_level_id
    WHERE r.id = :id
");

$stmt->execute([':id' => $id]);
$req = $stmt->fetch();
if (!$req) { http_response_code(404); exit('Request not found'); }

// ------ ต้องเป็นเจ้าของคำขอ, อยู่ใน approval chain, หรือเป็น it_admin/it_staff เท่านั้นถึงจะดูได้ ------
$stepsStmt = $pdo->prepare("SELECT * FROM access_request_steps WHERE request_id = :id ORDER BY step_order");
$stepsStmt->execute([':id' => $id]);
$steps = $stepsStmt->fetchAll();

$isRequestor  = ((int)$req['requestor_employee_id'] === $employeeId);
$isBeneficiary = ($req['beneficiary_employee_id'] !== null && (int)$req['beneficiary_employee_id'] === $employeeId);
$isInChain    = false;
foreach ($steps as $s) {
    if ((int)($s['assignee_employee_id'] ?? 0) === $employeeId) { $isInChain = true; break; }
}
if (!$isRequestor && !$isBeneficiary && !$isInChain && !$isItAdmin) {
    http_response_code(403);
    exit('คุณไม่มีสิทธิ์ดูคำขอนี้');
}

// ------ ดึงชื่อพนักงานที่เกี่ยวข้องทั้งหมดในคราวเดียว (requestor + ทุก assignee) ------
$empIds = array_filter(array_unique(array_merge(
    [(int)$req['requestor_employee_id']],
    array_map(fn($s) => (int)($s['assignee_employee_id'] ?? 0), $steps)
)));
$empNames = [];
if ($empIds) {
    $in = implode(',', array_fill(0, count($empIds), '?'));
    $eStmt = employee_db()->prepare("SELECT id, title, first_name, last_name FROM employees WHERE id IN ({$in})");
    $eStmt->execute(array_values($empIds));
    foreach ($eStmt->fetchAll() as $e) {
        $empNames[(int)$e['id']] = trim($e['title'] . ' ' . $e['first_name'] . ' ' . $e['last_name']);
    }
}

// ------ step ที่กำลัง pending อยู่ (อ้างจาก current_step_id) ------
$currentStep = null;
foreach ($steps as $s) {
    if ((int)$s['id'] === (int)$req['current_step_id']) { $currentStep = $s; break; }
}

// ------------------------------------------------------------------
//  POST: Approve / Reject / Action
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $currentStep) {
    csrf_verify();
    $decision = $_POST['decision'] ?? '';
    $comment  = trim((string)($_POST['comment'] ?? ''));

        $isSelfApproval = ((int)$req['requestor_employee_id'] === $employeeId) && $myRole !== 'it_admin';

    $canAct = !$isSelfApproval && (
        $currentStep['step_role'] === 'IT_ACTION'
            ? $isItAdmin
            : ((int)$currentStep['assignee_employee_id'] === $employeeId)
    );

    if (!$canAct) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์ดำเนินการ step นี้ (ไม่สามารถอนุมัติคำขอของตัวเองได้)');
    }

    $pdo->beginTransaction();
    $notifyAfterCommit = null; // เก็บ callback ไว้ยิงหลัง commit สำเร็จเท่านั้น

    try {
        $actedByAd = $_SESSION['iam_username'] ?? null;

          if ($decision === 'reject') {
              $pdo->prepare("UPDATE access_request_steps SET action='Rejected', comment=:c, acted_by_ad=:ad, acted_at=NOW() WHERE id=:id")
                  ->execute([':c' => $comment, ':ad' => $actedByAd, ':id' => $currentStep['id']]);

              // ------ step ที่เหลือที่ยัง Pending อยู่ → ยกเลิกไปด้วย ------
              $pdo->prepare("
                  UPDATE access_request_steps
                  SET action = 'Cancelled'
                  WHERE request_id = :rid AND step_order > :ord AND action = 'Pending'
              ")->execute([':rid' => $id, ':ord' => $currentStep['step_order']]);

              $pdo->prepare("UPDATE access_requests SET status='Rejected', completed_at=NOW() WHERE id=:id")
                  ->execute([':id' => $id]);

              // ── History log (เฟส 2) — log เฉพาะเมื่อ beneficiary เป็นพนักงานภายใน
              //    จริง (ไม่ใช่ NULL/external) ตามกติกาที่ตกลงกันไว้ ─────────────
              if ($req['beneficiary_employee_id'] !== null) {
                  logEmployeeTransaction(
                      $pdo,
                      (int)$req['beneficiary_employee_id'],
                      'Access Request Rejected',
                      'access_requests',
                      $id,
                      'Rejected: ' . $req['app_name'] . ($comment !== '' ? ' — ' . $comment : ''),
                      $actedByAd
                  );
              }

            $notifyAfterCommit = function () use ($id, $req, $currentStep, $comment) {
                $emails = getRequestStakeholderEmails($id);
                if ($emails) {
                    notifyEvent('ACCESS_REQUEST_REJECTED', [
                        'asset'     => $req['app_name'],
                        'reporter'  => $req['requestor_name'],
                        'issues'    => [$comment !== '' ? $comment : 'ไม่ระบุเหตุผล'],
                        'ticket_no' => $req['request_no'],
                        'note'      => "ถูกปฏิเสธที่ขั้นตอน: {$currentStep['step_role']}",
                        'to_email'  => $emails,
                    ]);
                }
            };

        } elseif ($decision === 'approve' && $currentStep['step_role'] !== 'IT_ACTION') {
            $pdo->prepare("UPDATE access_request_steps SET action='Approved', comment=:c, acted_by_ad=:ad, acted_at=NOW() WHERE id=:id")
                ->execute([':c' => $comment, ':ad' => $actedByAd, ':id' => $currentStep['id']]);

            $nextStmt = $pdo->prepare("SELECT * FROM access_request_steps WHERE request_id=:rid AND step_order > :ord ORDER BY step_order LIMIT 1");
            $nextStmt->execute([':rid' => $id, ':ord' => $currentStep['step_order']]);
            $next = $nextStmt->fetch();

            $newStatus = $next['step_role'] === 'IT_ACTION' ? 'Pending IT Action' : 'Pending Approver2';
            $pdo->prepare("UPDATE access_requests SET status=:st, current_step_id=:sid WHERE id=:id")
                ->execute([':st' => $newStatus, ':sid' => $next['id'], ':id' => $id]);

            // ── History log (เฟส 2) ──────────────────────────────────────────
            if ($req['beneficiary_employee_id'] !== null) {
                logEmployeeTransaction(
                    $pdo,
                    (int)$req['beneficiary_employee_id'],
                    'Access Request Approved',
                    'access_requests',
                    $id,
                    'Approved (' . $currentStep['step_role'] . '): ' . $req['app_name'],
                    $actedByAd
                );
            }

            $notifyAfterCommit = function () use ($id, $req, $next) {
                // IT_ACTION ไม่มี assignee ตายตัว (ใครใน it_admin/it_staff ก็ทำได้) จึงไม่มี email เจาะจงให้ส่ง
                if (!$next['assignee_employee_id']) {
                    error_log("[NOTIFY SKIP] next step is IT_ACTION (no fixed assignee) — request {$req['request_no']}");
                    return;
                }
                $emails = getEmployeeEmails([(int)$next['assignee_employee_id']]);
                if ($emails) {
                    notifyEvent('ACCESS_REQUEST_APPROVED', [
                        'asset'     => $req['app_name'],
                        'reporter'  => $req['requestor_name'],
                        'issues'    => ["รออนุมัติขั้นตอนถัดไป: {$next['step_role']}"],
                        'ticket_no' => $req['request_no'],
                        'note'      => 'อนุมัติขั้นก่อนหน้าแล้ว รอดำเนินการต่อ',
                        'to_email'  => $emails,
                    ]);
                } else {
                    error_log("[NOTIFY SKIP] next approver (employee_id={$next['assignee_employee_id']}) has no email — request {$req['request_no']}");
                }
            };

        } elseif ($decision === 'action' && $currentStep['step_role'] === 'IT_ACTION') {
            $pdo->prepare("UPDATE access_request_steps SET action='Actioned', comment=:c, acted_by_ad=:ad, acted_at=NOW() WHERE id=:id")
                ->execute([':c' => $comment, ':ad' => $actedByAd, ':id' => $currentStep['id']]);
            $pdo->prepare("UPDATE access_requests SET status='Completed', completed_at=NOW() WHERE id=:id")
                ->execute([':id' => $id]);

            // ── History log (เฟส 2) — IT_ACTION คือจุดที่สิทธิ์ถูกให้จริง
            //    (ต่างจาก 'Access Request Approved' ที่เป็นแค่ผ่านขั้นอนุมัติ) ──
            if ($req['beneficiary_employee_id'] !== null) {
                logEmployeeTransaction(
                    $pdo,
                    (int)$req['beneficiary_employee_id'],
                    'Permission Grant',
                    'access_requests',
                    $id,
                    'Grant ' . $req['app_name'] . ' (' . ($req['level_name'] ?: ($req['access_level_text'] ?: 'User')) . ')',
                    $actedByAd
                );
            }

            $notifyAfterCommit = function () use ($id, $req) {
                $emails = getRequestStakeholderEmails($id);
                if ($emails) {
                    notifyEvent('ACCESS_REQUEST_COMPLETED', [
                        'asset'     => $req['app_name'],
                        'reporter'  => $req['requestor_name'],
                        'issues'    => ['IT ดำเนินการเรียบร้อยแล้ว'],
                        'ticket_no' => $req['request_no'],
                        'note'      => 'คำขอเสร็จสมบูรณ์',
                        'to_email'  => $emails,
                    ]);
                }
            };
        }

        $pdo->commit();

        // ------ ยิง notify หลัง commit สำเร็จเท่านั้น (กันแจ้งเตือนก่อนข้อมูลบันทึกจริง) ------
        if ($notifyAfterCommit) {
            $notifyAfterCommit();
        }

        header('Location: /it-asset-manager/access-requests/detail.php?id=' . $id);
        exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[AR ACTION FAIL] ' . $e->getMessage());
        $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ดำเนินการไม่สำเร็จ'];
    }
}

$page_title  = $req['request_no'];
$active_menu = 'access_requests';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/access-requests/index.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับไปหน้ารายการ
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($req['request_no']) ?> — <?= e($req['app_name']) ?></h2>
</div>

<div class="card mb-3">
    <div class="card-body">
        <div class="row g-2 small">
            <div class="col-md-4"><strong>ผู้ขอ:</strong> <?= e($req['requestor_name']) ?></div>
            <div class="col-md-4"><strong>แผนก:</strong> <?= e($req['department'] ?? '—') ?></div>
            <div class="col-md-4">
                <strong>ขอสิทธิ์ให้:</strong>
                <?php if ($req['beneficiary_employee_id'] === null): ?>
                    <span class="text-warning"><?= e($req['beneficiary_name']) ?></span>
                    <span class="badge text-bg-secondary">บุคคลภายนอก</span>
                    <div class="text-muted small">
                        สังกัด: <?= e($req['beneficiary_external_org'] ?? '—') ?>
                        · ติดต่อ: <?= e($req['beneficiary_external_contact'] ?? '—') ?>
                    </div>
                <?php elseif ((int)$req['beneficiary_employee_id'] === (int)$req['requestor_employee_id']): ?>
                    ตัวเอง
                <?php else: ?>
                    <span class="text-primary"><?= e($req['beneficiary_name']) ?></span>
                    <?php if ($req['beneficiary_department']): ?>
                        <span class="text-muted">(<?= e($req['beneficiary_department']) ?>)</span>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            <div class="col-md-4"><strong>Site:</strong> <?= e($req['site_name'] ?? '—') ?></div>
            <div class="col-md-4"><strong>สถานะ:</strong> <span class="badge text-bg-info"><?= e($req['status']) ?></span></div>
            <div class="col-md-8"><strong>Access Level:</strong> <?= e($req['level_name'] ?: ($req['access_level_text'] ?: '—')) ?></div>
            <div class="col-12"><strong>เหตุผล:</strong> <?= nl2br(e($req['reason'])) ?></div>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-header bg-white"><strong>Approval Timeline</strong></div>
    <ul class="list-group list-group-flush">
        <?php foreach ($steps as $s):
            $badge = match($s['action']) {
                'Approved','Actioned' => 'text-bg-success',
                'Rejected'            => 'text-bg-danger',
                'Cancelled'           => 'text-bg-light border',
                default               => 'text-bg-secondary',
            };
            $assigneeLabel = $s['step_role'] === 'IT_ACTION'
                ? 'IT Admin / Staff'
                : ($empNames[(int)$s['assignee_employee_id']] ?? '—');
        ?>
            <li class="list-group-item d-flex justify-content-between align-items-start">
                <div>
                    <div class="fw-semibold"><?= e($s['step_role']) ?></div>
                    <div class="text-muted small">
                        <?= e($assigneeLabel) ?>
                        <?php if ($s['comment']): ?> — <?= e($s['comment']) ?><?php endif; ?>
                        <?php if ($s['acted_at']): ?> (<?= e($s['acted_at']) ?>)<?php endif; ?>
                    </div>
                </div>
                <span class="badge <?= $badge ?>">
                    <?= e($s['action'] === 'Cancelled' ? 'ยกเลิก (เพราะขั้นก่อนหน้าถูก Reject)' : $s['action']) ?>
                </span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<?php
$isSelfApproval = ((int)$req['requestor_employee_id'] === $employeeId) && $myRole !== 'it_admin';

$canAct = $currentStep && !$isSelfApproval && (
    $currentStep['step_role'] === 'IT_ACTION'
        ? $isItAdmin
        : ((int)$currentStep['assignee_employee_id'] === $employeeId)
); ?>
<?php if ($isSelfApproval && $currentStep && !in_array($req['status'], ['Completed','Rejected','Cancelled'], true)): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle"></i> คุณไม่สามารถอนุมัติคำขอของตัวเองได้ กรุณารอผู้อนุมัติท่านอื่นดำเนินการ
</div>
<?php endif; ?>

<?php if ($canAct && !in_array($req['status'], ['Completed','Rejected','Cancelled'], true)):
?>
<div class="card">
    <div class="card-header bg-white"><strong>ดำเนินการ</strong></div>
    <div class="card-body">
        <form method="post" class="row g-2" id="actionForm">
            
            <?php csrf_field(); ?>
            <div class="col-12">
                <textarea name="comment" id="commentBox" class="form-control" rows="2"
                          placeholder="ความเห็น (จำเป็นถ้าปฏิเสธ)"></textarea>
                <div class="text-danger small mt-1 d-none" id="commentRequiredMsg">
                    กรุณาระบุเหตุผลก่อนกดปฏิเสธ
                </div>
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
                <?php if ($currentStep['step_role'] === 'IT_ACTION'): ?>
                    <button name="decision" value="action" class="btn btn-primary">
                        <i class="bi bi-check2-square"></i> ดำเนินการเรียบร้อย
                    </button>
                <?php else: ?>
                    <button name="decision" value="approve" class="btn btn-success">
                        <i class="bi bi-check-lg"></i> Approve
                    </button>
                <?php endif; ?>
                <button type="submit" name="decision" value="reject" class="btn btn-outline-danger" id="rejectBtn">
                    <i class="bi bi-x-lg"></i> Reject
                </button>
            </div>
        </form>

        <script>
        document.getElementById('rejectBtn').addEventListener('click', function (e) {
            const comment = document.getElementById('commentBox').value.trim();
            if (comment === '') {
                e.preventDefault();
                document.getElementById('commentRequiredMsg').classList.remove('d-none');
                document.getElementById('commentBox').focus();
            }
        });
        document.getElementById('actionForm')?.addEventListener('submit', function (e) {
    // ให้ validation (reject ต้องมี comment) ทำงานก่อน ไม่ disable ถ้ายังไม่ผ่าน
    const submitter = e.submitter; // ปุ่มที่ถูกกด (Approve/Reject/Action)
    if (!submitter) return;

    setTimeout(() => {
        // หน่วงเล็กน้อยให้ validation JS เดิม (comment required) ทำงานก่อน
        if (!e.defaultPrevented) {
            document.querySelectorAll('#actionForm button[type="submit"], #actionForm button:not([type])').forEach(b => {
                b.disabled = true;
            });
            submitter.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังดำเนินการ...';
        }
    }, 0);
});
        </script>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
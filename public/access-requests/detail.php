<?php
/**
 * Access Request — Detail / Approve / Reject / Action
 * public/access-requests/detail.php?id=<request_id>
 *
 * 2026-10 redesign:
 *  - Layout ใหม่: สรุป "ใคร → ได้สิทธิ์อะไร" ด้านบน, timeline แบบ stepper, แผงตัดสินใจด้านขวา
 *  - Reject ต้องกด 2 จังหวะ (เข้าโหมดปฏิเสธ → ยืนยัน) + บังคับเหตุผลทั้งฝั่ง JS และ server
 *  - แสดง flash message (เดิมหน้านี้ไม่ render flash เลย)
 *  - POST logic (approve / reject / action / log / notify) คงเดิมทั้งหมด
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
    SELECT r.*, a.app_name, a.it_action_note, s.site_name, lv.level_name,
           TIMESTAMPDIFF(MINUTE, r.requested_at, NOW()) AS wait_minutes
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

    // ------ NEW: บังคับเหตุผลตอน reject ฝั่ง server ด้วย (JS validation ถูก bypass ได้) ------
    if ($decision === 'reject' && $comment === '') {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'กรุณาระบุเหตุผลก่อนปฏิเสธคำขอ'];
        header('Location: /it-asset-manager/access-requests/detail.php?id=' . $id);
        exit;
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

// ==================================================================
//  VIEW PREP — คำนวณค่าที่ใช้แสดงผลอย่างเดียว ไม่แตะ DB เพิ่ม
// ==================================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$isOpen = !in_array($req['status'], ['Completed', 'Rejected', 'Cancelled'], true);

$isSelfApproval = ((int)$req['requestor_employee_id'] === $employeeId) && $myRole !== 'it_admin';
$canAct = $isOpen && $currentStep && !$isSelfApproval && (
    $currentStep['step_role'] === 'IT_ACTION'
        ? $isItAdmin
        : ((int)$currentStep['assignee_employee_id'] === $employeeId)
);

// ------ ตำแหน่งของ step ปัจจุบัน + step ถัดไป (ใช้แสดง "ขั้น x/y" และ "เมื่ออนุมัติจะส่งต่อให้ใคร") ------
$totalSteps = count($steps);
$currentIdx = null;
foreach ($steps as $i => $s) {
    if ($currentStep && (int)$s['id'] === (int)$currentStep['id']) { $currentIdx = $i; break; }
}
$nextStep = ($currentIdx !== null && isset($steps[$currentIdx + 1])) ? $steps[$currentIdx + 1] : null;

$roleLabels = [
    'APPROVER1' => 'Approver 1',
    'APPROVER2' => 'Approver 2',
    'IT_ACTION' => 'IT ดำเนินการ',
];
$roleLabel = fn(string $role): string => $roleLabels[$role] ?? $role;

// ------ ชื่อผู้รับผิดชอบของ step (IT_ACTION ไม่มี assignee ตายตัว) ------
$assigneeName = function (array $s) use ($empNames): string {
    if ($s['step_role'] === 'IT_ACTION') {
        return 'IT Admin / Staff';
    }
    return $empNames[(int)($s['assignee_employee_id'] ?? 0)] ?? '—';
};

// ------ ระยะเวลาที่รอ (เช่น "2 วัน", "4 ชม.") ------
// คำนวณจาก wait_minutes ที่ MySQL คิดให้ (TIMESTAMPDIFF กับ NOW() ของ DB)
// ไม่ใช้ PHP DateTime เพราะ timezone ของ PHP-FPM ไม่ตรงกับ MySQL → เคยได้ "6 ชม." ทั้งที่เพิ่งยื่น
$ageText = function (int $minutes): string {
    $minutes = max(0, $minutes);
    if ($minutes >= 1440) return intdiv($minutes, 1440) . ' วัน';
    if ($minutes >= 60)   return intdiv($minutes, 60) . ' ชม.';
    return max(1, $minutes) . ' นาที';
};
$fmtDate = fn(?string $dt): string => $dt ? date('d/m/Y H:i', strtotime($dt)) : '';

// ------ ป้ายสถานะด้านบน ------
$stepNo = ($currentIdx ?? 0) + 1;
[$statusText, $statusClass] = match ($req['status']) {
    'Completed'         => ['เสร็จสมบูรณ์', 'ar-pill-ok'],
    'Rejected'          => ['ถูกปฏิเสธ', 'ar-pill-bad'],
    'Cancelled'         => ['ยกเลิก', 'ar-pill-muted'],
    'Pending IT Action' => ["รอ IT ดำเนินการ · ขั้น {$stepNo}/{$totalSteps}", 'ar-pill-wait'],
    default             => ["รออนุมัติ · ขั้น {$stepNo}/{$totalSteps}", 'ar-pill-wait'],
};

$waitMinutes = (int)$req['wait_minutes'];
$waitDays    = intdiv(max(0, $waitMinutes), 1440);

// ------ ผู้รับสิทธิ์ ------
$benIsExternal = $req['beneficiary_employee_id'] === null;
$benIsSelf     = !$benIsExternal && (int)$req['beneficiary_employee_id'] === (int)$req['requestor_employee_id'];
$levelText     = $req['level_name'] ?: ($req['access_level_text'] ?: '—');

// ------ ข้อความใต้ปุ่ม: บอกว่ากดแล้วจะเกิดอะไรต่อ ------
$nextHint = '';
if ($canAct) {
    if ($currentStep['step_role'] === 'IT_ACTION') {
        $nextHint = 'เมื่อกดแล้ว คำขอจะปิดเป็น Completed และบันทึกประวัติสิทธิ์ของผู้รับ';
    } elseif ($nextStep && $nextStep['step_role'] === 'IT_ACTION') {
        $nextHint = 'เมื่ออนุมัติ คำขอจะส่งต่อให้ทีม IT ดำเนินการ';
    } elseif ($nextStep) {
        $nextHint = 'เมื่ออนุมัติ ระบบจะส่งต่อให้ ' . $assigneeName($nextStep) . ' (' . $roleLabel($nextStep['step_role']) . ')';
    }
}

$page_title  = $req['request_no'];
$active_menu = 'access_requests';
require __DIR__ . '/../../includes/header.php';
?>

<style>
/* ---- Access Request detail (scoped ด้วย prefix ar-) ---- */
.ar-pill { display:inline-block; font-size:.8rem; font-weight:600; padding:.15rem .65rem; border-radius:999px; }
.ar-pill-wait  { color:#8A4B00; background:#FFF1D6; }
.ar-pill-ok    { color:#0F6B45; background:#DDF3E8; }
.ar-pill-bad   { color:#B42318; background:#FDE7E4; }
.ar-pill-muted { color:#52606D; background:#EEF1F4; }
.ar-no { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.8rem; color:#52606D; background:#EEF1F4; padding:.1rem .5rem; border-radius:6px; }
.ar-label { font-size:.75rem; color:#616E7C; }
.ar-section-title { font-size:.8rem; font-weight:600; color:#52606D; letter-spacing:.02em; }
.ar-summary { background:#F7F9FA; border-radius:10px; padding:1rem; }
.ar-reason { background:#F7F9FA; border-radius:10px; padding:.85rem 1rem; }
.ar-step { display:flex; gap:.9rem; }
.ar-step-rail { display:flex; flex-direction:column; align-items:center; width:28px; flex:none; }
.ar-dot { width:28px; height:28px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; box-sizing:border-box; }
.ar-dot-done { background:#0F7A4F; color:#fff; }
.ar-dot-bad  { background:#B42318; color:#fff; }
.ar-dot-cur  { background:#fff; border:2px solid #D97706; color:#B45309; }
.ar-dot-wait { background:#EEF1F4; color:#7B8794; }
.ar-line { width:2px; flex:1; min-height:20px; background:#E2E5E9; }
.ar-step-body { padding-bottom:1.1rem; min-width:0; flex:1; }
.ar-panel { position:sticky; top:1rem; }
.ar-comment-reject { border:2px solid #B42318 !important; background:#FFFBFA; }
</style>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- ====== Header ====== -->
<div class="mb-4">
    <a href="/it-asset-manager/access-requests/index.php" class="text-decoration-none small">
        <i class="bi bi-arrow-left"></i> กลับไปหน้ารายการ
    </a>
    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
        <span class="ar-no"><?= e($req['request_no']) ?></span>
        <span class="ar-pill <?= $statusClass ?>"><?= e($statusText) ?></span>
    </div>
    <h2 class="h4 fw-bold mb-1 mt-2">ขอสิทธิ์ <?= e($req['app_name']) ?> · <?= e($levelText) ?></h2>
    <div class="text-muted small">
        ยื่นเมื่อ <?= e($fmtDate($req['requested_at'])) ?>
        <?php if ($isOpen): ?>
            · รอมาแล้ว <strong class="<?= $waitDays >= 3 ? 'text-danger' : 'text-warning-emphasis' ?>"><?= e($ageText($waitMinutes)) ?></strong>
        <?php elseif (!empty($req['completed_at'])): ?>
            · ปิดเมื่อ <?= e($fmtDate($req['completed_at'])) ?>
        <?php endif; ?>
    </div>
</div>

<div class="row g-4 align-items-start">

    <!-- ====== ซ้าย: สรุปคำขอ + timeline ====== -->
    <div class="col-lg-8">

        <div class="card mb-4">
            <div class="card-body p-4">
                <div class="ar-section-title mb-3">สรุปคำขอ</div>

                <!-- ใคร → ได้สิทธิ์อะไร -->
                <div class="ar-summary d-flex flex-wrap align-items-center gap-3 mb-3">
                    <div class="d-flex align-items-center gap-2 flex-grow-1" style="min-width:200px">
                        <i class="bi bi-person-circle fs-2 text-secondary"></i>
                        <div>
                            <div class="ar-label">ผู้ได้รับสิทธิ์</div>
                            <div class="fw-semibold">
                                <?= e($req['beneficiary_name']) ?>
                                <?php if ($benIsSelf): ?><span class="text-muted fw-normal">(ตัวผู้ขอเอง)</span><?php endif; ?>
                                <?php if ($benIsExternal): ?><span class="badge text-bg-warning ms-1">บุคคลภายนอก</span><?php endif; ?>
                            </div>
                            <?php if (!$benIsExternal && !empty($req['beneficiary_department'])): ?>
                                <div class="small text-muted"><?= e($req['beneficiary_department']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <i class="bi bi-arrow-right fs-4 text-secondary" aria-hidden="true"></i>
                    <div class="flex-grow-1" style="min-width:200px">
                        <div class="ar-label">ระบบ / Access Level</div>
                        <div class="fw-semibold"><?= e($req['app_name']) ?></div>
                        <div class="small text-muted"><?= e($levelText) ?><?php if ($req['site_name']): ?> · Site <?= e($req['site_name']) ?><?php endif; ?></div>
                    </div>
                </div>

                <?php if ($benIsExternal): ?>
                    <div class="alert alert-warning d-flex gap-2 small mb-3">
                        <i class="bi bi-exclamation-triangle"></i>
                        <div>
                            <strong>บุคคลภายนอก</strong> —
                            สังกัด: <?= e($req['beneficiary_external_org'] ?? '—') ?>
                            · ติดต่อ: <?= e($req['beneficiary_external_contact'] ?? '—') ?>
                            <div>กรุณาตรวจสอบความจำเป็นและระยะเวลาการใช้งานก่อนอนุมัติ</div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row g-3 mb-3">
                    <div class="col-sm-4">
                        <div class="ar-label">ผู้ยื่นคำขอ</div>
                        <div><?= e($req['requestor_name']) ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="ar-label">แผนกผู้ยื่น</div>
                        <div><?= e($req['department'] ?? '—') ?></div>
                    </div>
                    <div class="col-sm-4">
                        <div class="ar-label">Site</div>
                        <div><?= e($req['site_name'] ?? '—') ?></div>
                    </div>
                </div>

                <div class="ar-label mb-1">เหตุผลในการขอ</div>
                <div class="ar-reason"><?= nl2br(e($req['reason'])) ?></div>
            </div>
        </div>

        <!-- ====== Timeline ====== -->
        <div class="card">
            <div class="card-body p-4">
                <div class="ar-section-title mb-3">ขั้นตอนการอนุมัติ</div>

                <!-- step 0: ยื่นคำขอ (ไม่มีใน access_request_steps แต่แสดงให้เห็นจุดเริ่ม) -->
                <div class="ar-step">
                    <div class="ar-step-rail">
                        <div class="ar-dot ar-dot-done"><i class="bi bi-check-lg"></i></div>
                        <div class="ar-line"></div>
                    </div>
                    <div class="ar-step-body">
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <span class="fw-semibold">ยื่นคำขอ</span>
                            <span class="ar-pill ar-pill-ok">เสร็จ</span>
                        </div>
                        <div class="small text-muted"><?= e($req['requestor_name']) ?></div>
                        <div class="small text-secondary"><?= e($fmtDate($req['requested_at'])) ?></div>
                    </div>
                </div>

                <?php foreach ($steps as $i => $s):
                    $isCurrent = $isOpen && $currentStep && (int)$s['id'] === (int)$currentStep['id'];
                    [$dotClass, $dotContent, $tagClass, $tagText] = match (true) {
                        in_array($s['action'], ['Approved', 'Actioned'], true)
                                         => ['ar-dot-done', '<i class="bi bi-check-lg"></i>', 'ar-pill-ok', $s['action'] === 'Actioned' ? 'ดำเนินการแล้ว' : 'อนุมัติแล้ว'],
                        $s['action'] === 'Rejected'
                                         => ['ar-dot-bad', '<i class="bi bi-x-lg"></i>', 'ar-pill-bad', 'ปฏิเสธ'],
                        $s['action'] === 'Cancelled'
                                         => ['ar-dot-wait', (string)($i + 1), 'ar-pill-muted', 'ยกเลิก (ขั้นก่อนหน้าถูกปฏิเสธ)'],
                        $isCurrent       => ['ar-dot-cur', (string)($i + 1), 'ar-pill-wait', 'รอดำเนินการ'],
                        default          => ['ar-dot-wait', (string)($i + 1), 'ar-pill-muted', 'ยังไม่ถึงคิว'],
                    };
                    $who = $assigneeName($s);
                    if ($isCurrent && $s['step_role'] !== 'IT_ACTION' && (int)$s['assignee_employee_id'] === $employeeId) {
                        $who .= ' (คุณ)';
                    }
                    if (!empty($s['acted_by_ad']) && $s['step_role'] === 'IT_ACTION') {
                        $who = 'ดำเนินการโดย ' . $s['acted_by_ad'];
                    }
                    $isLast = $i === $totalSteps - 1;
                ?>
                    <div class="ar-step">
                        <div class="ar-step-rail">
                            <div class="ar-dot <?= $dotClass ?>"><?= $dotContent /* ค่าคงที่จาก match ด้านบน ไม่ใช่ user input */ ?></div>
                            <?php if (!$isLast): ?><div class="ar-line"></div><?php endif; ?>
                        </div>
                        <div class="ar-step-body">
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <span class="fw-semibold"><?= e($roleLabel($s['step_role'])) ?></span>
                                <span class="ar-pill <?= $tagClass ?>"><?= e($tagText) ?></span>
                            </div>
                            <div class="small text-muted"><?= e($who) ?></div>
                            <?php if ($s['acted_at'] || $s['comment']): ?>
                                <div class="small text-secondary">
                                    <?= e($fmtDate($s['acted_at'])) ?>
                                    <?php if ($s['comment']): ?> · “<?= e($s['comment']) ?>”<?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ====== ขวา: แผงตัดสินใจ / สถานะ ====== -->
    <div class="col-lg-4">
        <div class="ar-panel">

        <?php if ($canAct): ?>
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="ar-section-title"><?= $currentStep['step_role'] === 'IT_ACTION' ? 'ขั้นตอน IT Action' : 'คุณคือ ' . e($roleLabel($currentStep['step_role'])) ?></div>
                    <div class="h5 fw-bold mb-3" id="panelTitle"
                         data-normal="<?= $currentStep['step_role'] === 'IT_ACTION' ? 'ให้สิทธิ์ตามคำขอ' : 'พิจารณาคำขอ' ?>"
                         data-reject="ปฏิเสธคำขอนี้">
                        <?= $currentStep['step_role'] === 'IT_ACTION' ? 'ให้สิทธิ์ตามคำขอ' : 'พิจารณาคำขอ' ?>
                    </div>

                    <?php if ($currentStep['step_role'] === 'IT_ACTION' && !empty($req['it_action_note'])): ?>
                        <div class="ar-reason small mb-3">
                            <div class="fw-semibold mb-1">สิ่งที่ต้องทำ</div>
                            <?= nl2br(e($req['it_action_note'])) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post" id="actionForm">
                        <?php csrf_field(); ?>

                        <label for="commentBox" class="form-label small fw-semibold" id="commentLabel"
                               data-normal="ความเห็น (ไม่บังคับ)" data-reject="เหตุผลที่ปฏิเสธ *">ความเห็น (ไม่บังคับ)</label>
                        <textarea name="comment" id="commentBox" class="form-control" rows="3"
                                  data-normal="ข้อความถึงผู้ขอ / ขั้นถัดไป"
                                  data-reject="เช่น ไม่ใช่ขอบเขตงานของตำแหน่งนี้"
                                  placeholder="ข้อความถึงผู้ขอ / ขั้นถัดไป"></textarea>
                        <div class="text-danger small mt-1 d-none" id="commentRequiredMsg">
                            ต้องระบุเหตุผลก่อนยืนยันการปฏิเสธ — ผู้ขอจะได้รับอีเมลพร้อมเหตุผลนี้
                        </div>

                        <!-- โหมดปกติ -->
                        <div class="d-grid gap-2 mt-3" id="normalBtns">
                            <?php if ($currentStep['step_role'] === 'IT_ACTION'): ?>
                                <button type="submit" name="decision" value="action" class="btn btn-success btn-lg">
                                    <i class="bi bi-check2-square"></i> ดำเนินการเรียบร้อย
                                </button>
                            <?php else: ?>
                                <button type="submit" name="decision" value="approve" class="btn btn-success btn-lg">
                                    <i class="bi bi-check-lg"></i> อนุมัติ
                                </button>
                            <?php endif; ?>
                            <button type="button" class="btn btn-outline-danger" id="rejectStartBtn">
                                ปฏิเสธคำขอ…
                            </button>
                        </div>

                        <!-- โหมดปฏิเสธ (ต้องกดยืนยันอีกครั้ง) -->
                        <div class="d-flex gap-2 mt-3 d-none" id="rejectBtns">
                            <button type="button" class="btn btn-outline-secondary flex-fill" id="rejectBackBtn">กลับ</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-danger flex-fill" id="rejectConfirmBtn">
                                ยืนยันปฏิเสธ
                            </button>
                        </div>

                        <?php if ($nextHint !== ''): ?>
                            <div class="small text-muted border-top pt-3 mt-3" id="nextHint"
                                 data-normal="<?= e($nextHint) ?>"
                                 data-reject="ขั้นที่เหลือทั้งหมดจะถูกยกเลิก และคำขอปิดเป็น Rejected"><?= e($nextHint) ?></div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

        <?php elseif ($isOpen && $currentStep): ?>
            <!-- ดูได้แต่ทำไม่ได้: บอกว่าตอนนี้รอใคร -->
            <div class="card">
                <div class="card-body p-4">
                    <div class="ar-section-title mb-1">สถานะตอนนี้</div>
                    <div class="fw-semibold mb-2">
                        รอ <?= e($assigneeName($currentStep)) ?> (<?= e($roleLabel($currentStep['step_role'])) ?>)
                    </div>
                    <?php
                    $iAmCurrentActor = $currentStep['step_role'] === 'IT_ACTION'
                        ? $isItAdmin
                        : ((int)$currentStep['assignee_employee_id'] === $employeeId);
                    if ($isSelfApproval && $iAmCurrentActor): ?>
                        <div class="alert alert-warning small mb-0">
                            <i class="bi bi-exclamation-triangle"></i>
                            นี่คือคำขอของคุณเอง ระบบไม่อนุญาตให้อนุมัติคำขอของตัวเอง กรุณาให้ผู้อนุมัติท่านอื่นดำเนินการ
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- ปิดแล้ว -->
            <div class="card">
                <div class="card-body p-4">
                    <div class="ar-section-title mb-1">ผลการพิจารณา</div>
                    <span class="ar-pill <?= $statusClass ?>"><?= e($statusText) ?></span>
                </div>
            </div>
        <?php endif; ?>

            <div class="small text-muted mt-3 px-1">
                ผู้ขอ ผู้อนุมัติ และ IT จะได้รับอีเมลแจ้งผลอัตโนมัติ
            </div>
        </div>
    </div>
</div>

<?php if ($canAct): ?>
<script>
(function () {
    const form       = document.getElementById('actionForm');
    const comment    = document.getElementById('commentBox');
    const msg        = document.getElementById('commentRequiredMsg');
    const normalBtns = document.getElementById('normalBtns');
    const rejectBtns = document.getElementById('rejectBtns');
    // element ที่สลับข้อความระหว่างโหมดปกติ/โหมดปฏิเสธ (อ่านจาก data-normal / data-reject)
    const swaps = ['panelTitle', 'commentLabel', 'nextHint']
        .map(id => document.getElementById(id)).filter(Boolean);

    function setRejectMode(on) {
        normalBtns.classList.toggle('d-none', on);
        rejectBtns.classList.toggle('d-none', !on);
        comment.classList.toggle('ar-comment-reject', on);
        comment.placeholder = on ? comment.dataset.reject : comment.dataset.normal;
        swaps.forEach(el => { el.textContent = on ? el.dataset.reject : el.dataset.normal; });
        if (!on) msg.classList.add('d-none');
        if (on) comment.focus();
    }

    document.getElementById('rejectStartBtn').addEventListener('click', () => setRejectMode(true));
    document.getElementById('rejectBackBtn').addEventListener('click', () => setRejectMode(false));

    // ปฏิเสธต้องมีเหตุผล (server ตรวจซ้ำอีกชั้น)
    document.getElementById('rejectConfirmBtn').addEventListener('click', function (e) {
        if (comment.value.trim() === '') {
            e.preventDefault();
            msg.classList.remove('d-none');
            comment.focus();
        }
    });

    // กันกดซ้ำ: disable ปุ่มหลังจาก browser เก็บค่า decision ของปุ่มที่กดไปแล้ว
    // (ถ้า disable ทันทีใน submit event ค่า name=decision ของปุ่มจะหายจาก POST)
    let submitting = false;
    form.addEventListener('submit', function (e) {
        if (submitting) { e.preventDefault(); return; }
        submitting = true;
        const submitter = e.submitter;
        setTimeout(() => {
            form.querySelectorAll('button').forEach(b => { b.disabled = true; });
            if (submitter) {
                submitter.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังดำเนินการ...';
            }
        }, 0);
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
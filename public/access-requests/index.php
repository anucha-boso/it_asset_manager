<?php
/**
 * Access Requests — List
 * public/access-requests/index.php
 * แสดง 2 ส่วน: คำขอของฉัน + รายการที่รอฉันอนุมัติ/ดำเนินการ
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/require_employee_link.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../config/notification.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);
require_module_access('ACCESS_REQUESTS');

$pdo        = db();
$employeeId = require_employee_link();
// ------ เช็คว่ามี email เก็บไว้ไหม (soft check ไม่บล็อกการใช้งาน) ------
$myEmail = getEmployeeEmails([$employeeId]);
$myRole     = $_SESSION['iam_role'] ?? '';
$isItAdmin  = in_array($myRole, ['it_admin', 'it_staff'], true);

// ------------------------------------------------------------------
//  1) คำขอของฉัน
// ------------------------------------------------------------------
$myStmt = $pdo->prepare("
    SELECT r.id, r.request_no, r.status, r.requested_at, r.beneficiary_name,
           r.requestor_employee_id, r.beneficiary_employee_id,
           r.access_level_text, lv.level_name, a.app_name
    FROM access_requests r
    JOIN access_applications a ON a.id = r.application_id
    LEFT JOIN access_application_levels lv ON lv.id = r.access_level_id
    WHERE r.requestor_employee_id = :eid
    ORDER BY r.requested_at DESC
    LIMIT 100
");
$myStmt->execute([':eid' => $employeeId]);
$myRequests = $myStmt->fetchAll();

// ------------------------------------------------------------------
//  2) รอฉันอนุมัติ (Approver1/Approver2)
// ------------------------------------------------------------------
$approvalStmt = $pdo->prepare("
    SELECT s.id AS step_id, r.id AS request_id, r.request_no, r.reason, r.requestor_name,
           r.beneficiary_name, r.beneficiary_employee_id, r.requestor_employee_id,
           a.app_name, s.step_role
    FROM access_request_steps s
    JOIN access_requests r ON r.id = s.request_id
    JOIN access_applications a ON a.id = r.application_id
    WHERE s.action = 'Pending'
      AND s.step_role IN ('APPROVER1','APPROVER2')
      AND s.assignee_employee_id = :eid
      AND r.status NOT IN ('Completed','Rejected','Cancelled')
    ORDER BY r.requested_at ASC
");
$approvalStmt->execute([':eid' => $employeeId]);
$pendingApprovals = $approvalStmt->fetchAll();

// ------------------------------------------------------------------
//  3) รอ IT ดำเนินการ (เฉพาะ it_admin/it_staff)
// ------------------------------------------------------------------
$pendingItAction = [];
if ($isItAdmin) {
    $itStmt = $pdo->query("
    SELECT s.id AS step_id, r.id AS request_id, r.request_no, r.reason, r.requestor_name,
           r.beneficiary_name, r.beneficiary_employee_id, r.requestor_employee_id,
           a.app_name, a.it_action_note
    FROM access_request_steps s
    JOIN access_requests r ON r.id = s.request_id
    JOIN access_applications a ON a.id = r.application_id
    WHERE s.action = 'Pending' AND s.step_role = 'IT_ACTION'
      AND r.status NOT IN ('Completed','Rejected','Cancelled')
    ORDER BY r.requested_at ASC
");
    $pendingItAction = $itStmt->fetchAll();
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title  = 'Access Requests';
$active_menu = 'access_requests';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if (!$myEmail): ?>
<div class="alert alert-warning d-flex justify-content-between align-items-center">
    <span><i class="bi bi-exclamation-triangle"></i> คุณยังไม่ได้ตั้งค่า Email รับการแจ้งเตือน</span>
    <a href="/it-asset-manager/profile/index.php" class="btn btn-sm btn-warning">ตั้งค่าเลย</a>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">

    <a href="/it-asset-manager/access-requests/form.php" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg"></i> สร้างคำขอใหม่
    </a>
</div>

<?php if ($pendingApprovals): ?>
<div class="card mb-4">
    <div class="card-header bg-white"><strong><i class="bi bi-hourglass-split"></i> รอฉันอนุมัติ</strong></div>
    <div class="list-group list-group-flush">
        <?php foreach ($pendingApprovals as $p): ?>
            <a href="/it-asset-manager/access-requests/detail.php?id=<?= e($p['request_id']) ?>"
               class="list-group-item list-group-item-action">
                <div class="d-flex justify-content-between">
                    <div>
                        <span class="fw-semibold"><?= e($p['request_no']) ?></span> — <?= e($p['app_name']) ?>
                        <div class="text-muted small">
                            ผู้ขอ: <?= e($p['requestor_name']) ?>
                            <?php if ($p['beneficiary_employee_id'] === null): ?>
                                · ขอให้: <span class="text-warning"><?= e($p['beneficiary_name']) ?></span>
                                <span class="badge text-bg-secondary" style="font-size:9px;">บุคคลภายนอก</span>
                            <?php elseif ((int)$p['beneficiary_employee_id'] !== (int)$p['requestor_employee_id']): ?>
                                · ขอให้: <span class="text-primary"><?= e($p['beneficiary_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <span class="badge text-bg-warning align-self-start"><?= e($p['step_role']) ?></span>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($isItAdmin && $pendingItAction): ?>
<div class="card mb-4">
    <div class="card-header bg-white"><strong><i class="bi bi-tools"></i> รอ IT ดำเนินการ</strong></div>
    <div class="list-group list-group-flush">
        <?php foreach ($pendingItAction as $p): ?>
            <a href="/it-asset-manager/access-requests/detail.php?id=<?= e($p['request_id']) ?>"
               class="list-group-item list-group-item-action">
                <div class="fw-semibold"><?= e($p['request_no']) ?> — <?= e($p['app_name']) ?></div>
                <div class="text-muted small">
                    ผู้ขอ: <?= e($p['requestor_name']) ?>
                    <?php if ($p['beneficiary_employee_id'] === null): ?>
                        · ขอให้: <span class="text-warning"><?= e($p['beneficiary_name']) ?></span>
                        <span class="badge text-bg-secondary" style="font-size:9px;">บุคคลภายนอก</span>
                    <?php elseif ((int)$p['beneficiary_employee_id'] !== (int)$p['requestor_employee_id']): ?>
                        · ขอให้: <span class="text-primary"><?= e($p['beneficiary_name']) ?></span>
                    <?php endif; ?>
                    <?php if ($p['it_action_note']): ?> · <?= e($p['it_action_note']) ?><?php endif; ?>
                </div>
            </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header bg-white"><strong>คำขอของฉัน</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Request No.</th>
                    <th>ระบบ</th>
                    <th>ขอให้</th>
                    <th>Level</th>
                    <th>วันที่ขอ</th>
                    <th>สถานะ</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$myRequests): ?>
                <tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีคำขอ</td></tr>
            <?php else: foreach ($myRequests as $r):
                $badge = match($r['status']) {
                    'Completed' => 'text-bg-success',
                    'Rejected','Cancelled' => 'text-bg-danger',
                    default => 'text-bg-info',
                };
            ?>
                <tr style="cursor:pointer" onclick="location.href='/it-asset-manager/access-requests/detail.php?id=<?= e($r['id']) ?>'">
                    <td class="fw-semibold"><?= e($r['request_no']) ?></td>
                    <td><?= e($r['app_name']) ?></td>
                    <<td class="small">
                        <?php if ($r['beneficiary_employee_id'] === null): ?>
                            <?= e($r['beneficiary_name']) ?> <span class="badge text-bg-secondary" style="font-size:9px;">ภายนอก</span>
                        <?php elseif ((int)$r['beneficiary_employee_id'] === (int)$r['requestor_employee_id']): ?>
                            <span class="text-muted">ตัวเอง</span>
                        <?php else: ?>
                            <?= e($r['beneficiary_name']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= e($r['level_name'] ?: ($r['access_level_text'] ?: '—')) ?></td>
                    <td class="text-muted small"><?= e($r['requested_at']) ?></td>
                    <td><span class="badge <?= $badge ?>"><?= e($r['status']) ?></span></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
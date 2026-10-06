<?php
/**
 * History — ประวัติ Asset/Software/Access ที่เคยถูก assign/revoke ให้พนักงาน
 * public/profile/history.php
 *
 * 2 โหมด (เหมือน profile/index.php):
 *   1) ไม่มี ?emp=   → ประวัติของตัวเอง (ผ่าน require_employee_link)
 *   2) มี ?emp=<id>  → ประวัติของพนักงานคนอื่น เฉพาะผู้ที่มีสิทธิ์ module EMPLOYEE_DIRECTORY
 *                       (it_admin ผ่านเสมอ / คนอื่นต้องมี grant) — ไม่มีสิทธิ์ได้ 403
 *
 * เฟส 1: Asset Assign/Transfer/Return และ App Assign/Revoke (Software/Hardware/Mobile)
 * เฟส 2: Access Request Submitted/Approved/Rejected และ Permission Grant/Revoke
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';   // can_access_module()
require_once __DIR__ . '/../../includes/require_employee_link.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);

$pdo = db();

// ------ เลือกพนักงานที่จะแสดง — เช็คสิทธิ์ฝั่ง server เสมอ (กัน IDOR) ------
$empParam    = $_GET['emp'] ?? '';
$isViewOther = ($empParam !== '');

if ($isViewOther) {
    if (!can_access_module('EMPLOYEE_DIRECTORY')) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์ดูประวัติของพนักงานคนอื่น');
    }
    $employeeId = filter_var($empParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($employeeId === false) {
        http_response_code(400);
        exit('รหัสพนักงานไม่ถูกต้อง');
    }

    // ยืนยันว่ามีพนักงานคนนี้จริง + ดึงชื่อมาแสดงหัวหน้า
    $empStmt = employee_db()->prepare("
        SELECT title, first_name, last_name, person_code
        FROM employees WHERE id = :id
    ");
    $empStmt->execute([':id' => $employeeId]);
    $employee = $empStmt->fetch();
    if (!$employee) {
        http_response_code(404);
        exit('ไม่พบข้อมูลพนักงานรหัสนี้');
    }
    $fullName = trim($employee['title'] . ' ' . $employee['first_name'] . ' ' . $employee['last_name']);
} else {
    $employeeId = require_employee_link();
    $fullName   = '';
}

$empQs = $isViewOther ? '?emp=' . $employeeId : '';

$stmt = $pdo->prepare("
    SELECT txn_type, ref_table, detail, performed_by_ad, occurred_at
    FROM employee_transaction_log
    WHERE employee_id = :eid
    ORDER BY occurred_at DESC
    LIMIT 200
");
$stmt->execute([':eid' => $employeeId]);
$logs = $stmt->fetchAll();

/* ── Badge สีตาม txn_type ── */
function txnBadgeClass(string $type): string {
    return match ($type) {
        'Asset Assign', 'App Assign'                 => 'text-bg-success',
        'Asset Transfer'                              => 'text-bg-warning',
        'Asset Return', 'App Revoke'                  => 'text-bg-secondary',
        'Permission Grant', 'Access Request Approved' => 'text-bg-success',
        'Permission Revoke', 'Access Request Rejected'=> 'text-bg-danger',
        'Access Request Submitted'                    => 'text-bg-info',
        default                                        => 'text-bg-light border',
    };
}

$headingText = $isViewOther ? 'ประวัติของ ' . $fullName : 'ประวัติของฉัน';
$page_title  = $headingText;
$active_menu = $isViewOther ? 'employee_directory' : '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <div class="d-flex justify-content-between align-items-center">
        <a href="/it-asset-manager/profile/index.php<?= e($empQs) ?>" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left"></i> กลับไปหน้าโปรไฟล์
        </a>
        <?php if ($isViewOther): ?>
            <span class="badge text-bg-light border"><i class="bi bi-eye"></i> โหมดดูอย่างเดียว</span>
        <?php endif; ?>
    </div>
    <h2 class="h5 mb-0 mt-1"><i class="bi bi-clock-history"></i> <?= e($headingText) ?></h2>
    <div class="text-muted small">
        <?php if ($isViewOther): ?>รหัส <?= e($employee['person_code']) ?> · <?php endif; ?>
        Asset, Software และ Access Request ที่เคยถูกมอบหมาย/อนุมัติ/ถอนคืน
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width:160px;">วันที่/เวลา</th>
                    <th style="width:160px;">ประเภท</th>
                    <th>รายละเอียด</th>
                    <th style="width:140px;">ทำโดย</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="4" class="text-center text-muted py-5">
                    <i class="bi bi-inbox" style="font-size:32px;"></i>
                    <div class="mt-2">ยังไม่มีประวัติ</div>
                </td></tr>
            <?php else: foreach ($logs as $l): ?>
                <tr>
                    <td class="text-muted small"><?= e(date('d/m/Y H:i', strtotime($l['occurred_at']))) ?></td>
                    <td><span class="badge <?= txnBadgeClass($l['txn_type']) ?>"><?= e($l['txn_type']) ?></span></td>
                    <td><?= e($l['detail'] ?: '—') ?></td>
                    <td class="text-muted small"><?= e($l['performed_by_ad'] ?: '—') ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
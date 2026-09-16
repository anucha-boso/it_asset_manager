<?php
/**
 * My History — ประวัติ Asset/Software ที่เคยถูก assign/revoke ให้ตัวเอง
 * public/profile/history.php
 *
 * เฟส 1: Asset Assign/Transfer/Return และ App Assign/Revoke (Software/Hardware/Mobile)
 * เฟส 2: เพิ่ม Access Request Submitted/Approved/Rejected และ Permission Grant/Revoke
 *        (ทั้งสองเฟสทำเสร็จแล้ว — คอมเมนต์นี้เก็บไว้อธิบายว่าแต่ละ txn_type มาจากจุดไหน)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/require_employee_link.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);

$pdo        = db();
$employeeId = require_employee_link();

$stmt = $pdo->prepare("
    SELECT txn_type, ref_table, detail, performed_by_ad, occurred_at
    FROM employee_transaction_log
    WHERE employee_id = :eid
    ORDER BY occurred_at DESC
    LIMIT 200
");
$stmt->execute([':eid' => $employeeId]);
$logs = $stmt->fetchAll();

/* ── Badge สีตาม txn_type ──────────────────────────────────────────
   เตรียมไว้ครบทุกค่าใน ENUM ตั้งแต่ตอนนี้ — ทั้งเฟส 1 (Asset/App) และ
   เฟส 2 (Access Request/Permission) ทำเสร็จแล้วทั้งคู่ ──────────────── */
function txnBadgeClass(string $type): string {
    return match ($type) {
        'Asset Assign', 'App Assign'                => 'text-bg-success',
        'Asset Transfer'                             => 'text-bg-warning',
        'Asset Return', 'App Revoke'                 => 'text-bg-secondary',
        'Permission Grant', 'Access Request Approved' => 'text-bg-success',
        'Permission Revoke', 'Access Request Rejected'=> 'text-bg-danger',
        'Access Request Submitted'                   => 'text-bg-info',
        default                                       => 'text-bg-light border',
    };
}

$page_title  = 'ประวัติของฉัน';
$active_menu = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/profile/index.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับไปหน้าโปรไฟล์
    </a>
    <h2 class="h5 mb-0 mt-1"><i class="bi bi-clock-history"></i> ประวัติของฉัน</h2>
    <div class="text-muted small">
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
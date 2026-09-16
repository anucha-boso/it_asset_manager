<?php
/**
 * Loan Detail — ดูรายละเอียด + ประวัติต่ออายุ
 * /var/www/lab/it-asset-manager/public/loans/detail.php?id=N
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff','it_viewer','it_borrower']);
require_module_access('LOANS');



require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Missing id'); }

$loan = $pdo->prepare("
    SELECT l.*,
        DATEDIFF(l.expected_return, CURRENT_DATE) AS days_left,
        h.asset_id AS hw_code, h.brand AS hw_brand, h.model AS hw_model,
        h.serial_number AS hw_serial, h.category, h.department AS hw_dept,
        h.location AS hw_location,
        m.asset_id AS mob_code, m.brand AS mob_brand, m.model AS mob_model,
        m.serial_number AS mob_serial, m.device_type, m.department AS mob_dept,
        n.asset_id AS net_code, n.brand AS net_brand, n.model AS net_model,
        n.device_type AS net_device_type, n.location AS net_location,
        s.site_name AS borrower_site
    FROM asset_loans l
    LEFT JOIN hardware_assets h ON h.id = l.asset_id
    LEFT JOIN mobile_assets   m ON m.id = l.mobile_id
    LEFT JOIN network_assets  n ON n.id = l.network_asset_id
    LEFT JOIN sites           s ON s.id = l.borrower_site_id
    WHERE l.id = :id
");
$loan->execute([':id' => $id]);
$loan = $loan->fetch();
if (!$loan) { http_response_code(404); exit('Loan not found'); }
/* borrower ดูได้เฉพาะ loan ของตัวเองเท่านั้น */
if (is_borrower_only() && $loan['borrower_ad'] !== iam_user()['username']) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ดูรายการนี้');
}

/* Extensions */
$exts = $pdo->prepare("SELECT * FROM loan_extensions WHERE loan_id=:lid ORDER BY created_at");
$exts->execute([':lid' => $id]);
$extensions = $exts->fetchAll();

/* Peripherals */
$peripherals = [];
if ($loan['asset_id']) {
    $ps = $pdo->prepare("SELECT * FROM asset_peripherals WHERE asset_id=:aid ORDER BY type,slot");
    $ps->execute([':aid' => $loan['asset_id']]);
    $peripherals = $ps->fetchAll();
}

$stClass = match($loan['status']) {
    'OnLoan'   => 'text-bg-primary',
    'Overdue'  => 'text-bg-danger',
    'Pending'  => 'text-bg-warning',
    'Approved' => 'text-bg-info',
    'Returned' => 'text-bg-success',
    'Lost'     => 'text-bg-danger',
    'Damaged'  => 'text-bg-secondary',
    default    => 'text-bg-light border',
};

$assetLabel = $loan['hw_code']
    ? "{$loan['hw_code']} · {$loan['hw_brand']} {$loan['hw_model']}"
    : ($loan['mob_code']
        ? "{$loan['mob_code']} · {$loan['mob_brand']} {$loan['mob_model']}"
        : "{$loan['net_code']} · {$loan['net_brand']} {$loan['net_model']}");

$page_title  = $loan['loan_code'];
$active_menu = 'loans';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/loans/index.php"
       class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับรายการ
    </a>
    <div class="d-flex align-items-center gap-2 mt-1">
        <h2 class="h5 mb-0"><?= e($loan['loan_code']) ?></h2>
        <span class="badge <?= $stClass ?>"><?= e($loan['status']) ?></span>
    </div>
</div>

<div class="row g-3">
    <!-- ── ซ้าย: ข้อมูลหลัก ──────────────────────────────────── -->
    <div class="col-lg-8">

        <!-- อุปกรณ์ -->
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>อุปกรณ์</strong></div>
            <div class="card-body">
                <div class="row g-2 small">
                    <div class="col-md-6">
                        <span class="text-muted">Asset:</span>
                        <strong class="ms-1"><?= e($assetLabel) ?></strong>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted">ประเภท:</span>
                        <span class="ms-1"><?= e($loan['category'] ?: $loan['device_type'] ?: $loan['net_device_type'] ?: '—') ?></span>
                    </div>
                    <div class="col-md-3">
                        <span class="text-muted">หน่วยงาน:</span>
                        <span class="ms-1"><?= e($loan['hw_dept'] ?: $loan['mob_dept'] ?: $loan['net_location'] ?: '—') ?></span>
                    </div>
                    <div class="col-md-6">
                        <span class="text-muted">Serial:</span>
                        <span class="ms-1 font-monospace">
                            <?= e($loan['hw_serial'] ?: $loan['mob_serial'] ?: '—') ?>
                        </span>
                    </div>
                    <?php if ($peripherals): ?>
                    <div class="col-12 mt-2">
                        <span class="text-muted">อุปกรณ์เสริม:</span>
                        <?php foreach ($peripherals as $p): ?>
                            <span class="badge text-bg-light border ms-1">
                                <?= e($p['type']) ?> <?= $p['slot']>1?$p['slot']:'' ?>
                                <?= $p['serial_number'] ? '— '.e($p['serial_number']) : '' ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ผู้ยืม -->
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>ผู้ยืม</strong></div>
            <div class="card-body">
                <div class="row g-2 small">
                    <div class="col-md-4"><span class="text-muted">ชื่อ:</span>
                        <strong class="ms-1"><?= e($loan['borrower_name']) ?></strong></div>
                    <div class="col-md-4"><span class="text-muted">AD:</span>
                        <span class="ms-1"><?= e($loan['borrower_ad']) ?></span></div>
                    <div class="col-md-4"><span class="text-muted">เบอร์:</span>
                        <span class="ms-1"><?= e($loan['borrower_phone'] ?: '—') ?></span></div>
                    <div class="col-md-4"><span class="text-muted">หน่วยงาน:</span>
                        <span class="ms-1"><?= e($loan['borrower_dept'] ?: '—') ?></span></div>
                    <div class="col-md-4"><span class="text-muted">Site:</span>
                        <span class="ms-1"><?= e($loan['borrower_site'] ?: '—') ?></span></div>
                </div>
            </div>
        </div>

        <!-- กำหนดการ -->
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>กำหนดการ / สภาพ</strong></div>
            <div class="card-body">
                <div class="row g-2 small">
                    <div class="col-md-3"><span class="text-muted">วันที่ยืม:</span>
                        <span class="ms-1"><?= e($loan['loan_date']) ?></span></div>
                    <div class="col-md-3"><span class="text-muted">กำหนดคืน:</span>
                        <strong class="ms-1"><?= e($loan['expected_return']) ?></strong></div>
                    <div class="col-md-3"><span class="text-muted">คืนจริง:</span>
                        <span class="ms-1"><?= e($loan['actual_return'] ?: '—') ?></span></div>
                    <div class="col-md-3">
                        <?php
                        $days = (int)$loan['days_left'];
                        if ($loan['status'] === 'Returned'): ?>
                            <span class="badge text-bg-success">คืนแล้ว</span>
                        <?php elseif ($days < 0): ?>
                            <span class="badge text-bg-danger">เลย <?= abs($days) ?> วัน</span>
                        <?php elseif ($days <= 3): ?>
                            <span class="badge text-bg-warning">เหลือ <?= $days ?> วัน</span>
                        <?php else: ?>
                            <span class="badge text-bg-light border">เหลือ <?= $days ?> วัน</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-6"><span class="text-muted">วัตถุประสงค์:</span>
                        <span class="ms-1"><?= e($loan['purpose']) ?></span>
                        <span class="badge text-bg-light border ms-1"><?= e($loan['purpose_type']) ?></span>
                    </div>
                    <?php if ($loan['condition_out']): ?>
                    <div class="col-md-6"><span class="text-muted">สภาพตอนยืม:</span>
                        <span class="ms-1"><?= e($loan['condition_out']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($loan['condition_in']): ?>
                    <div class="col-md-6"><span class="text-muted">สภาพตอนคืน:</span>
                        <span class="ms-1"><?= e($loan['condition_in']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($loan['accessories_out']): ?>
                    <div class="col-12"><span class="text-muted">อุปกรณ์ที่ส่ง:</span>
                        <span class="ms-1"><?= e($loan['accessories_out']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($loan['accessories_in']): ?>
                    <div class="col-12"><span class="text-muted">อุปกรณ์ที่รับคืน:</span>
                        <span class="ms-1"><?= e($loan['accessories_in']) ?></span></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($extensions): ?>
        <!-- ประวัติต่ออายุ -->
        <div class="card mb-3">
            <div class="card-header bg-white">
                <strong>ประวัติต่ออายุ</strong>
                <span class="badge text-bg-light border ms-1"><?= count($extensions) ?> ครั้ง</span>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                    <thead class="table-light">
                        <tr><th>#</th><th>จาก</th><th>เป็น</th><th>เหตุผล</th><th>ผู้ขอ</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($extensions as $i => $ext): ?>
                        <tr>
                            <td><?= $i+1 ?></td>
                            <td><?= e($ext['old_return_date']) ?></td>
                            <td><strong><?= e($ext['new_return_date']) ?></strong></td>
                            <td><?= e($ext['reason'] ?: '—') ?></td>
                            <td><?= e($ext['requested_by'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── ขวา: Actions ──────────────────────────────────────── -->
    <div class="col-lg-4">
        <div class="card mb-3">
            <div class="card-header bg-white"><strong>Actions</strong></div>
            <div class="card-body d-flex flex-column gap-2">
                <?php if (can('checkout_loan') && in_array($loan['status'], ['Pending','Approved'], true)): ?>
                    <a href="/it-asset-manager/loans/checkout.php?id=<?= e($loan['id']) ?>"
                    class="btn btn-lg btn-success w-100 mb-2">
                        <i class="bi bi-box-arrow-right"></i> ให้ยืม (Checkout)
                    </a>
                <?php endif; ?>
                <?php if (can('return_loan') && in_array($loan['status'], ['OnLoan','Overdue'])): ?>
                    <a href="/it-asset-manager/loans/return.php?id=<?= $id ?>"
                       class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-left"></i> รับคืน / ต่ออายุ
                    </a>
                <?php endif; ?>
                <a href="/it-asset-manager/loans/print.php?id=<?= $id ?>"
                   target="_blank" class="btn btn-outline-secondary">
                    <i class="bi bi-printer"></i> พิมพ์ใบยืม
                </a>
                <a href="/it-asset-manager/loans/index.php"
                   class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> กลับรายการ
                </a>
            </div>
        </div>

        <!-- Approval info -->
        <?php if ($loan['approved_by']): ?>
        <div class="card">
            <div class="card-header bg-white"><strong>Approval</strong></div>
            <div class="card-body small">
                <div class="mb-1">
                    <span class="text-muted">อนุมัติโดย:</span>
                    <span class="ms-1"><?= e($loan['approved_by']) ?></span>
                </div>
                <div class="mb-1">
                    <span class="text-muted">เมื่อ:</span>
                    <span class="ms-1"><?= e($loan['approved_at'] ?? '—') ?></span>
                </div>
                <div class="mb-1">
                    <span class="text-muted">ปล่อยโดย:</span>
                    <span class="ms-1"><?= e($loan['handed_out_by'] ?? '—') ?></span>
                </div>
                <?php if ($loan['received_by']): ?>
                <div>
                    <span class="text-muted">รับคืนโดย:</span>
                    <span class="ms-1"><?= e($loan['received_by']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="mb-5"></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
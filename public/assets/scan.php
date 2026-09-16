<?php
/**
 * Scan-to-View — แสดงข้อมูลอุปกรณ์จากการสแกน QR ที่ติดบนตัวเครื่อง
 * public/assets/scan.php?type=hw|mob|net&id=<pk>
 *
 * QR ที่ติดบนอุปกรณ์ทุกตัว (Hardware/Mobile/Network) เก็บ URL มาที่หน้านี้
 * ตรงๆ — สแกนด้วยกล้อง native ของมือถือก็เปิดได้เลย ไม่ต้องมีแอปพิเศษ
 * (login gate เหมือนหน้าอื่นในระบบ ถ้ายังไม่ login จะเด้งไปหน้า login ก่อน)
 *
 * ถ้าอุปกรณ์เปิดให้ยืม (is_loanable) และว่างอยู่ตอนนี้ จะมีปุ่ม "ยืมอุปกรณ์นี้"
 * พาไปที่ loans/form.php พร้อม prefill asset ให้ (ยังต้องรอ IT checkout ตามปกติ)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);

$pdo = db();

$type = $_GET['type'] ?? '';
$id   = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;

if (!in_array($type, ['hw', 'mob', 'net'], true) || $id <= 0) {
    http_response_code(400);
    exit('QR ไม่ถูกต้อง — ไม่พบข้อมูลอุปกรณ์');
}

$asset = null;
$activeLoan = null; // loan record ปัจจุบันถ้ากำลังถูกยืมอยู่ (Pending/Approved/OnLoan/Overdue)

if ($type === 'hw') {
    $stmt = $pdo->prepare("
        SELECT h.*, s.site_name
        FROM hardware_assets h
        LEFT JOIN sites s ON s.id = h.site_id
        WHERE h.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $asset = $stmt->fetch();
    if ($asset) {
        $loanStmt = $pdo->prepare("
            SELECT * FROM asset_loans
            WHERE asset_id = :id AND status IN ('Pending','Approved','OnLoan','Overdue')
            ORDER BY id DESC LIMIT 1
        ");
        $loanStmt->execute([':id' => $id]);
        $activeLoan = $loanStmt->fetch() ?: null;
    }
} elseif ($type === 'mob') {
    $stmt = $pdo->prepare("
        SELECT m.*, s.site_name
        FROM mobile_assets m
        LEFT JOIN sites s ON s.id = m.site_id
        WHERE m.id = :id
    ");
    $stmt->execute([':id' => $id]);
    $asset = $stmt->fetch();
    if ($asset) {
        $loanStmt = $pdo->prepare("
            SELECT * FROM asset_loans
            WHERE mobile_id = :id AND status IN ('Pending','Approved','OnLoan','Overdue')
            ORDER BY id DESC LIMIT 1
        ");
        $loanStmt->execute([':id' => $id]);
        $activeLoan = $loanStmt->fetch() ?: null;
    }
} else { // net
    $stmt = $pdo->prepare("SELECT n.* FROM network_assets n WHERE n.id = :id");
    $stmt->execute([':id' => $id]);
    $asset = $stmt->fetch();
    if ($asset) {
        $loanStmt = $pdo->prepare("
            SELECT * FROM asset_loans
            WHERE network_asset_id = :id AND status IN ('Pending','Approved','OnLoan','Overdue')
            ORDER BY id DESC LIMIT 1
        ");
        $loanStmt->execute([':id' => $id]);
        $activeLoan = $loanStmt->fetch() ?: null;
    }
}

if (!$asset) {
    http_response_code(404);
    exit('ไม่พบอุปกรณ์นี้ในระบบ (อาจถูกลบไปแล้ว)');
}

$isLoanable = (int)($asset['is_loanable'] ?? 0) === 1;
$okStatus   = $type === 'net'
    ? $asset['status'] === 'Active'
    : in_array($asset['status'], ['Active', 'In Stock'], true);
$canBorrow  = $isLoanable && $okStatus && !$activeLoan;

$typeLabel  = ['hw' => 'Hardware Asset', 'mob' => 'Mobile / Handheld', 'net' => 'Network Device'][$type];
$deviceType = $asset['category'] ?? $asset['device_type'] ?? '—';

$page_title  = $asset['asset_id']; // ไม่ escape เอง — header.php escape ให้ตอน render <title> อยู่แล้ว
                                    // (และ e() ยังไม่ถูก define ณ จุดนี้ด้วย เพราะ header.php ที่ define มันยังไม่ถูก require)
$active_menu = $type === 'hw' ? 'hardware' : ($type === 'mob' ? 'mobile' : 'network');
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <span class="badge text-bg-light border"><i class="bi bi-qr-code"></i> สแกนแล้ว</span>
</div>

<div class="card mb-3" style="max-width:640px;">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><?= e($typeLabel) ?></strong>
        <span class="badge <?= $asset['status'] === 'Active' ? 'text-bg-success' : 'text-bg-secondary' ?>">
            <?= e($asset['status']) ?>
        </span>
    </div>
    <div class="card-body">
        <div class="h4 font-monospace mb-1"><?= e($asset['asset_id']) ?></div>
        <div class="text-muted mb-3"><?= e($asset['brand'] ?? '') ?> <?= e($asset['model'] ?? '') ?></div>

        <div class="row g-2 small">
            <div class="col-md-6"><span class="text-muted">ประเภท:</span> <span class="ms-1"><?= e($deviceType) ?></span></div>
            <?php if (isset($asset['site_name'])): ?>
                <div class="col-md-6"><span class="text-muted">Site:</span> <span class="ms-1"><?= e($asset['site_name'] ?: '—') ?></span></div>
            <?php endif; ?>
            <?php if (isset($asset['serial_number'])): ?>
                <div class="col-md-6"><span class="text-muted">Serial:</span> <span class="ms-1 font-monospace"><?= e($asset['serial_number'] ?: '—') ?></span></div>
            <?php endif; ?>
            <?php if (isset($asset['location'])): ?>
                <div class="col-md-6"><span class="text-muted">Location:</span> <span class="ms-1"><?= e($asset['location'] ?: '—') ?></span></div>
            <?php endif; ?>
        </div>

        <hr>

        <?php if ($canBorrow): ?>
            <a href="/it-asset-manager/loans/form.php?asset_type=<?= e($type) ?>&id=<?= e($id) ?>"
               class="btn btn-success w-100">
                <i class="bi bi-box-arrow-right"></i> ยืมอุปกรณ์นี้
            </a>
        <?php elseif (!$isLoanable): ?>
            <div class="text-muted small"><i class="bi bi-x-circle me-1"></i> อุปกรณ์นี้ไม่เปิดให้ยืม</div>
        <?php elseif (!$okStatus): ?>
            <div class="text-muted small"><i class="bi bi-x-circle me-1"></i> อุปกรณ์นี้ไม่พร้อมให้ยืม (สถานะ <?= e($asset['status']) ?>)</div>
        <?php elseif ($activeLoan): ?>
            <?php if (in_array($_SESSION['iam_role'] ?? '', ['it_admin','it_staff','it_viewer'], true)): ?>
                <div class="text-muted small">
                    <i class="bi bi-clock-history me-1"></i> กำลังถูกยืมอยู่
                    (<a href="/it-asset-manager/loans/detail.php?id=<?= e($activeLoan['id']) ?>"><?= e($activeLoan['loan_code']) ?></a>
                    — <?= e($activeLoan['status']) ?>)
                </div>
            <?php else: ?>
                <div class="text-muted small"><i class="bi bi-clock-history me-1"></i> อุปกรณ์นี้กำลังถูกยืมอยู่ ไม่ว่างในขณะนี้</div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (in_array($_SESSION['iam_role'] ?? '', ['it_admin','it_staff'], true)): ?>
<div class="small">
    <a href="/it-asset-manager/assets/qr.php?type=<?= e($type) ?>&id=<?= e($id) ?>" class="text-decoration-none">
        <i class="bi bi-qr-code"></i> ดู/พิมพ์ QR ของอุปกรณ์นี้
    </a>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
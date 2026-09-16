<?php
/**
 * Scan to Borrow — สแกน QR จากในแอปเพื่อเติมฟอร์มขอยืมอัตโนมัติ
 * public/loans/scan.php
 *
 * ใช้กล้องเครื่อง (ผ่าน JS library html5-qrcode จาก CDN) อ่าน QR ที่ติดบน
 * อุปกรณ์ (URL เดียวกับที่ scan.php ใช้ดูข้อมูล) แล้วดึง type/id จาก URL
 * ออกมา redirect ไปที่ loans/form.php พร้อม prefill — ยังต้องรอ IT checkout
 * ตามปกติ ไม่ข้ามขั้นตอนอนุมัติ (ตามที่ตกลงกันไว้)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);
require_module_access('LOANS');

$page_title  = 'สแกนยืมอุปกรณ์';
$active_menu = 'loans';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/loans/index.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับรายการ Loan
    </a>
    <h2 class="h5 mb-0 mt-1"><i class="bi bi-qr-code-scan"></i> สแกน QR เพื่อยืมอุปกรณ์</h2>
    <div class="text-muted small">เล็ง QR ที่ติดบนตัวอุปกรณ์ให้อยู่ในกรอบ</div>
</div>

<div class="card mb-3" style="max-width:480px;">
    <div class="card-body">
        <div id="reader" style="width:100%;"></div>
        <div id="scanStatus" class="text-muted small text-center mt-2">กำลังเปิดกล้อง...</div>
        <div id="scanError" class="alert alert-danger small mt-2 d-none"></div>
    </div>
</div>

<div class="small text-muted" style="max-width:480px;">
    <i class="bi bi-info-circle"></i>
    ถ้ากล้องไม่ขึ้น ต้องอนุญาตสิทธิ์กล้องให้เบราว์เซอร์ก่อน หรือเปิดผ่าน HTTPS
    (กล้องใช้งานไม่ได้บน HTTP ธรรมดา)
</div>

<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
const statusEl = document.getElementById('scanStatus');
const errorEl  = document.getElementById('scanError');

function showError(msg) {
    errorEl.textContent = msg;
    errorEl.classList.remove('d-none');
    statusEl.textContent = '';
}

function handleScan(decodedText) {
    // decodedText คาดว่าเป็น URL เต็มของ scan.php เช่น
    // https://.../it-asset-manager/assets/scan.php?type=hw&id=45
    let type = null, id = null;
    try {
        const url = new URL(decodedText);
        type = url.searchParams.get('type');
        id   = url.searchParams.get('id');
    } catch (e) {
        showError('QR นี้ไม่ใช่ QR ของระบบ IT Asset Manager');
        return;
    }

    if (!['hw', 'mob', 'net'].includes(type) || !id) {
        showError('QR นี้ไม่ใช่ QR อุปกรณ์ของระบบ');
        return;
    }

    statusEl.textContent = 'พบอุปกรณ์แล้ว กำลังไปหน้าขอยืม...';
    scanner.stop().finally(() => {
        window.location.href = '/it-asset-manager/loans/form.php?asset_type=' + encodeURIComponent(type)
            + '&id=' + encodeURIComponent(id);
    });
}

const scanner = new Html5Qrcode('reader');
scanner.start(
    { facingMode: 'environment' },
    { fps: 10, qrbox: { width: 240, height: 240 } },
    handleScan,
    () => { /* decode error ต่อเนื่องระหว่างหากรอบ — ไม่ต้องโชว์ error รัว ๆ */ }
).then(() => {
    statusEl.textContent = 'เล็งกล้องไปที่ QR บนอุปกรณ์';
}).catch((err) => {
    showError('เปิดกล้องไม่สำเร็จ: ' + err + ' — ลองอนุญาตสิทธิ์กล้องแล้วรีเฟรชหน้านี้');
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
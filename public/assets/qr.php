<?php
/**
 * QR Label — สร้าง/พิมพ์ QR code ติดบนอุปกรณ์
 * public/assets/qr.php?type=hw|mob|net&id=<pk>
 *
 * เฉพาะ it_admin/it_staff เท่านั้นที่พิมพ์ label ได้ (viewer/borrower ไม่ต้องเห็น)
 * QR encode เป็น URL เต็มไปที่ scan.php — สแกนด้วยกล้อง native มือถือได้ทันที
 * ไม่ใช้ PHP library ฝั่งเซิร์ฟเวอร์เลย (โหลด JS generate QR จาก CDN แทน)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_role(['it_admin', 'it_staff']);

/* หน้านี้ตั้งใจไม่ include includes/header.php (จะได้ไม่มี sidebar/topbar
   ของแอปปนมาในหน้าพิมพ์ label) แต่ e() ถูก define อยู่ใน header.php เท่านั้น
   จึง define เองที่นี่ กัน "Call to undefined function" ตอนเรียกใช้ด้านล่าง */
if (!function_exists('e')) {
    function e($v): string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$pdo = db();

$type = $_GET['type'] ?? '';
$id   = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;

if (!in_array($type, ['hw', 'mob', 'net'], true) || $id <= 0) {
    http_response_code(400);
    exit('พารามิเตอร์ไม่ถูกต้อง');
}

$table = ['hw' => 'hardware_assets', 'mob' => 'mobile_assets', 'net' => 'network_assets'][$type];
$colSql = $type === 'hw'
    ? "asset_id, brand, model, category, NULL AS device_type"
    : "asset_id, brand, model, NULL AS category, device_type";
$stmt = $pdo->prepare("SELECT {$colSql} FROM {$table} WHERE id = :id");
$stmt->execute([':id' => $id]);
$asset = $stmt->fetch();
if (!$asset) { http_response_code(404); exit('ไม่พบอุปกรณ์นี้'); }

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$scanUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/it-asset-manager/assets/scan.php?type=' . $type . '&id=' . $id;

$deviceType = $asset['category'] ?? $asset['device_type'] ?? '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>QR Label — <?= e($asset['asset_id']) ?></title>
    <script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
    <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: "Sarabun", "TH SarabunPSK", Arial, sans-serif;
        background: #f4f6fa;
        padding: 24px;
    }
    .no-print { margin-bottom: 16px; }
    .no-print button {
        padding: 6px 16px; border: none; border-radius: 4px;
        cursor: pointer; font-size: 13px; margin-right: 6px;
    }
    .btn-print { background: #1e3a5f; color: #fff; }
    .btn-close { background: #ddd; }

    /* ป้าย label ขนาด ~60x40mm สำหรับติดตัวเครื่อง */
    .label {
        width: 60mm;
        border: 1px solid #999;
        border-radius: 4px;
        padding: 8px;
        text-align: center;
        background: #fff;
        display: inline-block;
    }
    .label .company { font-size: 9px; color: #555; }
    .label .qr-box { margin: 6px 0; display: flex; justify-content: center; }
    .label .asset-code { font-family: monospace; font-weight: bold; font-size: 13px; margin-top: 4px; }
    .label .asset-desc { font-size: 9px; color: #555; word-break: break-word; }

    @media print {
        body { background: #fff; padding: 0; }
        .no-print { display: none; }
        .label { border: 1px dashed #999; }
        @page { margin: 10mm; }
    }
    </style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">🖨 พิมพ์ Label</button>
    <button class="btn-close" onclick="window.close()">✕ ปิด</button>
</div>

<div class="label">
    <div class="company">Pacific Cold Storage · IT Asset</div>
    <div class="qr-box" id="qrcode"></div>
    <div class="asset-code"><?= e($asset['asset_id']) ?></div>
    <div class="asset-desc"><?= e($asset['brand']) ?> <?= e($asset['model']) ?> · <?= e($deviceType) ?></div>
</div>

<script>
new QRCode(document.getElementById("qrcode"), {
    text: <?= json_encode($scanUrl) ?>,
    width: 110,
    height: 110,
    correctLevel: QRCode.CorrectLevel.M
});
</script>

</body>
</html>
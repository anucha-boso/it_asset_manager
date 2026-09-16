<?php
/**
 * *** DIAGNOSTIC VERSION — ใช้ชั่วคราวเพื่อหาสาเหตุ 500 เท่านั้น ***
 * ลบไฟล์นี้ทิ้งและใช้ qr.php ตัวจริงกลับคืนหลังหาสาเหตุเจอแล้ว
 */
declare(strict_types=1);

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo "=== FATAL ERROR CAUGHT ===\n";
        echo $err['message'] . "\n";
        echo "File: " . $err['file'] . "\n";
        echo "Line: " . $err['line'] . "\n";
    }
});

try {
    require_once __DIR__ . '/../../config/db_connect.php';
    require_once __DIR__ . '/../../config/auth.php';
    require_role(['it_admin', 'it_staff']);

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

    echo "=== SUCCESS — no error, reached the end ===\n";
    echo "asset_id: " . $asset['asset_id'] . "\n";
    echo "scanUrl: " . $scanUrl . "\n";
    echo "deviceType: " . $deviceType . "\n";

} catch (Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== EXCEPTION CAUGHT ===\n";
    echo get_class($e) . ": " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "--- Trace ---\n";
    echo $e->getTraceAsString();
}
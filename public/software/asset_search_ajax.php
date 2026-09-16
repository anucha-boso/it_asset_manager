<?php
/**
 * AJAX endpoint — ค้นหา Hardware Asset สำหรับผูกกับ Software Allocation
 * public/software/asset_search_ajax.php?q=...
 * Read-only, คืน JSON เท่านั้น
 *
 * ต่างจาก employee_search_ajax.php ตรงที่ query จาก it_asset_mgmt เอง
 * ไม่ต้องข้าม DB (hardware_assets อยู่ฐานข้อมูลเดียวกับ software_licenses)
 * กรองเฉพาะ status Active/In Stock เหมือน dropdown เดิมที่เคยใช้
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';

require_role(['it_admin', 'it_staff']);

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$pdo = db();
$stmt = $pdo->prepare("
    SELECT id, asset_id, brand, model, category
    FROM hardware_assets
    WHERE status IN ('Active','In Stock')
      AND (asset_id LIKE :q1 OR brand LIKE :q2 OR model LIKE :q3)
    ORDER BY asset_id
    LIMIT 15
");
$stmt->execute([
    ':q1' => '%' . $q . '%',
    ':q2' => '%' . $q . '%',
    ':q3' => '%' . $q . '%',
]);

$results = array_map(function ($r) {
    return [
        'id'    => (int)$r['id'],
        'label' => $r['asset_id'],
        'sub'   => trim(($r['category'] ?? '') . ' · ' . $r['brand'] . ' ' . $r['model']),
    ];
}, $stmt->fetchAll());

echo json_encode($results, JSON_UNESCAPED_UNICODE);
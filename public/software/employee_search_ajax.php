<?php
/**
 * AJAX endpoint — ค้นหาพนักงานสำหรับ allocate software license
 * public/software/employee_search_ajax.php?q=...
 * Read-only, คืน JSON เท่านั้น
 *
 * Pattern เดียวกับ public/access-requests/employee_search_ajax.php
 * ต่างกันที่: require_role ให้ตรงกับสิทธิ์ของหน้า allocate.php (it_admin, it_staff)
 * และคืน field 'person_code' แยกออกมาตรงๆ (ไม่ต้อง parse จาก 'sub')
 * เพื่อให้ allocate.php เอาไปเติมช่อง assigned_user_ad ฝั่ง client ได้ง่าย
 * (ค่าจริงที่บันทึกลง DB เซิร์ฟเวอร์จะ query ยืนยันซ้ำเองเสมอ ไม่เชื่อค่าจาก client)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';

require_role(['it_admin', 'it_staff']);

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

$stmt = employee_db()->prepare("
    SELECT id, person_code, title, first_name, last_name, department, position
    FROM employees
    WHERE resign_status = 'Active'
      AND (first_name LIKE :q1 OR last_name LIKE :q2 OR person_code LIKE :q3)
    ORDER BY first_name
    LIMIT 15
");
$stmt->execute([
    ':q1' => '%' . $q . '%',
    ':q2' => '%' . $q . '%',
    ':q3' => '%' . $q . '%',
]);

$results = array_map(function ($r) {
    return [
        'id'          => (int)$r['id'],
        'person_code' => (string)$r['person_code'],
        'label'       => trim($r['title'] . ' ' . $r['first_name'] . ' ' . $r['last_name']),
        'sub'         => $r['person_code'] . ' · ' . $r['department'] . ' · ' . $r['position'],
    ];
}, $stmt->fetchAll());

echo json_encode($results, JSON_UNESCAPED_UNICODE);
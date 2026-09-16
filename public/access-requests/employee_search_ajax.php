<?php
/**
 * AJAX endpoint — ค้นหาพนักงานสำหรับเลือกเป็น Beneficiary
 * public/access-requests/employee_search_ajax.php?q=...
 * Read-only, คืน JSON เท่านั้น
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);
require_module_access('ACCESS_REQUESTS');

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
        'id'    => (int)$r['id'],
        'label' => trim($r['title'] . ' ' . $r['first_name'] . ' ' . $r['last_name']),
        'sub'   => $r['person_code'] . ' · ' . $r['department'] . ' · ' . $r['position'],
    ];
}, $stmt->fetchAll());

echo json_encode($results, JSON_UNESCAPED_UNICODE);
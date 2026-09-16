<?php
/**
 * Site — Delete
 * - asset ที่อยู่ในสาขานี้จะถูก SET NULL ที่ site_id (ตั้งไว้ใน schema)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_role(['it_admin']);
require_module_access('SITES');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
csrf_verify();

$id = $_POST['id'] ?? '';
if (!ctype_digit((string)$id) || (int)$id <= 0) {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'รหัสสาขาไม่ถูกต้อง'];
    header('Location: /sites/index.php'); exit;
}

try {
    $stmt = db()->prepare("DELETE FROM sites WHERE id = :id");
    $stmt->execute([':id' => (int)$id]);
    $_SESSION['flash'] = $stmt->rowCount() > 0
        ? ['type'=>'success','message'=>'ลบสาขาเรียบร้อยแล้ว (อุปกรณ์ในสาขาถูกตั้งเป็นไม่ระบุสาขา)']
        : ['type'=>'warning','message'=>'ไม่พบสาขาที่ต้องการลบ'];
} catch (PDOException $e) {
    error_log('[SITE DELETE FAIL] ' . $e->getMessage());
    $_SESSION['flash'] = ['type'=>'danger','message'=>'ลบไม่สำเร็จ'];
}
header('Location: /sites/index.php');
exit;

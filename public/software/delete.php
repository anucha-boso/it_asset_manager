<?php
/**
 * Software License — Delete
 * public/software/delete.php
 * - allocation map จะ CASCADE ลบตามไปด้วย (ตั้งไว้ใน schema)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';

require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin']);
require_module_access('SOFTWARE');

require_once __DIR__ . '/../../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); exit('Method Not Allowed');
}
csrf_verify();

$id = $_POST['id'] ?? '';
if (!ctype_digit((string)$id) || (int)$id <= 0) {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'รหัส license ไม่ถูกต้อง'];
    header('Location: /it-asset-manager/software/index.php'); exit;
}

try {
    $stmt = db()->prepare("DELETE FROM software_licenses WHERE id = :id");
    $stmt->execute([':id' => (int)$id]);
    $_SESSION['flash'] = $stmt->rowCount() > 0
        ? ['type'=>'success','message'=>'ลบ license เรียบร้อยแล้ว']
        : ['type'=>'warning','message'=>'ไม่พบ license ที่ต้องการลบ'];
} catch (PDOException $e) {
    error_log('[SW DELETE FAIL] ' . $e->getMessage());
    $_SESSION['flash'] = ['type'=>'danger','message'=>'ลบไม่สำเร็จ'];
}
header('Location: /it-asset-manager/software/index.php');
exit;

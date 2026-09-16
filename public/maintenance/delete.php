<?php
/** Maintenance Log — Delete */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin']);
require_module_access('MAINTENANCE');



require_once __DIR__ . '/../../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method Not Allowed'); }
csrf_verify();

$id = $_POST['id'] ?? '';
if (!ctype_digit((string)$id) || (int)$id <= 0) {
    $_SESSION['flash'] = ['type'=>'danger','message'=>'รหัสบันทึกไม่ถูกต้อง'];
    header('Location: /maintenance/index.php'); exit;
}

try {
    $stmt = db()->prepare("DELETE FROM maintenance_logs WHERE id = :id");
    $stmt->execute([':id' => (int)$id]);
    $_SESSION['flash'] = $stmt->rowCount() > 0
        ? ['type'=>'success','message'=>'ลบบันทึกเรียบร้อยแล้ว']
        : ['type'=>'warning','message'=>'ไม่พบบันทึกที่ต้องการลบ'];
} catch (PDOException $e) {
    error_log('[MAINT DELETE FAIL] ' . $e->getMessage());
    $_SESSION['flash'] = ['type'=>'danger','message'=>'ลบไม่สำเร็จ'];
}
header('Location: ../maintenance/index.php');
exit;

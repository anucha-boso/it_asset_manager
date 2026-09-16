<?php
/**
 * =============================================================================
 *  Hardware Asset — Delete handler
 *  public/assets/delete.php
 * -----------------------------------------------------------------------------
 *  - รับเฉพาะ POST
 *  - ตรวจ CSRF
 *  - ใช้ prepared statement
 *  - การลบจะ cascade ลบ maintenance_logs ของ asset นี้ (กำหนดไว้ใน schema)
 *  - software_allocation_map.asset_id จะถูก SET NULL (ไม่ลบ license)
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_role(['it_admin']);



require_once __DIR__ . '/../../includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

csrf_verify();

$id = $_POST['id'] ?? '';
if (!ctype_digit((string)$id) || (int)$id <= 0) {
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'รหัสอุปกรณ์ไม่ถูกต้อง'];
    header('Location: ../assets/index.php');
    exit;
}

$pdo = db();

try {
    $stmt = $pdo->prepare("DELETE FROM hardware_assets WHERE id = :id");
    $stmt->execute([':id' => (int)$id]);

    if ($stmt->rowCount() > 0) {
        $_SESSION['flash'] = ['type' => 'success', 'message' => 'ลบอุปกรณ์เรียบร้อยแล้ว'];
    } else {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'ไม่พบอุปกรณ์ที่ต้องการลบ'];
    }
} catch (PDOException $e) {
    error_log('[ASSET DELETE FAIL] ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'ลบไม่สำเร็จ กรุณาลองอีกครั้ง'];
}

header('Location: ../assets/index.php');
exit;

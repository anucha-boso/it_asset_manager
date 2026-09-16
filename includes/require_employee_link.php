<?php
/**
 * Guard: บังคับให้ login user ต้องผูกกับ employee ก่อนเข้าใช้ฟีเจอร์ที่อิง employee_id
 * เช่น Access Request module
 *
 * ใช้งาน: require_once __DIR__ . '/../../includes/require_employee_link.php';
 *         $employeeId = require_employee_link();
 */
declare(strict_types=1);

function require_employee_link(): int
{
    $user = iam_user();   // มาจาก config/auth.php ที่ require ไว้ก่อนหน้าแล้วในทุกหน้า

    $stmt = db()->prepare("SELECT employee_id FROM user_employee_map WHERE auth_user_id = :uid");
    $stmt->execute([':uid' => $user['user_id']]);
    $row = $stmt->fetch();

    if (!$row) {
        $back = urlencode($_SERVER['REQUEST_URI'] ?? '/it-asset-manager/index.php');
        header('Location: /it-asset-manager/employees/link-me.php?back=' . $back);
        exit;
    }

    return (int)$row['employee_id'];
}
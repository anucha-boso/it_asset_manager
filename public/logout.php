<?php
/**
 * IT Asset Manager — Logout
 * /var/www/lab/it-asset-manager/logout.php
 */
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

/* ลบเฉพาะ session ของ IAM (ไม่กระทบ app อื่นที่ใช้ session key ต่างกัน) */
$iam_keys = ['iam_user_id','iam_username','iam_fullname','iam_role','iam_site_id'];
foreach ($iam_keys as $k) unset($_SESSION[$k]);

/* ถ้าไม่มี session อื่นเหลือ → destroy ทิ้งเลย */
if (empty(array_filter($_SESSION))) {
    session_destroy();
}

header('Location: /it-asset-manager/login.php?logout=1');
exit;

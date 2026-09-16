<?php
require_once __DIR__ . '/../config/auth.php';
iam_require_login();

$role = $_SESSION['iam_role'] ?? '';
$allowed = ['it_admin', 'it_staff', 'it_viewer', 'it_borrower'];

header('Content-Type: text/plain; charset=utf-8');
echo "Raw role bytes: ";
var_dump($role);
echo "\nHex dump: " . bin2hex($role) . "\n";
echo "\nExpected 'it_borrower' hex: " . bin2hex('it_borrower') . "\n";
echo "\nin_array check: ";
var_dump(in_array($role, $allowed, true));
echo "\nFull session data:\n";
print_r($_SESSION);

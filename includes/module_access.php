<?php
/**
 * /var/www/lab/it-asset-manager/includes/module_access.php
 * ตรวจสอบสิทธิ์เข้า module รายบุคคล — คนละชั้นกับ require_role() เดิม
 * it_admin bypass เสมอ (เห็นทุกเมนู ไม่ต้อง grant)
 */
declare(strict_types=1);

function can_access_module(string $moduleCode): bool
{
    if (($_SESSION['iam_role'] ?? '') === 'it_admin') return true;

    static $cache = null;
    if ($cache === null) {
        $userId = (int)($_SESSION['iam_user_id'] ?? 0);
        $stmt = db()->prepare("SELECT module_code FROM user_module_access WHERE auth_user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $cache = array_column($stmt->fetchAll(), 'module_code');
    }
    return in_array($moduleCode, $cache, true);
}

function require_module_access(string $moduleCode): void
{
    iam_require_login();
    if (!can_access_module($moduleCode)) {
        _iam_forbidden($_SESSION['iam_role'] ?? '');
    }
}
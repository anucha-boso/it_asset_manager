<?php
/**
 * /var/www/lab/it-asset-manager/includes/site_scope.php
 * จำกัดข้อมูลตาม Site ต่อผู้ใช้ — it_admin เห็นทุก Site, role อื่นเห็นเฉพาะ Site ที่ grant
 * ไม่มี grant เลย = ไม่เห็นอะไร (deny by default), แถว site_id = NULL เห็นเฉพาะ it_admin
 *
 * ต้องมีตาราง it_asset_mgmt.user_site_access(auth_user_id, site_id, granted_by_ad, granted_at)
 * และต้อง require_once หลัง config/db_connect.php + config/auth.php
 */
declare(strict_types=1);

function site_scope_unrestricted(): bool
{
    return ($_SESSION['iam_role'] ?? '') === 'it_admin';
}

/** @return int[] site_id ที่ผู้ใช้ปัจจุบันเข้าถึงได้ (cache ต่อ request) */
function user_site_ids(): array
{
    static $cache = null;
    if ($cache === null) {
        $userId = (int)($_SESSION['iam_user_id'] ?? 0);
        $stmt = db()->prepare("SELECT site_id FROM user_site_access WHERE auth_user_id = :uid");
        $stmt->execute([':uid' => $userId]);
        $cache = array_map('intval', array_column($stmt->fetchAll(), 'site_id'));
    }
    return $cache;
}

/**
 * สร้างเงื่อนไข SQL สำหรับ WHERE เช่น "h.site_id IN (:ss0,:ss1)"
 * $column ต้องเป็นชื่อคอลัมน์ที่โค้ดกำหนดเอง (ไม่ใช่ค่าจากผู้ใช้) และถูกตรวจ regex อีกชั้น
 * $params ส่งแบบ reference เพื่อเติม placeholder เข้า array เดียวกับ query
 * $prefix ใช้กรณีมีหลายเงื่อนไขใน query เดียว (placeholder ต้องไม่ซ้ำ เพราะ ATTR_EMULATE_PREPARES=false)
 */
function site_scope_sql(string $column, array &$params, string $prefix = 'ss'): string
{
    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column)) {
        throw new InvalidArgumentException('Invalid column for site scope');
    }
    if (site_scope_unrestricted()) return '1=1';

    $ids = user_site_ids();
    if (!$ids) return '1=0';

    $ph = [];
    foreach ($ids as $i => $id) {
        $key = ':' . $prefix . $i;
        $params[$key] = $id;
        $ph[] = $key;
    }
    return $column . ' IN (' . implode(',', $ph) . ')';
}

function can_access_site(?int $siteId): bool
{
    if (site_scope_unrestricted()) return true;
    if ($siteId === null) return false;
    return in_array($siteId, user_site_ids(), true);
}

/** ใช้ในหน้า view/edit/delete ที่รับ ?id= — กัน IDOR (ต้องเช็กหลังดึงแถวจาก DB แล้ว) */
function require_site_access(?int $siteId): void
{
    iam_require_login();
    if (!can_access_site($siteId)) {
        _iam_forbidden($_SESSION['iam_role'] ?? '');
    }
}

/**
 * เงื่อนไข SQL สำหรับตารางที่ผูก asset ได้ 3 ทาง (เช่น pm_schedules): h=hardware, m=mobile, n=network
 * query ต้อง LEFT JOIN hardware_assets h / mobile_assets m / network_assets n มาด้วย
 * แต่ละแถวมี asset แค่ 1 ใน 3 → อีก 2 ตารางเป็น NULL (NULL IN (...) เป็นเท็จ) จึงใช้ OR ได้
 * $prefix ต้องไม่ซ้ำกันภายใน query เดียวกัน (placeholder ซ้ำไม่ได้เมื่อ EMULATE_PREPARES=false)
 */
function pm_scope_sql(array &$params, string $prefix = 'pm'): string
{
    return '('
        . site_scope_sql('h.site_id', $params, $prefix . 'h') . ' OR '
        . site_scope_sql('m.site_id', $params, $prefix . 'm') . ' OR '
        . site_scope_sql('n.site_id', $params, $prefix . 'n')
        . ')';
}

/** ใช้กับหน้าที่รับ schedule_id — ไม่พบ schedule = ไม่ทำอะไร (ให้ผู้เรียกตอบ 404/flash เอง) */
function require_pm_schedule_access(PDO $pdo, int $schedId): void
{
    $stmt = $pdo->prepare("
        SELECT COALESCE(h.site_id, m.site_id, n.site_id) AS site_id
        FROM pm_schedules ps
        LEFT JOIN hardware_assets h ON h.id = ps.asset_id
        LEFT JOIN mobile_assets   m ON m.id = ps.mobile_id
        LEFT JOIN network_assets  n ON n.id = ps.network_id
        WHERE ps.id = :id
    ");
    $stmt->execute([':id' => $schedId]);
    $row = $stmt->fetch();
    if (!$row) return;
    require_site_access($row['site_id'] !== null ? (int)$row['site_id'] : null);
}
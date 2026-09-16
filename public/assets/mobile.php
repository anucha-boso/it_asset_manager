<?php
/**
 * Mobile Assets — List view
 * /var/www/lab/it-asset-manager/public/assets/mobile.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);
require_module_access('MOBILE');

$pdo = db();

/* ── Filters ─────────────────────────────────────────────────── */
$search     = trim((string)($_GET['q']           ?? ''));
$deviceType = trim((string)($_GET['device_type'] ?? ''));
$department = trim((string)($_GET['department']  ?? ''));
$status     = trim((string)($_GET['status']      ?? ''));
$siteId     = ctype_digit((string)($_GET['site_id'] ?? '')) ? (int)$_GET['site_id'] : 0;

$activeFilterCount = (int)($search !== '') + (int)($deviceType !== '') + (int)($department !== '') + (int)($status !== '') + (int)($siteId > 0);

$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

/* ── WHERE ───────────────────────────────────────────────────── */
$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(m.asset_id      LIKE :q1
              OR m.brand         LIKE :q2
              OR m.model         LIKE :q3
              OR m.serial_number LIKE :q4
              OR m.imei          LIKE :q5
              OR m.user_name     LIKE :q6
              OR m.phone_number  LIKE :q7)';
    $qLike = '%' . $search . '%';
    $params[':q1'] = $qLike;
    $params[':q2'] = $qLike;
    $params[':q3'] = $qLike;
    $params[':q4'] = $qLike;
    $params[':q5'] = $qLike;
    $params[':q6'] = $qLike;
    $params[':q7'] = $qLike;
}
if ($deviceType !== '') {
    $where[] = 'm.device_type = :dtype';
    $params[':dtype'] = $deviceType;
}
if ($department !== '') {
    $where[] = 'm.department = :dept';
    $params[':dept'] = $department;
}
if ($status !== '') {
    $where[] = 'm.status = :status';
    $params[':status'] = $status;
}
if ($siteId > 0) {
    $where[] = 'm.site_id = :site_id';
    $params[':site_id'] = $siteId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ── KPI (แก้ bug: เดิมใช้ $pdo->query() ตรงๆ ไม่กรอง filter — ตอนนี้ใช้ whereSql/params เหมือนตารางด้านล่าง) ── */
$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(*)                                AS total,
        SUM(device_type='Smartphone')           AS smartphones,
        SUM(device_type IN ('Handheld','Barcode Reader','Barcode Scanner','Rugged PDA')) AS handhelds,
        SUM(device_type='Tablet')               AS tablets,
        SUM(status='Active')                    AS active,
        SUM(status IN ('ชำรุด','In Repair'))    AS damaged,
        SUM(status='สูญหาย')                    AS lost
    FROM mobile_assets m
    {$whereSql}
");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch();

/* ── Count ───────────────────────────────────────────────────── */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM mobile_assets m {$whereSql}");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* ── Main query ──────────────────────────────────────────────── */
$sql = "
    SELECT
        m.id, m.asset_id, m.device_type, m.company, m.department,
        m.brand, m.model, m.serial_number, m.imei,
        m.os_version, m.main_app,
        m.sim_provider, m.phone_number,
        m.user_name, m.location_detail,
        m.status, m.is_loanable,
        m.anydesk_id, m.mac_wifi,
        s.site_name
    FROM mobile_assets m
    LEFT JOIN sites s ON s.id = m.site_id
    {$whereSql}
    ORDER BY m.device_type ASC, m.department ASC, m.asset_id ASC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$mobiles = $stmt->fetchAll();

/* ── Dropdowns ───────────────────────────────────────────────── */
$sites       = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();
$deviceTypes = ['Smartphone','Handheld','Tablet','Barcode Reader','Barcode Scanner','Rugged PDA','Feature Phone','Other'];
$departments = ['IT','HR','MK','WH','CS','EN','AC','PLP','QA','PC','ส่วนกลาง'];
$statuses    = ['Active','In Repair','In Stock','Retired','On Loan','ชำรุด','คืนแล้ว','สูญหาย','รอ Reset'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$page_title  = 'Mobile / Handheld Assets';
$active_menu = 'mobile';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type'] ?? 'info') ?> alert-dismissible fade show">
    <?= e($flash['message'] ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── KPI Cards หลัก (4 ใบ, สีตามความหมาย: น้ำเงิน=รวม, เขียว/teal=ปกติ, เหลือง=ต้องระวัง, แดง=วิกฤต) ── -->
<div class="row g-3 mb-2">
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-blue h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-phone"></i>Total Mobile</div>
                <div class="metric-value"><?= number_format((int)$kpi['total']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-teal h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-check-circle"></i>Active</div>
                <div class="metric-value"><?= number_format((int)$kpi['active']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-amber h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-tools"></i>ชำรุด / ซ่อม</div>
                <div class="metric-value"><?= number_format((int)$kpi['damaged']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-red h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-exclamation-triangle"></i>สูญหาย</div>
                <div class="metric-value"><?= number_format((int)$kpi['lost']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Breakdown ตามประเภท (chip เล็ก ไม่ใช่การ์ดใหญ่ — ข้อมูลยังครบแต่ไม่แย่งสายตา) ── -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="breakdown-chip"><i class="bi bi-phone"></i> Smartphone: <strong><?= number_format((int)$kpi['smartphones']) ?></strong></span>
    <span class="breakdown-chip"><i class="bi bi-upc-scan"></i> Handheld/PDA: <strong><?= number_format((int)$kpi['handhelds']) ?></strong></span>
    <span class="breakdown-chip"><i class="bi bi-tablet"></i> Tablet: <strong><?= number_format((int)$kpi['tablets']) ?></strong></span>
</div>

<!-- ── Filter bar ────────────────────────────────────────────── -->
<form method="get" class="card mb-3">
    <div class="card-body py-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <label class="form-label small text-muted mb-0 fw-semibold">
                <i class="bi bi-funnel"></i> ตัวกรอง
            </label>
            <?php if ($activeFilterCount > 0): ?>
                <span class="filter-active-chip">
                    <i class="bi bi-info-circle"></i> กำลังกรองอยู่ <?= $activeFilterCount ?> เงื่อนไข
                </span>
            <?php endif; ?>
        </div>
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">ค้นหา</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
                           placeholder="Asset ID, Serial, IMEI, Brand, ผู้รับผิดชอบ, เบอร์">
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">ประเภท</label>
                <select name="device_type" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($deviceTypes as $dt): ?>
                        <option value="<?= e($dt) ?>" <?= $deviceType===$dt?'selected':'' ?>><?= e($dt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?= e($st) ?>" <?= $status===$st?'selected':'' ?>><?= e($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Site</label>
                <select name="site_id" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= e($s['id']) ?>" <?= $siteId===(int)$s['id']?'selected':'' ?>>
                            <?= e($s['site_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <a href="/it-asset-manager/assets/mobile.php"
                   class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </div>
</form>

<!-- ── Action bar ────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-muted small">
        พบ <strong><?= number_format($totalRows) ?></strong> รายการ
        <?php if ($totalPages > 1): ?>· หน้า <?= $page ?> / <?= $totalPages ?><?php endif; ?>
    </div>
    <a href="/it-asset-manager/assets/mobile_form.php" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg"></i> Add Mobile
    </a>
</div>

<!-- ── Table ──────────────────────────────────────────────────── -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Asset</th>
                    <th>Brand / Model</th>
                    <th>Serial / IMEI</th>
                    <th>OS / App หลัก</th>
                    <th>SIM / เบอร์</th>
                    <th>ผู้รับผิดชอบ</th>
                    <th>Site</th>
                    <th>Status</th>
                    <th class="text-end" style="width:90px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$mobiles): ?>
                <tr>
                    <td colspan="9" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:32px;"></i>
                        <div class="mt-2">ยังไม่มีข้อมูล Mobile Assets</div>
                    </td>
                </tr>
            <?php else: foreach ($mobiles as $m):
                $stClass = match($m['status']) {
                    'Active'     => 'text-bg-success',
                    'In Repair',
                    'ชำรุด'      => 'text-bg-warning',
                    'On Loan'    => 'text-bg-primary',
                    'In Stock'   => 'text-bg-info',
                    'สูญหาย'     => 'text-bg-danger',
                    'Retired',
                    'คืนแล้ว'    => 'text-bg-secondary',
                    default      => 'text-bg-light border',
                };
                [$dtIcon, $dtColor] = match(true) {
                    str_contains($m['device_type'], 'Smartphone') ||
                    str_contains($m['device_type'], 'Feature')    => ['bi-phone', 'icon-blue'],
                    str_contains($m['device_type'], 'Tablet')     => ['bi-tablet', 'icon-teal'],
                    default                                        => ['bi-upc-scan', 'icon-amber'],
                };
            ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="device-icon <?= $dtColor ?>"><i class="bi <?= $dtIcon ?>"></i></div>
                            <div>
                                <div class="d-flex align-items-center gap-1">
                                    <a href="/it-asset-manager/assets/mobile_form.php?id=<?= e($m['id']) ?>"
                                       class="fw-semibold text-decoration-none font-monospace">
                                        <?= e($m['asset_id']) ?>
                                    </a>
                                    <?php if ($m['is_loanable']): ?>
                                        <span class="badge text-bg-light border text-muted" style="font-size:9px;" title="ยืมได้">
                                            <i class="bi bi-share"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small"><?= e($m['device_type']) ?>
                                    <?php if ($m['company']): ?> · <?= e($m['company']) ?><?php endif; ?>
                                    <?php if ($m['department']): ?> · <?= e($m['department']) ?><?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td class="small"><?= e($m['brand']) ?> <?= e($m['model']) ?></td>
                    <td class="small font-monospace text-muted">
                        <?php if ($m['serial_number']): ?>
                            <div><?= e($m['serial_number']) ?></div>
                        <?php endif; ?>
                        <?php if ($m['imei']): ?>
                            <div class="text-muted" style="font-size:11px;">IMEI: <?= e($m['imei']) ?></div>
                        <?php endif; ?>
                        <?php if (!$m['serial_number'] && !$m['imei']): ?>—<?php endif; ?>
                    </td>
                    <td class="small">
                        <div><?= e($m['os_version'] ?: '—') ?></div>
                        <?php if ($m['main_app']): ?>
                            <div class="text-muted"><?= e($m['main_app']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small">
                        <div><?= e($m['sim_provider'] ?: '—') ?></div>
                        <?php if ($m['phone_number']): ?>
                            <div class="text-muted"><?= e($m['phone_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small"><?= e($m['user_name'] ?: '—') ?></td>
                    <td class="small text-muted">
                        <div><?= e($m['site_name'] ?? '—') ?></div>
                        <?php if ($m['location_detail']): ?>
                            <div style="font-size:11px;"><?= e($m['location_detail']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $stClass ?>"><?= e($m['status']) ?></span></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="/it-asset-manager/assets/qr.php?type=mob&id=<?= e($m['id']) ?>"
                               class="btn btn-outline-secondary" title="QR Code">
                                <i class="bi bi-qr-code"></i>
                            </a>
                            <a href="/it-asset-manager/assets/mobile_form.php?id=<?= e($m['id']) ?>"
                               class="btn btn-outline-secondary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="/it-asset-manager/assets/mobile_delete.php"
                                  method="post" style="display:contents"
                                  data-confirm="ยืนยันการลบ <?= e($m['asset_id']) ?>?">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= e($m['id']) ?>">
                                <button type="submit" class="btn btn-outline-danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ── Pagination ─────────────────────────────────────────────── -->
<?php if ($totalPages > 1):
    $qsBase = $_GET; unset($qsBase['page']);
    $qs     = http_build_query($qsBase);
    $pfx    = $qs === '' ? '?' : ('?' . $qs . '&');
?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-end mb-0">
        <li class="page-item <?= $page<=1?'disabled':'' ?>">
            <a class="page-link" href="<?= $pfx ?>page=<?= max(1,$page-1) ?>">← ก่อนหน้า</a>
        </li>
        <?php
        $s = max(1,$page-3); $e = min($totalPages,$s+6); $s = max(1,$e-6);
        for ($p=$s; $p<=$e; $p++): ?>
            <li class="page-item <?= $p===$page?'active':'' ?>">
                <a class="page-link" href="<?= $pfx ?>page=<?= $p ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page>=$totalPages?'disabled':'' ?>">
            <a class="page-link" href="<?= $pfx ?>page=<?= min($totalPages,$page+1) ?>">ถัดไป →</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
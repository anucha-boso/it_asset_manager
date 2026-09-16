<?php
ini_set("display_errors",1);error_reporting(E_ALL);

/**
 * Hardware Assets — List view (v2)
 * /var/www/lab/it-asset-manager/public/assets/index.php
 */
//declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);
require_module_access('ASSETS');



require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

/* ── Filters ─────────────────────────────────────────────────── */
$search     = trim((string)($_GET['q']          ?? ''));
$status     = trim((string)($_GET['status']     ?? ''));
$category   = trim((string)($_GET['category']   ?? ''));
$department = trim((string)($_GET['department'] ?? ''));
$siteId     = ctype_digit((string)($_GET['site_id'] ?? '')) ? (int)$_GET['site_id'] : 0;

$activeFilterCount = (int)($search !== '') + (int)($status !== '') + (int)($category !== '') + (int)($department !== '') + (int)($siteId > 0);

$perPage = 25;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

/* ── WHERE builder ───────────────────────────────────────────── */
$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(h.asset_id      LIKE :q1
              OR h.serial_number LIKE :q2
              OR h.service_tag   LIKE :q3
              OR h.model         LIKE :q4
              OR h.brand         LIKE :q5
              OR h.assigned_to_ad LIKE :q6
              OR h.user_display  LIKE :q7)';
    $qLike = '%' . $search . '%';
    $params[':q1'] = $qLike;
    $params[':q2'] = $qLike;
    $params[':q3'] = $qLike;
    $params[':q4'] = $qLike;
    $params[':q5'] = $qLike;
    $params[':q6'] = $qLike;
    $params[':q7'] = $qLike;
}
if ($status !== '') {
    $where[] = 'h.status = :status';
    $params[':status'] = $status;
}
if ($category !== '') {
    $where[] = 'h.category = :category';
    $params[':category'] = $category;
}
if ($department !== '') {
    $where[] = 'h.department = :dept';
    $params[':dept'] = $department;
}
if ($siteId > 0) {
    $where[] = 'h.site_id = :site_id';
    $params[':site_id'] = $siteId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ── Summary KPI (ใช้ filter เดียวกับตารางด้านล่าง) ──────────── */
$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(*)                                              AS total,
        SUM(status = 'Active')                               AS active,
        SUM(status = 'In Repair')                            AS in_repair,
        SUM(status = 'On Loan')                               AS on_loan,
        SUM(status = 'In Stock')                             AS in_stock,
        SUM(is_loanable = 1)                                 AS loanable,
        SUM(warranty_expiry IS NOT NULL
            AND warranty_expiry < CURRENT_DATE)              AS warranty_expired

     FROM hardware_assets h
     {$whereSql}
 ");
 $kpiStmt->execute($params);
 $kpi = $kpiStmt->fetch();

/* ── Count for pagination ────────────────────────────────────── */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM hardware_assets h {$whereSql}");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

/* ── Main query ──────────────────────────────────────────────── */
$sql = "
    SELECT
        h.id, h.asset_id, h.category, h.department,
        h.brand, h.model, h.serial_number, h.service_tag,
        h.cpu, h.ram_gb, h.storage,
        h.status, h.is_loanable,
        h.assigned_to_ad, h.user_display,
        h.location, h.ip_address,
        h.warranty_expiry,
        s.site_name,
        DATEDIFF(h.warranty_expiry, CURRENT_DATE) AS warranty_days_left,
        -- PM สถานะล่าสุด
        (SELECT ps.status FROM pm_schedules ps
         WHERE ps.asset_id = h.id
           AND ps.year = YEAR(CURRENT_DATE)
         ORDER BY ps.quarter DESC LIMIT 1) AS pm_status,
        (SELECT ps.quarter FROM pm_schedules ps
         WHERE ps.asset_id = h.id
           AND ps.year = YEAR(CURRENT_DATE)
         ORDER BY ps.quarter DESC LIMIT 1) AS pm_quarter
    FROM hardware_assets h
    LEFT JOIN sites s ON s.id = h.site_id
    {$whereSql}
    ORDER BY h.department ASC, h.asset_id ASC
    LIMIT :limit OFFSET :offset
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$assets = $stmt->fetchAll();

/* ── Dropdown data ───────────────────────────────────────────── */
$sites = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();

$categories = ['PC','Notebook','Surface','Server','NAS',
               'Printer','Scanner','UPS','Handheld','Tablet',
               'PrintServer','Other'];

$departments = ['IT','HR','MK','WH','CS','EN','AC','PLP','QA','PC','ส่วนกลาง'];

$statuses = ['Active','In Repair','In Stock','Retired','On Loan','Reserved'];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$page_title  = 'Hardware Assets';
$active_menu = 'hardware';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type'] ?? 'info') ?> alert-dismissible fade show">
    <?= e($flash['message'] ?? '') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── KPI Cards หลัก (4 ใบ, สีตามความหมาย) ─────────────────── -->
<div class="row g-3 mb-2">
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-blue h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-pc-display"></i>Total Assets</div>
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
                <div class="metric-label"><i class="bi bi-tools"></i>In Repair</div>
                <div class="metric-value"><?= number_format((int)$kpi['in_repair']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-red h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-exclamation-triangle"></i>Warranty หมด</div>
                <div class="metric-value"><?= number_format((int)$kpi['warranty_expired']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Breakdown chip (ข้อมูลเดิมที่เคย query แต่ไม่เคยโชว์ — On Loan/In Stock ตอนนี้โชว์แล้ว) ── -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <span class="breakdown-chip"><i class="bi bi-arrow-left-right"></i> On Loan: <strong><?= number_format((int)$kpi['on_loan']) ?></strong></span>
    <span class="breakdown-chip"><i class="bi bi-box-seam"></i> In Stock: <strong><?= number_format((int)$kpi['in_stock']) ?></strong></span>
    <span class="breakdown-chip"><i class="bi bi-share"></i> Loan Pool (เครื่องส่วนกลาง): <strong><?= number_format((int)$kpi['loanable']) ?></strong></span>
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
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">ค้นหา</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
                           placeholder="Asset ID, Serial, Service Tag, Brand/Model, User">
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Category</label>
                <select name="category" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c) ?>" <?= $category === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">หน่วยงาน</label>
                <select name="department" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= e($d) ?>" <?= $department === $d ? 'selected' : '' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?= e($st) ?>" <?= $status === $st ? 'selected' : '' ?>><?= e($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-1">
                <label class="form-label small text-muted mb-1">Site</label>
                <select name="site_id" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= e($s['id']) ?>" <?= $siteId === (int)$s['id'] ? 'selected' : '' ?>>
                            <?= e($s['site_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <a href="/it-asset-manager/assets/index.php"
                   class="btn btn-sm btn-outline-secondary" title="Reset">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </div>
    </div>
</form>

<!-- ── Action bar ────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-muted small">
        พบ <strong><?= number_format($totalRows) ?></strong> รายการ
        <?php if ($totalPages > 1): ?>
            · หน้า <?= $page ?> / <?= $totalPages ?>
        <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
        <a href="/it-asset-manager/maintenance/pm_schedule.php"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-calendar-check"></i> PM Schedule
        </a>
        <a href="/it-asset-manager/assets/form.php"
           class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg"></i> Add Asset
        </a>
    </div>
</div>

<!-- ── Table ──────────────────────────────────────────────────── -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Asset</th>
                    <th>Brand / Model</th>
                    <th>Spec</th>
                    <th>ผู้ใช้งาน</th>
                    <th>Site / Location</th>
                    <th>Status</th>
                    <th>Warranty</th>
                    <th class="text-end" style="width:90px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$assets): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                        <i class="bi bi-inbox" style="font-size:32px;"></i>
                        <div class="mt-2">ไม่พบข้อมูล</div>
                    </td>
                </tr>
            <?php else: foreach ($assets as $a):
                /* Status badge */
                $stClass = match($a['status']) {
                    'Active'    => 'text-bg-success',
                    'In Repair' => 'text-bg-warning',
                    'In Stock'  => 'text-bg-info',
                    'On Loan'   => 'text-bg-primary',
                    'Retired'   => 'text-bg-secondary',
                    'Reserved'  => 'text-bg-light border',
                    default     => 'text-bg-light border',
                };

                /* Warranty badge */
                $wDays = $a['warranty_days_left'];
                if ($a['warranty_expiry'] === null) {
                    $wBadge = 'text-bg-light border'; $wLabel = '—';
                } elseif ((int)$wDays < 0) {
                    $wBadge = 'text-bg-danger';  $wLabel = 'หมดแล้ว';
                } elseif ((int)$wDays <= 30) {
                    $wBadge = 'text-bg-danger';  $wLabel = (int)$wDays . ' วัน';
                } elseif ((int)$wDays <= 90) {
                    $wBadge = 'text-bg-warning'; $wLabel = (int)$wDays . ' วัน';
                } else {
                    $wBadge = 'text-bg-light border';
                    $wLabel = date('d/m/y', strtotime($a['warranty_expiry']));
                }

                /* PM badge */
                $pmBadge = ''; $pmLabel = '—';
                if ($a['pm_status']) {
                    $pmBadge = match($a['pm_status']) {
                        'Done'       => 'text-bg-success',
                        'InProgress' => 'text-bg-warning',
                        'Planned'    => 'text-bg-light border',
                        'Skipped'    => 'text-bg-secondary',
                        default      => 'text-bg-light border',
                    };
                    $pmLabel = 'Q' . $a['pm_quarter'] . ' ' . $a['pm_status'];
                }

                /* Category icon color (จัดกลุ่มเดียวกับ mobile.php) */
                $catColor = match(true) {
                    in_array($a['category'], ['PC','Notebook','Surface','Server','NAS'], true) => 'icon-blue',
                    in_array($a['category'], ['Printer','Scanner','UPS','PrintServer'], true)  => 'icon-amber',
                    in_array($a['category'], ['Handheld','Tablet'], true)                       => 'icon-teal',
                    default                                                                     => 'icon-gray',
                };
                $catIcon = match(true) {
                    in_array($a['category'], ['PC','Notebook','Surface'], true) => 'bi-laptop',
                    in_array($a['category'], ['Server','NAS'], true)            => 'bi-hdd-network',
                    in_array($a['category'], ['Printer','PrintServer'], true)   => 'bi-printer',
                    $a['category'] === 'UPS'                                    => 'bi-battery-charging',
                    in_array($a['category'], ['Handheld','Tablet'], true)       => 'bi-tablet',
                    default                                                     => 'bi-pc-display',
                };
            ?>
                <tr>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <div class="device-icon <?= $catColor ?>"><i class="bi <?= $catIcon ?>"></i></div>
                            <div>
                                <div class="d-flex align-items-center gap-1">
                                    <a href="/it-asset-manager/assets/form.php?id=<?= e($a['id']) ?>"
                                       class="fw-semibold text-decoration-none font-monospace">
                                        <?= e($a['asset_id']) ?>
                                    </a>
                                    <?php if ($a['is_loanable']): ?>
                                        <span class="badge text-bg-light border text-muted" style="font-size:9px;" title="เครื่องส่วนกลาง">
                                            <i class="bi bi-share"></i>
                                        </span>
                                    <?php endif; ?>
                                </div>
                                <div class="text-muted small">
                                    <?= e($a['category'] ?: '—') ?>
                                    <?php if ($a['department']): ?> · <?= e($a['department']) ?><?php endif; ?>
                                </div>
                                <?php if ($pmBadge): ?>
                                    <span class="badge <?= $pmBadge ?>" style="font-size:9px;">
                                        <?= e($pmLabel) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <div><?= e($a['brand']) ?> <?= e($a['model']) ?></div>
                        <?php if ($a['service_tag']): ?>
                            <div class="text-muted small font-monospace"><?= e($a['service_tag']) ?></div>
                        <?php elseif ($a['serial_number']): ?>
                            <div class="text-muted small font-monospace"><?= e($a['serial_number']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?php
                        $specs = [];
                        if ($a['cpu'])    $specs[] = $a['cpu'];
                        if ($a['ram_gb']) $specs[] = $a['ram_gb'] . ' GB';
                        if ($a['storage'])$specs[] = $a['storage'];
                        echo $specs ? e(implode(' · ', $specs)) : '—';
                        ?>
                    </td>
                    <td>
                        <?php if ($a['user_display']): ?>
                            <div class="small"><?= e($a['user_display']) ?></div>
                        <?php endif; ?>
                        <?php if ($a['assigned_to_ad']): ?>
                            <div class="text-muted small">
                                <i class="bi bi-person"></i> <?= e($a['assigned_to_ad']) ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!$a['user_display'] && !$a['assigned_to_ad']): ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="small"><?= e($a['site_name'] ?? '—') ?></div>
                        <?php if ($a['location']): ?>
                            <div class="text-muted small"><?= e($a['location']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $stClass ?>"><?= e($a['status']) ?></span>
                    </td>
                    <td>
                        <span class="badge <?= $wBadge ?>"><?= $wLabel ?></span>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="/it-asset-manager/assets/qr.php?type=hw&id=<?= e($a['id']) ?>"
                               class="btn btn-outline-secondary" title="QR Code">
                                <i class="bi bi-qr-code"></i>
                            </a>
                            <a href="/it-asset-manager/assets/form.php?id=<?= e($a['id']) ?>"
                               class="btn btn-outline-secondary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="/it-asset-manager/assets/delete.php"
                                  method="post" style="display:contents"
                                  data-confirm="ยืนยันการลบ <?= e($a['asset_id']) ?>?&#10;จะลบประวัติ PM และ peripherals ทั้งหมดด้วย">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= e($a['id']) ?>">
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
    $qsStr  = http_build_query($qsBase);
    $prefix = $qsStr === '' ? '?' : ('?' . $qsStr . '&');
?>
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-end mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $prefix ?>page=<?= max(1, $page - 1) ?>">← ก่อนหน้า</a>
        </li>
        <?php
        $start = max(1, $page - 3);
        $end   = min($totalPages, $start + 6);
        $start = max(1, $end - 6);
        for ($p = $start; $p <= $end; $p++):
        ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= $prefix ?>page=<?= $p ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= $prefix ?>page=<?= min($totalPages, $page + 1) ?>">ถัดไป →</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
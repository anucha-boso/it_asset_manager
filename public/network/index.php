<?php
/**
 * Network Assets — List
 * /var/www/lab/it-asset-manager/public/network/index.php
 */
//declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_role(['it_admin','it_staff','it_viewer']);

$pdo = db();

/* ── Filters ─────────────────────────────────────────────────── */
$search     = trim((string)($_GET['q']           ?? ''));
$deviceType = trim((string)($_GET['device_type'] ?? ''));
$location   = trim((string)($_GET['location']    ?? ''));
$status     = trim((string)($_GET['status']      ?? ''));
$siteId     = ctype_digit((string)($_GET['site_id'] ?? '')) ? (int)$_GET['site_id'] : 0;

$where  = [];
$params = [];

if ($search !== '') {
    $where[]       = '(n.asset_id LIKE :q1 OR n.hostname LIKE :q2
                    OR n.model LIKE :q3 OR n.ip_mgmt LIKE :q4
                    OR n.ip_production LIKE :q5 OR n.serial_number LIKE :q6)';
    $qLike = '%' . $search . '%';
    $params[':q1'] = $qLike;
    $params[':q2'] = $qLike;
    $params[':q3'] = $qLike;
    $params[':q4'] = $qLike;
    $params[':q5'] = $qLike;
    $params[':q6'] = $qLike;
}
if ($deviceType !== '') {
    $where[]              = 'n.device_type = :dtype';
    $params[':dtype']     = $deviceType;
}
if ($location !== '') {
    $where[]              = 'n.location = :loc';
    $params[':loc']       = $location;
}
if ($status !== '') {
    $where[]              = 'n.status = :status';
    $params[':status']    = $status;
}
if ($siteId > 0) {
     $where[]           = 'n.site_id = :site_id';
     $params[':site_id'] = $siteId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* ── KPI (ต้องกรองด้วย filter เดียวกับตารางด้านล่าง — ไม่งั้นตัวเลข KPI
      จะค้างที่ยอดรวมทั้งระบบ ไม่ตรงกับผลลัพธ์ที่กรองอยู่จริง) ───────────── */
$kpiStmt = $pdo->prepare("
    SELECT
        COUNT(*)                          AS total,
        SUM(n.device_type='Switch')       AS switches,
        SUM(n.device_type='Router')       AS routers,
        SUM(n.device_type='Router SIM')   AS router_sim,
        SUM(n.device_type='Access Point') AS aps,
        SUM(n.device_type='Firewall')     AS firewalls,
        SUM(n.status='Active')            AS active,
        SUM(n.status='Inactive')          AS inactive
    FROM network_assets n
    {$whereSql}
");
$kpiStmt->execute($params);
$kpi = $kpiStmt->fetch();

/* ── Locations ───────────────────────────────────────────────── */
$locations = $pdo->query("
    SELECT DISTINCT location FROM network_assets
    WHERE location IS NOT NULL ORDER BY location
")->fetchAll(PDO::FETCH_COLUMN);

$deviceTypes = ['Switch','Router','Firewall','Access Point',
                'Load Balancer','Patch Panel','Media Converter',
                'Router SIM','Other'];
$statuses    = ['Active','Inactive','In Repair','Retired','On Loan'];
$sites       = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();


/* ── Main query ──────────────────────────────────────────────── */
$stmt = $pdo->prepare("
    SELECT n.*, s.site_name
    FROM network_assets n
    LEFT JOIN sites s ON s.id = n.site_id
    {$whereSql}
    ORDER BY n.location ASC, n.device_type ASC, n.asset_id ASC
    LIMIT 300
");
$stmt->execute($params);
$devices = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$page_title  = 'Network Assets';
$active_menu = 'network';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── KPI Cards ─────────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <?php
    $cards = [
        ['Total Network',  $kpi['total'],      'metric-blue'],
        ['Switch',         $kpi['switches'],   'metric-teal'],
        ['Router / SIM',   (int)$kpi['routers']+(int)$kpi['router_sim'], 'metric-amber'],
        ['Access Point',   $kpi['aps'],        'metric-teal'],
        ['Firewall',       $kpi['firewalls'],  'metric-red'],
        ['Active',         $kpi['active'],     'metric-teal'],
    ];
    foreach ($cards as [$label, $val, $color]): ?>
    <div class="col-6 col-md-2">
        <div class="card metric-card <?= $color ?> h-100">
            <div class="card-body">
                <div class="metric-label"><?= e($label) ?></div>
                <div class="metric-value"><?= number_format((int)$val) ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Filter ────────────────────────────────────────────────── -->
<form method="get" class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">ค้นหา</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= e($search) ?>"
                           class="form-control"
                           placeholder="Asset ID, Hostname, IP, Serial">
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">ประเภท</label>
                <select name="device_type" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($deviceTypes as $dt): ?>
                        <option value="<?= e($dt) ?>"
                            <?= $deviceType===$dt?'selected':'' ?>><?= e($dt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Location</label>
                <select name="location" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?= e($loc) ?>"
                            <?= $location===$loc?'selected':'' ?>><?= e($loc) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">   <!-- Site: ใหม่ -->
                <label class="form-label small text-muted mb-1">Site</label>
                <select name="site_id" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= e($s['id']) ?>"
                            <?= $siteId===(int)$s['id']?'selected':'' ?>>
                            <?= e($s['site_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?= e($st) ?>"
                            <?= $status===$st?'selected':'' ?>><?= e($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <a href="/it-asset-manager/network/index.php"
                   class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
                <?php if (can('create')): ?>
                    <a href="/it-asset-manager/network/form.php"
                       class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg"></i> Add
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<div class="text-muted small mb-2">
    พบ <strong><?= count($devices) ?></strong> รายการ
</div>

<!-- ── Table ──────────────────────────────────────────────────── -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Asset ID</th>
                    <th>ประเภท</th>
                    <th>Hostname / Model</th>
                    <th>Site</th>
                    <th>Location</th>
                    <th>IP Mgmt</th>
                    <th>IP Production</th>
                    <th>Firmware</th>
                    <th>Status</th>
                    <th class="text-end" style="width:90px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$devices): ?>
                <tr>
                    <td colspan="10" class="text-center text-muted py-5">
                        <i class="bi bi-hdd-network" style="font-size:32px;"></i>
                        <div class="mt-2">ไม่พบข้อมูล</div>
                    </td>
                </tr>
            <?php else: foreach ($devices as $d):
                $stClass = match($d['status']) {
                    'Active'     => 'text-bg-success',
                    'Inactive'   => 'text-bg-secondary',
                    'In Repair'  => 'text-bg-warning',
                    'On Loan'    => 'text-bg-primary',
                    'Retired'    => 'text-bg-secondary',
                    default      => 'text-bg-light border',
                };
                $dtIcon = match($d['device_type']) {
                    'Switch'               => 'bi-diagram-3',
                    'Router','Router SIM'  => 'bi-router',
                    'Firewall'             => 'bi-shield-check',
                    'Access Point'         => 'bi-wifi',
                    default                => 'bi-hdd-network',
                };
            ?>
                <tr>
                    <td>
                        <a href="/it-asset-manager/network/form.php?id=<?= e($d['id']) ?>"
                           class="fw-semibold text-decoration-none font-monospace">
                            <?= e($d['asset_id']) ?>
                        </a>
                        <?php if ($d['is_loanable']): ?>
                            <span class="badge text-bg-light border text-muted ms-1"
                                  style="font-size:9px;" title="ยืมได้">
                                <i class="bi bi-share"></i>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <i class="bi <?= $dtIcon ?> text-muted me-1"></i>
                        <span class="small"><?= e($d['device_type']) ?></span>
                    </td>
                    <td>
                        <div class="small fw-semibold"><?= e($d['hostname'] ?: '—') ?></div>
                        <div class="text-muted small"><?= e($d['brand']) ?> <?= e($d['model']) ?></div>
                    </td>
                    <td class="small text-muted"><?= e($d['site_name'] ?: '—') ?></td>
                    <td class="small text-muted">
                        <div><?= e($d['location'] ?: '—') ?></div>
                        <?php if ($d['rack_position']): ?>
                            <div style="font-size:11px;"><?= e($d['rack_position']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="small font-monospace"><?= e($d['ip_mgmt'] ?: '—') ?></td>
                    <td class="small font-monospace text-muted"><?= e($d['ip_production'] ?: '—') ?></td>
                    <td class="small">
                        <?php if ($d['firmware_version']): ?>
                            <span class="badge text-bg-light border"><?= e($d['firmware_version']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $stClass ?>"><?= e($d['status']) ?></span></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <?php if (can('edit')): ?>
                                <a href="/it-asset-manager/assets/qr.php?type=net&id=<?= e($d['id']) ?>"
                                   class="btn btn-outline-secondary" title="QR Code">
                                    <i class="bi bi-qr-code"></i>
                                </a>
                                <a href="/it-asset-manager/network/form.php?id=<?= e($d['id']) ?>"
                                   class="btn btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            <?php endif; ?>
                            <?php if (can('delete')): ?>
                                <form action="/it-asset-manager/network/delete.php"
                                      method="post" style="display:contents"
                                      data-confirm="ยืนยันการลบ <?= e($d['asset_id']) ?>?">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?= e($d['id']) ?>">
                                    <button class="btn btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
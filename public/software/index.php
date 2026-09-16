<?php
ini_set("display_errors",1);error_reporting(E_ALL);
/**
 * =============================================================================
 *  Software Licenses — List view
 *  public/software/index.php
 * -----------------------------------------------------------------------------
 *  - แสดง license พร้อม seat utilization จาก view v_software_usage
 *  - filter: ค้นหา + status (ปกติ / ใกล้หมด / หมดอายุ)
 * =============================================================================
 */
//declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_role(['it_admin','it_staff','it_viewer']);



require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

$sites = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();
$search = trim((string)($_GET['q']     ?? ''));
$status = trim((string)($_GET['status']?? '')); // ''|active|expiring|expired
$siteId = isset($_GET['site']) && ctype_digit((string)$_GET['site']) ? (int)$_GET['site'] : 0;

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(v.software_name LIKE :q1 OR v.publisher LIKE :q2 OR v.software_id LIKE :q3)';
    $qLike = '%' . $search . '%';
    $params[':q1'] = $qLike;
    $params[':q2'] = $qLike;
    $params[':q3'] = $qLike;
}
switch ($status) {
    case 'expired':
        $where[] = 'v.expiry_date IS NOT NULL AND v.expiry_date < CURRENT_DATE';
        break;
    case 'expiring':
        $where[] = 'v.expiry_date IS NOT NULL AND v.expiry_date >= CURRENT_DATE AND v.expiry_date <= (CURRENT_DATE + INTERVAL 90 DAY)';
        break;
    case 'active':
        $where[] = '(v.expiry_date IS NULL OR v.expiry_date > (CURRENT_DATE + INTERVAL 90 DAY))';
        break;
}
if ($siteId > 0) {
    $where[] = 'v.site_id = :site_id';
    $params[':site_id'] = $siteId;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT v.*, DATEDIFF(v.expiry_date, CURRENT_DATE) AS days_left
    FROM v_software_usage v
    {$whereSql}
    ORDER BY v.software_name ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$licenses = $stmt->fetchAll();

/* ── KPI summary (คำนวณจาก $licenses ที่ผ่าน filter มาแล้ว — ให้ตัวเลข
      สะท้อนตาม search/status/site ที่เลือกอยู่จริง ไม่ใช่ยอดรวมทั้งระบบ) ── */
$kpiTotal    = count($licenses);
$kpiExpiring = 0;
$kpiExpired  = 0;
$kpiSeatsTotal = 0;
$kpiSeatsUsed  = 0;
foreach ($licenses as $l) {
    $d = $l['days_left'];
    if ($l['expiry_date'] !== null) {
        if ((int)$d < 0) {
            $kpiExpired++;
        } elseif ((int)$d <= 90) {
            $kpiExpiring++;
        }
    }
    $kpiSeatsTotal += (int)$l['total_seats'];
    $kpiSeatsUsed  += (int)$l['seats_used'];
}
$kpiUtilPct = $kpiSeatsTotal > 0 ? round(($kpiSeatsUsed / $kpiSeatsTotal) * 100) : 0;

/* จำนวน filter ที่กำลังใช้งานอยู่ (สำหรับ chip แจ้งเตือน) */
$activeFilterCount = ($search !== '' ? 1 : 0) + ($status !== '' ? 1 : 0) + ($siteId > 0 ? 1 : 0);

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title = 'Software Licenses';
$active_menu = 'software';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type'] ?? 'info') ?> alert-dismissible fade show">
        <?= e($flash['message'] ?? '') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- ====================== Summary metric cards ====================== -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card metric-card metric-blue h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-box-seam"></i> Total Licenses</div>
                <div class="metric-value"><?= number_format($kpiTotal) ?></div>
                <?php if ($activeFilterCount > 0): ?>
                    <span class="filter-active-chip mt-2"><i class="bi bi-funnel-fill"></i> กรองอยู่ <?= $activeFilterCount ?> เงื่อนไข</span>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card metric-card metric-amber h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-hourglass-split"></i> Expiring Soon (≤90d)</div>
                <div class="metric-value"><?= number_format($kpiExpiring) ?></div>
                <div class="metric-sub">ใกล้หมดอายุ ยังไม่เลย</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card metric-card metric-red h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-x-octagon"></i> Expired</div>
                <div class="metric-value"><?= number_format($kpiExpired) ?></div>
                <div class="metric-sub">หมดอายุแล้ว ต้องต่อ/ทบทวน</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card metric-card metric-teal h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-bar-chart-line"></i> Seat Utilization</div>
                <div class="metric-value">
                    <?= number_format($kpiSeatsUsed) ?>
                    <span class="metric-divider">/</span>
                    <span class="metric-muted"><?= number_format($kpiSeatsTotal) ?></span>
                </div>
                <div class="progress mt-2" style="height:6px;">
                    <div class="progress-bar bg-success" style="width: <?= (int)$kpiUtilPct ?>%"></div>
                </div>
                <div class="metric-sub mt-1"><?= $kpiUtilPct ?>% ของทุก license ที่กรองอยู่</div>
            </div>
        </div>
    </div>
</div>

<form method="get" class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">ค้นหา</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
                           placeholder="Software name, Publisher, Vendor, SW ID">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Expiry</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <option value="active"   <?= $status==='active'  ?'selected':'' ?>>ปกติ (เหลือ &gt;90 วัน)</option>
                    <option value="expiring" <?= $status==='expiring'?'selected':'' ?>>ใกล้หมด (≤90 วัน)</option>
                    <option value="expired"  <?= $status==='expired' ?'selected':'' ?>>หมดอายุแล้ว</option>
                </select>
            </div>
             <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Site</label>
                <select name="site" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= e($s['id']) ?>" <?= $siteId===(int)$s['id']?'selected':'' ?>>
                            <?= e($s['site_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-3 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
                <a href="/it-asset-manager/software/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </div>
</form>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-muted small">พบ <strong><?= count($licenses) ?></strong> รายการ</div>
    <a href="/it-asset-manager/software/form.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Add License</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>SW ID</th>
                    <th>Software</th>
                    <th>Publisher</th>
                    <th>Site></th>
                    <th style="width:24%;">Utilization</th>
                    <th class="text-end">Seats</th>
                    <th>Expiry</th>
                    <th class="text-end" style="width:170px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$licenses): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">
                    <i class="bi bi-inbox" style="font-size:32px;"></i><div class="mt-2">ไม่พบข้อมูล</div>
                </td></tr>
            <?php else: foreach ($licenses as $l):
                $total = (int)$l['total_seats'];
                $used  = (int)$l['seats_used'];
                $pct   = $total > 0 ? round(($used / $total) * 100) : 0;
                $bar   = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');

                $days = $l['days_left'];
                if ($l['expiry_date'] === null) {
                    $eBadge = 'text-bg-light border'; $eLabel = 'ไม่มีกำหนด';
                } elseif ((int)$days < 0) {
                    $eBadge = 'text-bg-danger';  $eLabel = 'หมดอายุแล้ว';
                } elseif ((int)$days <= 30) {
                    $eBadge = 'text-bg-danger';  $eLabel = 'เหลือ ' . (int)$days . ' วัน';
                } elseif ((int)$days <= 90) {
                    $eBadge = 'text-bg-warning'; $eLabel = 'เหลือ ' . (int)$days . ' วัน';
                } else {
                    $eBadge = 'text-bg-light border'; $eLabel = e($l['expiry_date']);
                }
            ?>
                <tr>
                    <td class="text-muted small"><?= e($l['software_id']) ?></td>
                    <td>
                        <a href="/it-asset-manager/software/allocate.php?id=<?= e($l['id']) ?>"
                           class="fw-semibold text-decoration-none"><?= e($l['software_name']) ?></a>
                    </td>
                    <td><?= e($l['publisher']) ?></td>
                    <td class="text-muted small"><?= e($l['site_name'] ?: 'ส่วนกลาง') ?></td>
                    <td>
                        <div class="progress" style="height:8px;">
                            <div class="progress-bar <?= $bar ?>" style="width: <?= (int)$pct ?>%"></div>
                        </div>
                        <div class="small text-muted mt-1"><?= $pct ?>%</div>
                    </td>
                    <td class="text-end">
                        <span class="fw-semibold"><?= e($used) ?></span>
                        <span class="text-muted">/ <?= e($total) ?></span>
                    </td>
                    <td><span class="badge <?= $eBadge ?>"><?= e($eLabel) ?></span></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="../software/allocate.php?id=<?= e($l['id']) ?>"
                               class="btn btn-outline-secondary" title="Allocations">
                                <i class="bi bi-people"></i>
                            </a>
                            <a href="../software/renew.php?id=<?= e($l['id']) ?>"
                               class="btn btn-outline-secondary" title="Renewal History">
                                <i class="bi bi-arrow-repeat"></i>
                            </a>
                            <a href="../software/form.php?id=<?= e($l['id']) ?>"
                               class="btn btn-outline-secondary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form action="/it-asset-manager/software/delete.php" method="post" style="display:contents"
                                  data-confirm="ยืนยันการลบ <?= e($l['software_name']) ?>? จะลบ allocation ทั้งหมดของ license นี้ด้วย">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= e($l['id']) ?>">
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

<?php require __DIR__ . '/../../includes/footer.php'; ?>
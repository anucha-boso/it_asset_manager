<?php
/**
 * PM Schedule — Generate & List
 * /var/www/lab/it-asset-manager/public/maintenance/pm_schedule.php
 *
 * หน้านี้ทำ 2 อย่าง:
 *  1. Generate pm_schedules รายปี (Q1-Q4) ต่อ asset อัตโนมัติ
 *  2. แสดงรายการ schedule ทั้งหมด filter by year/quarter/status
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff','it_viewer']);
require_module_access('MAINTENANCE');



require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

$currentYear = (int)date('Y');
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ── POST: Generate PM Plan ─────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $genYear = isset($_POST['gen_year']) && ctype_digit((string)$_POST['gen_year'])
            ? (int)$_POST['gen_year'] : $currentYear;
        $genQtr  = isset($_POST['gen_quarter']) && in_array($_POST['gen_quarter'], ['1','2','3','4'], true)
            ? (int)$_POST['gen_quarter'] : 0;
        $genCats = array_values(array_filter(array_map('trim', (array)($_POST['gen_categories'] ?? []))));
        $quartersToGen = $genQtr ? [$genQtr] : [1, 2, 3, 4];

        /* Map category → template_id */
        $tplMap = [];
        $tplRows = $pdo->query("SELECT id, applies_to FROM pm_templates WHERE is_active = 1")->fetchAll();
        foreach ($tplRows as $t) {
            foreach (explode(',', $t['applies_to']) as $cat) {
                $tplMap[trim($cat)] = (int)$t['id'];
            }
        }

        /* ดึง assets ที่ status Active/In Stock — filter category ถ้าเลือกไว้ (ไม่เลือก = ทุก category) */
        if ($genCats) {
            $ph = implode(',', array_fill(0, count($genCats), '?'));
            $assetStmt = $pdo->prepare("
                SELECT id, asset_id, category FROM hardware_assets
                WHERE status IN ('Active','In Stock') AND category IN ($ph)
            ");
            $assetStmt->execute($genCats);
            $assets = $assetStmt->fetchAll();
        } else {
            $assets = $pdo->query("
                SELECT id, asset_id, category FROM hardware_assets
                WHERE status IN ('Active','In Stock')
            ")->fetchAll();
        }

        $created = 0;
        $skipped = 0;

        /* Quarter → planned date (วันแรกของกลางไตรมาส) */
        $qtrDates = [
            1 => "{$genYear}-02-01",
            2 => "{$genYear}-05-01",
            3 => "{$genYear}-08-01",
            4 => "{$genYear}-11-01",
        ];

        $catFilterFn = function(string $sql, string $col) use ($genCats): string {
            if (!$genCats) return $sql;
            $ph = implode(',', array_fill(0, count($genCats), '?'));
            return $sql . " AND {$col} IN ($ph)";
        };

        /* --- 1) Hardware assets --- */
        $hwStmt = $pdo->prepare($catFilterFn(
            "SELECT id, category FROM hardware_assets WHERE status IN ('Active','In Stock')", 'category'
        ));
        $hwStmt->execute($genCats);

        $insHwStmt = $pdo->prepare("
            INSERT IGNORE INTO pm_schedules (asset_id, template_id, year, quarter, planned_date, status)
            VALUES (:aid, :tid, :year, :qtr, :pdate, 'Planned')
        ");
        foreach ($hwStmt->fetchAll() as $a) {
            $cat = $a['category'] ?? '';
            if (!isset($tplMap[$cat])) { $skipped++; continue; }
            foreach ($quartersToGen as $q) {
                $insHwStmt->execute([':aid'=>$a['id'], ':tid'=>$tplMap[$cat], ':year'=>$genYear, ':qtr'=>$q, ':pdate'=>$qtrDates[$q]]);
                $insHwStmt->rowCount() > 0 ? $created++ : $skipped++;
            }
        }

        /* --- 2) Mobile assets --- */
        $mobStmt = $pdo->prepare($catFilterFn(
            "SELECT id, device_type FROM mobile_assets WHERE status IN ('Active','In Stock')", 'device_type'
        ));
        $mobStmt->execute($genCats);

        $insMobStmt = $pdo->prepare("
            INSERT IGNORE INTO pm_schedules (mobile_id, template_id, year, quarter, planned_date, status)
            VALUES (:mid, :tid, :year, :qtr, :pdate, 'Planned')
        ");
        foreach ($mobStmt->fetchAll() as $a) {
            $cat = $a['device_type'] ?? '';
            if (!isset($tplMap[$cat])) { $skipped++; continue; }
            foreach ($quartersToGen as $q) {
                $insMobStmt->execute([':mid'=>$a['id'], ':tid'=>$tplMap[$cat], ':year'=>$genYear, ':qtr'=>$q, ':pdate'=>$qtrDates[$q]]);
                $insMobStmt->rowCount() > 0 ? $created++ : $skipped++;
            }
        }

        /* --- 3) Network assets (enum ไม่มี 'In Stock' จึงเช็คแค่ Active) --- */
        $netStmt = $pdo->prepare($catFilterFn(
            "SELECT id, device_type FROM network_assets WHERE status = 'Active'", 'device_type'
        ));
        $netStmt->execute($genCats);

        $insNetStmt = $pdo->prepare("
            INSERT IGNORE INTO pm_schedules (network_id, template_id, year, quarter, planned_date, status)
            VALUES (:nid, :tid, :year, :qtr, :pdate, 'Planned')
        ");
        foreach ($netStmt->fetchAll() as $a) {
            $cat = $a['device_type'] ?? '';
            if (!isset($tplMap[$cat])) { $skipped++; continue; }
            foreach ($quartersToGen as $q) {
                $insNetStmt->execute([':nid'=>$a['id'], ':tid'=>$tplMap[$cat], ':year'=>$genYear, ':qtr'=>$q, ':pdate'=>$qtrDates[$q]]);
                $insNetStmt->rowCount() > 0 ? $created++ : $skipped++;
            }
        }

       $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => "Generate PM Plan {$genYear} เรียบร้อย — สร้าง {$created} รายการ, ข้าม {$skipped} รายการ (มีอยู่แล้ว/ไม่มี template)",
        ];
        header('Location: /it-asset-manager/maintenance/pm_schedule.php?year=' . $genYear);
        exit;
    }

    if ($action === 'reset_plan') {
        if (!can('delete')) {
            $_SESSION['flash'] = ['type'=>'danger','message'=>'ไม่มีสิทธิ์รีเซ็ตแผน PM'];
            header('Location: /it-asset-manager/maintenance/pm_schedule.php');
            exit;
        }

        $resetYear = isset($_POST['reset_year']) && ctype_digit((string)$_POST['reset_year'])
            ? (int)$_POST['reset_year'] : 0;
        $resetQtr  = isset($_POST['reset_quarter']) && in_array($_POST['reset_quarter'], ['1','2','3','4'], true)
            ? (int)$_POST['reset_quarter'] : 0;
        $resetCats = array_values(array_filter(array_map('trim', (array)($_POST['reset_categories'] ?? []))));

        if ($resetYear <= 0) {
            $_SESSION['flash'] = ['type'=>'danger','message'=>'กรุณาเลือกปีที่ต้องการรีเซ็ต'];
            header('Location: /it-asset-manager/maintenance/pm_schedule.php');
            exit;
        }

        $where  = ["ps.year = :year", "ps.status = 'Planned'"];
        $params = [':year' => $resetYear];
        $joinSql = 'LEFT JOIN hardware_assets h ON h.id = ps.asset_id
                    LEFT JOIN mobile_assets   m ON m.id = ps.mobile_id
                    LEFT JOIN network_assets  n ON n.id = ps.network_id';

        if ($resetQtr) { $where[] = 'ps.quarter = :qtr'; $params[':qtr'] = $resetQtr; }

        if ($resetCats) {
            $ph = [];
            foreach ($resetCats as $i => $c) {
                $key = ":cat{$i}";
                $ph[] = $key;
                $params[$key] = $c;
            }
            $where[] = 'COALESCE(h.category, m.device_type, n.device_type) IN (' . implode(',', $ph) . ')';
        }

        $whereSql = implode(' AND ', $where);

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM pm_schedules ps {$joinSql} WHERE {$whereSql}");
        $countStmt->execute($params);
        $toDelete = (int)$countStmt->fetchColumn();

        if ($toDelete === 0) {
            $_SESSION['flash'] = ['type'=>'warning','message'=>'ไม่พบ schedule สถานะ Planned ที่ตรงเงื่อนไข ไม่มีอะไรให้รีเซ็ต'];
        } else {
            $delStmt = $pdo->prepare("DELETE ps FROM pm_schedules ps {$joinSql} WHERE {$whereSql}");
            $delStmt->execute($params);
            $_SESSION['flash'] = ['type'=>'success',
                'message'=>"รีเซ็ตแผน PM เรียบร้อย — ลบ {$toDelete} รายการ (เฉพาะสถานะ Planned เท่านั้น สถานะ InProgress/Done ไม่ถูกแตะต้อง)"];
        }
        header('Location: /it-asset-manager/maintenance/pm_schedule.php?year=' . $resetYear);
        exit;
    }

    if ($action === 'reschedule') {
        $schedId = isset($_POST['schedule_id']) && ctype_digit((string)$_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;
        $newDate = trim((string)($_POST['new_planned_date'] ?? ''));
        $reason  = trim((string)($_POST['reason'] ?? '')) ?: null;

        if ($schedId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $newDate)) {
            $_SESSION['flash'] = ['type'=>'danger','message'=>'ข้อมูลเลื่อนวันไม่ถูกต้อง'];
            header('Location: /it-asset-manager/maintenance/pm_schedule.php');
            exit;
        }

        $curStmt = $pdo->prepare("SELECT planned_date FROM pm_schedules WHERE id = :id");
        $curStmt->execute([':id' => $schedId]);
        $oldDate = $curStmt->fetchColumn();

        if ($oldDate === false) {
            $_SESSION['flash'] = ['type'=>'danger','message'=>'ไม่พบ schedule นี้'];
            header('Location: /it-asset-manager/maintenance/pm_schedule.php');
            exit;
        }

        if ($oldDate === $newDate) {
            $_SESSION['flash'] = ['type'=>'warning','message'=>'วันที่ใหม่เหมือนวันเดิม ไม่มีการเปลี่ยนแปลง'];
            header('Location: /it-asset-manager/maintenance/pm_schedule.php');
            exit;
        }

        $currentUser = iam_user();
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE pm_schedules SET planned_date = :new_date WHERE id = :id")
                ->execute([':new_date' => $newDate, ':id' => $schedId]);
            $pdo->prepare("
                INSERT INTO pm_reschedule_log (schedule_id, old_planned_date, new_planned_date, reason, changed_by)
                VALUES (:sid, :old_date, :new_date, :reason, :changed_by)
            ")->execute([
                ':sid' => $schedId, ':old_date' => $oldDate, ':new_date' => $newDate,
                ':reason' => $reason, ':changed_by' => $currentUser['username'],
            ]);
            $pdo->commit();
            $_SESSION['flash'] = ['type'=>'success','message'=>"เลื่อนวันจาก {$oldDate} เป็น {$newDate} เรียบร้อย"];
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[PM RESCHEDULE FAIL] ' . $e->getMessage());
            $_SESSION['flash'] = ['type'=>'danger','message'=>'เลื่อนวันไม่สำเร็จ: ' . $e->getMessage()];
        }
        header('Location: /it-asset-manager/maintenance/pm_schedule.php');
        exit;
    }
}

/* ── Filters ─────────────────────────────────────────────────── */
$filterYear = isset($_GET['year']) && ctype_digit((string)$_GET['year'])
    ? (int)$_GET['year'] : $currentYear;
$filterQtr  = isset($_GET['quarter']) && in_array($_GET['quarter'], ['1','2','3','4'], true)
    ? (int)$_GET['quarter'] : 0;
$filterStatus = in_array($_GET['status'] ?? '', ['Planned','InProgress','Done','Skipped'], true)
    ? $_GET['status'] : '';

/* ── Summary cards ───────────────────────────────────────────── */
$summary = $pdo->prepare("
    SELECT status, COUNT(*) AS cnt
    FROM pm_schedules
    WHERE year = :year " . ($filterQtr ? "AND quarter = :qtr" : "") . "
    GROUP BY status
");
$bindSum = [':year' => $filterYear];
if ($filterQtr) $bindSum[':qtr'] = $filterQtr;
$summary->execute($bindSum);
$summaryMap = ['Planned'=>0,'InProgress'=>0,'Done'=>0,'Skipped'=>0];
foreach ($summary->fetchAll() as $r) $summaryMap[$r['status']] = (int)$r['cnt'];
$totalScheduled = array_sum($summaryMap);
$donePct = $totalScheduled > 0
    ? round(($summaryMap['Done'] / $totalScheduled) * 100) : 0;

/* ── Schedule list ───────────────────────────────────────────── */
$where  = ['ps.year = :year'];
$params = [':year' => $filterYear];
if ($filterQtr) { $where[] = 'ps.quarter = :qtr'; $params[':qtr'] = $filterQtr; }
if ($filterStatus) { $where[] = 'ps.status = :status'; $params[':status'] = $filterStatus; }

$schedules = $pdo->prepare("
    SELECT
        ps.id, ps.year, ps.quarter, ps.planned_date, ps.status,
        COALESCE(h.asset_id, m.asset_id, n.asset_id)     AS asset_code,
        COALESCE(h.brand, m.brand, n.brand)               AS brand,
        COALESCE(h.model, m.model, n.model)               AS model,
        COALESCE(h.category, m.device_type, n.device_type) AS category,
        COALESCE(h.department, m.department)              AS department,
        s.site_name,
        pt.name AS template_name,
        (SELECT COUNT(*) FROM maintenance_logs ml WHERE ml.schedule_id = ps.id) AS log_count,
        (SELECT COUNT(*) FROM pm_reschedule_log rl WHERE rl.schedule_id = ps.id) AS reschedule_count
    FROM pm_schedules ps
    LEFT  JOIN hardware_assets h ON h.id = ps.asset_id
    LEFT  JOIN mobile_assets   m ON m.id = ps.mobile_id
    LEFT  JOIN network_assets  n ON n.id = ps.network_id
    LEFT  JOIN sites s           ON s.id = COALESCE(h.site_id, m.site_id, n.site_id)
    INNER JOIN pm_templates pt   ON pt.id = ps.template_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY ps.quarter ASC, asset_code ASC
    LIMIT 500
");
$schedules->execute($params);
$scheduleList = $schedules->fetchAll();

/* ── Available years ─────────────────────────────────────────── */
$years = $pdo->query("
    SELECT DISTINCT year FROM pm_schedules ORDER BY year DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($currentYear, $years)) $years[] = $currentYear;
sort($years);

/* ── Categories สำหรับ generate/reset filter (รวม 3 แหล่ง) ─────── */
$allCategories = $pdo->query("
    SELECT category AS cat FROM hardware_assets WHERE category IS NOT NULL AND category <> ''
    UNION
    SELECT device_type FROM mobile_assets WHERE device_type IS NOT NULL AND device_type <> ''
    UNION
    SELECT device_type FROM network_assets WHERE device_type IS NOT NULL AND device_type <> ''
    ORDER BY cat
")->fetchAll(PDO::FETCH_COLUMN);

$page_title  = 'PM Schedule';
$active_menu = 'maintenance';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ── Action bar (แทนที่การ์ด Generate/Reset เดิม) ─────────────── -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="h5 mb-0">PM Schedule</h2>
    <div class="d-flex gap-2">
        <a href="pm_templates.php" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-list-check"></i> จัดการ Templates
        </a>
        <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#resetPlanModal">
            <i class="bi bi-arrow-counterclockwise"></i> รีเซ็ตแผน
        </button>
        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#generatePlanModal">
            <i class="bi bi-lightning-charge"></i> Generate Plan
        </button>
    </div>
</div>

<!-- ── Summary cards ─────────────────────────────────────────── -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-blue h-100">
            <div class="card-body">
                <div class="metric-label">Planned</div>
                <div class="metric-value"><?= number_format($summaryMap['Planned']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-amber h-100">
            <div class="card-body">
                <div class="metric-label">In Progress</div>
                <div class="metric-value"><?= number_format($summaryMap['InProgress']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-teal h-100">
            <div class="card-body">
                <div class="metric-label">Done</div>
                <div class="metric-value"><?= number_format($summaryMap['Done']) ?></div>
                <div class="metric-sub">
                    <div class="progress mt-1" style="height:4px;">
                        <div class="progress-bar bg-success" style="width:<?= $donePct ?>%"></div>
                    </div>
                    <?= $donePct ?>% complete
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-red h-100">
            <div class="card-body">
                <div class="metric-label">Skipped</div>
                <div class="metric-value"><?= number_format($summaryMap['Skipped']) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ── Filters ───────────────────────────────────────────────── -->
<form method="get" class="card mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-auto">
                <label class="form-label small text-muted mb-1">ปี</label>
                <select name="year" class="form-select form-select-sm">
                    <?php foreach ($years as $y): ?>
                        <option value="<?= $y ?>" <?= $y === $filterYear ? 'selected' : '' ?>><?= $y ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small text-muted mb-1">ไตรมาส</label>
                <select name="quarter" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach ([1=>'Q1 ม.ค.–มี.ค.',2=>'Q2 เม.ย.–มิ.ย.',3=>'Q3 ก.ค.–ก.ย.',4=>'Q4 ต.ค.–ธ.ค.'] as $q => $ql): ?>
                        <option value="<?= $q ?>" <?= $filterQtr === $q ? 'selected' : '' ?>><?= $ql ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto">
                <label class="form-label small text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <?php foreach (['Planned','InProgress','Done','Skipped'] as $st): ?>
                        <option value="<?= $st ?>" <?= $filterStatus === $st ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-auto d-flex gap-2">
                <button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i> Filter</button>
                <a href="pm_schedule.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
            <div class="col text-end">
                <span class="text-muted small">พบ <strong><?= count($scheduleList) ?></strong> รายการ</span>
            </div>
        </div>
    </div>
</form>

<!-- ── Schedule table ────────────────────────────────────────── -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Q</th>
                    <th>Asset</th>
                    <th>หน่วยงาน</th>
                    <th>Site</th>
                    <th>Template</th>
                    <th>Planned Date</th>
                    <th>Status</th>
                    <th class="text-end" style="width:100px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$scheduleList): ?>
                <tr>
                    <td colspan="8" class="text-center text-muted py-5">
                        <i class="bi bi-calendar-x" style="font-size:32px;"></i>
                        <div class="mt-2">ยังไม่มี PM Schedule — กด Generate เพื่อสร้าง</div>
                    </td>
                </tr>
            <?php else: foreach ($scheduleList as $r):
                $stBadge = match($r['status']) {
                    'Done'       => 'text-bg-success',
                    'InProgress' => 'text-bg-warning',
                    'Skipped'    => 'text-bg-secondary',
                    default      => 'text-bg-light border',
                };
                $qLabel = 'Q' . $r['quarter'];
                $qColor = match((int)$r['quarter']) {
                    1 => '#3b82f6', 2 => '#10b981',
                    3 => '#f59e0b', 4 => '#ef4444',
                };
            ?>
                <tr>
                    <td>
                        <span class="badge" style="background:<?= $qColor ?>">
                            <?= $qLabel ?>
                        </span>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= e($r['asset_code']) ?></div>
                        <div class="text-muted small"><?= e($r['brand']) ?> <?= e($r['model']) ?></div>
                    </td>
                    <td class="text-muted small"><?= e($r['department'] ?? '—') ?></td>
                    <td class="text-muted small"><?= e($r['site_name'] ?? '—') ?></td>
                    <td class="small"><?= e($r['template_name']) ?></td>
                    <td class="small">
                        <?php $isOverdue = $r['planned_date'] && $r['planned_date'] < date('Y-m-d') && $r['status'] === 'Planned'; ?>
                        <span class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>"><?= e($r['planned_date'] ?? '—') ?></span>
                        <?php if ($isOverdue): ?>
                            <span class="badge text-bg-danger ms-1" style="font-size:10px;">เลยกำหนด</span>
                        <?php endif; ?>
                        <?php if ($r['reschedule_count'] > 0): ?>
                            <span class="badge text-bg-light border text-muted ms-1" style="font-size:10px;"
                                  title="เคยเลื่อนวันมาแล้ว <?= $r['reschedule_count'] ?> ครั้ง">
                                <i class="bi bi-arrow-repeat"></i> <?= $r['reschedule_count'] ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= $stBadge ?>"><?= e($r['status']) ?></span>
                        <?php if ($r['log_count'] > 0): ?>
                            <span class="badge text-bg-info ms-1">
                                <i class="bi bi-file-text"></i> <?= $r['log_count'] ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <?php if ($r['status'] !== 'Done'): ?>
                                <a href="pm_execute.php?schedule_id=<?= $r['id'] ?>" class="btn btn-primary">
                                    <i class="bi bi-clipboard-check"></i> ทำ PM
                                </a>
                            <?php else: ?>
                                <a href="pm_execute.php?schedule_id=<?= $r['id'] ?>&view=1" class="btn btn-outline-secondary">
                                    <i class="bi bi-eye"></i> ดูผล
                                </a>
                            <?php endif; ?>
                            <?php if ($r['status'] !== 'Done'): ?>
                                <button type="button" class="btn btn-outline-warning" title="เลื่อนวัน"
                                        onclick="openReschedule(<?= $r['id'] ?>, '<?= e($r['planned_date']) ?>', '<?= e($r['asset_code']) ?>')">
                                    <i class="bi bi-calendar-week"></i>
                                </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<!-- ── Reschedule Modal ─────────────────────────────────────────── -->
<div class="modal fade" id="rescheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="reschedule">
            <input type="hidden" name="schedule_id" id="rs_schedule_id">
            <div class="modal-header">
                <h5 class="modal-title">เลื่อนวัน PM — <span id="rs_asset_label"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small text-muted">วันเดิม</label>
                    <input type="text" id="rs_old_date" class="form-control" disabled>
                </div>
                <div class="mb-3">
                    <label class="form-label">วันใหม่ <span class="text-danger">*</span></label>
                    <input type="date" name="new_planned_date" id="rs_new_date" class="form-control" required>
                </div>
                <div class="mb-0">
                    <label class="form-label">เหตุผล</label>
                    <input type="text" name="reason" maxlength="255" class="form-control"
                           placeholder="เช่น ช่างติดงานไซต์อื่น, เครื่องไม่ว่าง">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึกการเลื่อนวัน</button>
            </div>
        </form>
    </div>
</div>

<script>
function openReschedule(id, oldDate, assetLabel) {
    document.getElementById('rs_schedule_id').value = id;
    document.getElementById('rs_old_date').value = oldDate;
    document.getElementById('rs_new_date').value = oldDate;
    document.getElementById('rs_asset_label').textContent = assetLabel;
    new bootstrap.Modal(document.getElementById('rescheduleModal')).show();
}
</script>

<!-- ── Generate Plan Modal ───────────────────────────────────────── -->
<div class="modal fade" id="generatePlanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content"
              onsubmit="return confirm('Generate PM Schedule สำหรับปี/category ที่เลือก?\n(ถ้ามีอยู่แล้วจะข้ามอัตโนมัติ)')">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="generate">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar-plus text-primary"></i> Generate PM Plan รายปี</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">ปีที่ต้องการ Generate</label>
                        <select name="gen_year" class="form-select">
                            <?php foreach (range($currentYear - 1, $currentYear + 1) as $y): ?>
                                <option value="<?= $y ?>" <?= $y === $currentYear ? 'selected' : '' ?>><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">ไตรมาส (ไม่เลือก = Q1-Q4 ทั้งหมด)</label>
                        <select name="gen_quarter" class="form-select">
                            <option value="">— ทั้งหมด Q1-Q4 —</option>
                            <option value="1">Q1 (ม.ค.-มี.ค.)</option>
                            <option value="2">Q2 (เม.ย.-มิ.ย.)</option>
                            <option value="3">Q3 (ก.ค.-ก.ย.)</option>
                            <option value="4">Q4 (ต.ค.-ธ.ค.)</option>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small text-muted mb-1">Category (ไม่เลือก = ทุก category)</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($allCategories as $cat): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="gen_categories[]"
                                           value="<?= e($cat) ?>" id="gc_<?= e(md5($cat)) ?>">
                                    <label class="form-check-label" for="gc_<?= e(md5($cat)) ?>"><?= e($cat) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">
                            ระบบจะสร้าง schedule ให้ asset ที่ status Active/In Stock ตาม category ที่เลือก (ไม่เลือก = ทุก category)
                            โดย map category → PM template อัตโนมัติ (ถ้ามีอยู่แล้วจะข้าม)
                        </small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-lightning-charge"></i> Generate</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Reset Plan Modal ──────────────────────────────────────────── -->
<div class="modal fade" id="resetPlanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content"
              onsubmit="return confirm('ยืนยันรีเซ็ตแผน PM?\nจะลบเฉพาะ schedule สถานะ Planned เท่านั้น ที่ทำไปแล้ว (InProgress/Done) จะไม่ถูกลบ\nการลบนี้ย้อนกลับไม่ได้')">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="reset_plan">
            <div class="modal-header">
                <h5 class="modal-title text-danger"><i class="bi bi-arrow-counterclockwise"></i> รีเซ็ตแผน PM</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">ปี</label>
                        <select name="reset_year" class="form-select">
                            <?php foreach ($years as $y): ?>
                                <option value="<?= $y ?>"><?= $y ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label small text-muted mb-1">ไตรมาส</label>
                        <select name="reset_quarter" class="form-select">
                            <option value="">— ทั้งหมด —</option>
                            <?php foreach ([1,2,3,4] as $q): ?>
                                <option value="<?= $q ?>">Q<?= $q ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label small text-muted mb-1">Category (ไม่เลือก = ทุก category)</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($allCategories as $cat): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="reset_categories[]"
                                           value="<?= e($cat) ?>" id="rc_<?= e(md5($cat)) ?>">
                                    <label class="form-check-label" for="rc_<?= e(md5($cat)) ?>"><?= e($cat) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12">
                        <small class="text-danger">
                            ⚠ ลบเฉพาะ schedule ที่สถานะ "Planned" เท่านั้น สถานะ "InProgress"/"Done" จะไม่ถูกลบ
                        </small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash3"></i> รีเซ็ต (เฉพาะ Planned)</button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

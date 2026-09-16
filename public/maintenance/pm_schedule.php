<?php
/**
 * PM Schedule — Generate (Rolling Interval) & List
 * /var/www/lab/it-asset-manager/public/maintenance/pm_schedule.php
 *
 * เปลี่ยนจากโมเดลเดิม (Fixed Calendar Quarter) เป็น Rolling Interval:
 *  - รอบแรกของแต่ละ asset+template (ยังไม่เคยมี schedule เลย): กระจายวันตาม site + จำนวนเครื่อง/วัน (seed)
 *  - รอบถัดไป (เคยทำ PM แล้ว): คำนวณจาก "วันที่ทำ PM ล่าสุดจริง" (maintenance_logs.pm_date) + interval_days ของ template
 *  - year/quarter ยังเก็บไว้สำหรับ filter/รายงาน แต่คำนวณจาก planned_date ไม่ใช่ตัวขับเคลื่อนการ generate อีกต่อไป
 *
 * หน้านี้ทำ:
 *  1. Generate/Refresh PM Plan (rolling interval)
 *  2. Reset แผน (ลบเฉพาะสถานะ Planned)
 *  3. Reschedule (เลื่อนวัน + audit log)
 *  4. Skip (ข้ามรอบ พร้อมเหตุผล + audit) — ใหม่
 *  5. แสดงรายการ schedule ทั้งหมด filter by year/quarter/status
 *
 * ต้องรัน SQL migration ก่อน: เพิ่ม pm_templates.interval_days,
 * pm_schedules.skip_reason/skipped_by/skipped_at, และเปลี่ยน unique key เป็นแบบ (fk, template_id, planned_date)
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

/* ── POST actions ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    /* ============================================================
     *  Generate / Refresh PM Plan — Rolling Interval model
     * ============================================================ */
    if ($action === 'generate') {
        $genCats = array_values(array_filter(array_map('trim', (array)($_POST['gen_categories'] ?? []))));
        $genSites = array_values(array_map('intval', array_filter(
            array_map('trim', (array)($_POST['gen_sites'] ?? [])),
            fn($v) => $v !== '' && ctype_digit($v)
        )));

        $itemsPerDay = isset($_POST['items_per_day']) && ctype_digit((string)$_POST['items_per_day']) && (int)$_POST['items_per_day'] > 0
            ? (int)$_POST['items_per_day'] : 8;

        /* จำกัดจำนวนที่จะสร้าง/ต่อรอบในครั้งนี้ — ไม่ระบุ = ไม่จำกัด (เหมือนเดิม) */
        $maxItems = isset($_POST['max_items']) && ctype_digit((string)$_POST['max_items']) && (int)$_POST['max_items'] > 0
            ? (int)$_POST['max_items'] : null;
        $remaining = $maxItems; // null = unlimited, ลดค่าทุกครั้งที่ insert สำเร็จจริง
        $quotaHit  = false;

        $seedStartInput = trim((string)($_POST['seed_start_date'] ?? ''));
        $seedStart = preg_match('/^\d{4}-\d{2}-\d{2}$/', $seedStartInput)
            ? $seedStartInput
            : (new DateTime('+7 days'))->format('Y-m-d');

        /* Map category → template (id + interval_days) */
        $tplMap = [];
        $tplRows = $pdo->query("SELECT id, applies_to, interval_days FROM pm_templates WHERE is_active = 1")->fetchAll();
        foreach ($tplRows as $t) {
            foreach (explode(',', $t['applies_to']) as $cat) {
                $tplMap[trim($cat)] = ['id' => (int)$t['id'], 'interval' => (int)$t['interval_days']];
            }
        }

        $catFilterFn = function (string $sql, string $col) use ($genCats): string {
            if (!$genCats) return $sql;
            $ph = implode(',', array_fill(0, count($genCats), '?'));
            return $sql . " AND {$col} IN ($ph)";
        };
        $siteFilterFn = function (string $sql, string $siteCol) use ($genSites): string {
            if (!$genSites) return $sql;
            $ph = implode(',', array_fill(0, count($genSites), '?'));
            return $sql . " AND {$siteCol} IN ($ph)";
        };

        $created = 0; $refreshed = 0; $skipped = 0;

        $nextBusinessDay = function (DateTime $d): DateTime {
            do { $d->modify('+1 day'); } while (in_array((int)$d->format('N'), [6, 7], true));
            return $d;
        };

        /**
         * ประมวลผล asset กลุ่มหนึ่ง (hardware/mobile/network) — สร้าง/ต่อ schedule ทีละตัว
         * $rows: ['pk'=>id, 'site_id'=>x, 'category'=>cat][]
         * $fkCol: 'asset_id' | 'mobile_id' | 'network_id'
         */
        $processGroup = function (array $rows, string $fkCol) use (
            $pdo, $tplMap, &$created, &$refreshed, &$skipped, &$remaining, &$quotaHit,
            $itemsPerDay, $seedStart, $nextBusinessDay
        ) {
            /* เรียงตาม site ก่อน เพื่อกระจายวัน seed ให้ site เดียวกันอยู่ติดกัน */
            usort($rows, fn($a, $b) => ($a['site_id'] <=> $b['site_id']));

            $seedCursor = new DateTime($seedStart);
            $seedCountToday = 0;

            foreach ($rows as $r) {
                /* ครบโควตาที่ตั้งไว้แล้ว → หยุดทันที (เหลือไว้ให้กด Generate รอบถัดไป) */
                if ($remaining !== null && $remaining <= 0) { $quotaHit = true; break; }

                $cat = $r['category'] ?? '';
                if (!isset($tplMap[$cat])) { $skipped++; continue; }
                $tid      = $tplMap[$cat]['id'];
                $interval = max(1, $tplMap[$cat]['interval']);

                /* ข้ามถ้ามี schedule ค้างอยู่แล้ว (Planned/InProgress) ของคู่ asset+template นี้ */
                $pendStmt = $pdo->prepare("
                    SELECT id FROM pm_schedules
                    WHERE {$fkCol} = :pk AND template_id = :tid AND status IN ('Planned','InProgress')
                    LIMIT 1
                ");
                $pendStmt->execute([':pk' => $r['pk'], ':tid' => $tid]);
                if ($pendStmt->fetchColumn()) { $skipped++; continue; }

                /* หาวันที่ PM ล่าสุดที่ "เสร็จแล้วจริง" ของคู่ asset+template นี้ */
                $lastStmt = $pdo->prepare("
                    SELECT MAX(ml.pm_date) FROM maintenance_logs ml
                    INNER JOIN pm_schedules ps ON ps.id = ml.schedule_id
                    WHERE ps.{$fkCol} = :pk AND ps.template_id = :tid
                ");
                $lastStmt->execute([':pk' => $r['pk'], ':tid' => $tid]);
                $lastPm = $lastStmt->fetchColumn();

                $isSeed = false;
                if ($lastPm) {
                    /* Rolling: วันล่าสุดที่ทำจริง + interval_days ของ template */
                    $plannedDate = (new DateTime($lastPm))->modify("+{$interval} days")->format('Y-m-d');
                } else {
                    /* รอบแรก (seed): กระจายตาม site + จำนวนเครื่อง/วัน เพื่อไม่ให้วันชนกันทั้งหมด */
                    $isSeed = true;
                    if ($seedCountToday >= $itemsPerDay) {
                        $seedCursor = $nextBusinessDay($seedCursor);
                        $seedCountToday = 0;
                    }
                    $plannedDate = $seedCursor->format('Y-m-d');
                    $seedCountToday++;
                }

                $pd      = new DateTime($plannedDate);
                $year    = (int)$pd->format('Y');
                $quarter = (int)ceil(((int)$pd->format('n')) / 3);

                $ins = $pdo->prepare("
                    INSERT IGNORE INTO pm_schedules ({$fkCol}, template_id, year, quarter, planned_date, status)
                    VALUES (:pk, :tid, :year, :qtr, :pdate, 'Planned')
                ");
                $ins->execute([
                    ':pk' => $r['pk'], ':tid' => $tid, ':year' => $year, ':qtr' => $quarter, ':pdate' => $plannedDate,
                ]);

                if ($ins->rowCount() > 0) {
                    if ($isSeed) $created++; else $refreshed++;
                    if ($remaining !== null) $remaining--;
                } else {
                    $skipped++; // ซ้ำวันเดิมพอดี (unique key กันไว้)
                }
            }
        };

        /* --- 1) Hardware assets --- */
        $hwSql = $catFilterFn("SELECT id, category, site_id FROM hardware_assets WHERE status IN ('Active','In Stock')", 'category');
        $hwSql = $siteFilterFn($hwSql, 'site_id');
        $hwStmt = $pdo->prepare($hwSql);
        $hwStmt->execute(array_merge($genCats, $genSites));
        $processGroup(array_map(
            fn($a) => ['pk' => (int)$a['id'], 'site_id' => (int)$a['site_id'], 'category' => $a['category']],
            $hwStmt->fetchAll()
        ), 'asset_id');

        /* --- 2) Mobile assets (ข้ามถ้าครบโควตาจาก hardware ไปแล้ว) --- */
        if ($remaining === null || $remaining > 0) {
            $mobSql = $catFilterFn("SELECT id, device_type AS category, site_id FROM mobile_assets WHERE status IN ('Active','In Stock')", 'device_type');
            $mobSql = $siteFilterFn($mobSql, 'site_id');
            $mobStmt = $pdo->prepare($mobSql);
            $mobStmt->execute(array_merge($genCats, $genSites));
            $processGroup(array_map(
                fn($a) => ['pk' => (int)$a['id'], 'site_id' => (int)$a['site_id'], 'category' => $a['category']],
                $mobStmt->fetchAll()
            ), 'mobile_id');
        }

        /* --- 3) Network assets (enum ไม่มี 'In Stock' จึงเช็คแค่ Active; ข้ามถ้าครบโควตาแล้ว) --- */
        if ($remaining === null || $remaining > 0) {
            $netSql = $catFilterFn("SELECT id, device_type AS category, site_id FROM network_assets WHERE status = 'Active'", 'device_type');
            $netSql = $siteFilterFn($netSql, 'site_id');
            $netStmt = $pdo->prepare($netSql);
            $netStmt->execute(array_merge($genCats, $genSites));
            $processGroup(array_map(
                fn($a) => ['pk' => (int)$a['id'], 'site_id' => (int)$a['site_id'], 'category' => $a['category']],
                $netStmt->fetchAll()
            ), 'network_id');
        }

        $quotaNote = ($maxItems !== null && $quotaHit)
            ? " — ครบโควตา {$maxItems} รายการตามที่กำหนดแล้ว ยังมีอุปกรณ์ที่รอสร้าง/ต่อรอบเหลืออยู่ กด Generate ซ้ำเพื่อทำรอบถัดไป"
            : '';

        $_SESSION['flash'] = [
            'type'    => 'success',
            'message' => "Generate/Refresh PM Plan เรียบร้อย — รอบแรก (seed) {$created} รายการ, "
                       . "ต่อรอบอัตโนมัติจากวันที่ทำจริง {$refreshed} รายการ, "
                       . "ข้าม {$skipped} รายการ (มี schedule ค้างอยู่แล้ว/ไม่มี template){$quotaNote}",
        ];
        header('Location: /it-asset-manager/maintenance/pm_schedule.php');
        exit;
    }

    /* ============================================================
     *  Reset Plan — ลบเฉพาะสถานะ Planned (เหมือนเดิม)
     * ============================================================ */
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

    /* ============================================================
     *  Reschedule — เลื่อนวัน (เหมือนเดิม)
     * ============================================================ */
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

    /* ============================================================
     *  Skip — ข้ามรอบ PM นี้ พร้อมเหตุผล (ใหม่)
     * ============================================================ */
    if ($action === 'skip') {
        $schedId = isset($_POST['schedule_id']) && ctype_digit((string)$_POST['schedule_id']) ? (int)$_POST['schedule_id'] : 0;
        $reason  = trim((string)($_POST['skip_reason'] ?? ''));

        if ($schedId <= 0 || $reason === '') {
            $_SESSION['flash'] = ['type'=>'danger','message'=>'กรุณาระบุเหตุผลการข้าม PM'];
            header('Location: /it-asset-manager/maintenance/pm_schedule.php');
            exit;
        }

        $chk = $pdo->prepare("SELECT status FROM pm_schedules WHERE id = :id");
        $chk->execute([':id' => $schedId]);
        $curStatus = $chk->fetchColumn();

        if ($curStatus === false) {
            $_SESSION['flash'] = ['type'=>'danger','message'=>'ไม่พบ schedule นี้'];
        } elseif ($curStatus === 'Done') {
            $_SESSION['flash'] = ['type'=>'danger','message'=>'Schedule นี้ทำ PM เสร็จแล้ว ไม่สามารถ Skip ได้'];
        } else {
            $currentUser = iam_user();
            $pdo->prepare("
                UPDATE pm_schedules
                SET status = 'Skipped', skip_reason = :reason, skipped_by = :by, skipped_at = NOW()
                WHERE id = :id
            ")->execute([':reason' => $reason, ':by' => $currentUser['username'], ':id' => $schedId]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'ข้ามรอบ PM นี้เรียบร้อย — ระบบจะสร้างรอบใหม่ให้เมื่อกด Generate ครั้งถัดไป'];
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

/* ── Schedule list (แบ่งหน้า สูงสุด 10 รายการ/หน้า) ────────────── */
$where  = ['ps.year = :year'];
$params = [':year' => $filterYear];
if ($filterQtr) { $where[] = 'ps.quarter = :qtr'; $params[':qtr'] = $filterQtr; }
if ($filterStatus) { $where[] = 'ps.status = :status'; $params[':status'] = $filterStatus; }
$whereListSql = implode(' AND ', $where);

$perPage = 10;
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pm_schedules ps WHERE {$whereListSql}");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));

$page = isset($_GET['page']) && ctype_digit((string)$_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$schedules = $pdo->prepare("
    SELECT
        ps.id, ps.year, ps.quarter, ps.planned_date, ps.status,
        ps.skip_reason, ps.skipped_by, ps.skipped_at,
        COALESCE(h.asset_id, m.asset_id, n.asset_id)     AS asset_code,
        COALESCE(h.brand, m.brand, n.brand)               AS brand,
        COALESCE(h.model, m.model, n.model)               AS model,
        COALESCE(h.category, m.device_type, n.device_type) AS category,
        COALESCE(h.department, m.department)              AS department,
        s.site_name,
        pt.name AS template_name, pt.interval_days,
        (SELECT COUNT(*) FROM maintenance_logs ml WHERE ml.schedule_id = ps.id) AS log_count,
        (SELECT COUNT(*) FROM pm_reschedule_log rl WHERE rl.schedule_id = ps.id) AS reschedule_count
    FROM pm_schedules ps
    LEFT  JOIN hardware_assets h ON h.id = ps.asset_id
    LEFT  JOIN mobile_assets   m ON m.id = ps.mobile_id
    LEFT  JOIN network_assets  n ON n.id = ps.network_id
    LEFT  JOIN sites s           ON s.id = COALESCE(h.site_id, m.site_id, n.site_id)
    INNER JOIN pm_templates pt   ON pt.id = ps.template_id
    WHERE {$whereListSql}
    ORDER BY ps.quarter ASC, asset_code ASC
    LIMIT {$perPage} OFFSET {$offset}
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

/* ── Sites สำหรับ generate filter (ใหม่) ────────────────────────── */
$allSites = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();

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

<!-- ── Action bar ────────────────────────────────────────────── -->
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
            <i class="bi bi-lightning-charge"></i> Generate / Refresh Plan
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
                <span class="text-muted small">
                    พบ <strong><?= number_format($totalRows) ?></strong> รายการ
                    <?php if ($totalRows > 0): ?>
                        (หน้า <?= $page ?>/<?= $totalPages ?>)
                    <?php endif; ?>
                </span>
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
                    <th class="text-end" style="width:140px;">Action</th>
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
                    <td class="small">
                        <?= e($r['template_name']) ?>
                        <span class="text-muted" style="font-size:10px;">(ทุก <?= (int)$r['interval_days'] ?> วัน)</span>
                    </td>
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
                        <?php if ($r['status'] === 'Skipped' && $r['skip_reason']): ?>
                            <div class="text-muted small mt-1" style="font-size:11px;"
                                 title="โดย <?= e($r['skipped_by'] ?? '') ?> เมื่อ <?= e($r['skipped_at'] ?? '') ?>">
                                <i class="bi bi-info-circle"></i> <?= e($r['skip_reason']) ?>
                            </div>
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
                            <?php if (!in_array($r['status'], ['Done','Skipped'], true)): ?>
                                <button type="button" class="btn btn-outline-warning" title="เลื่อนวัน"
                                        onclick="openReschedule(<?= $r['id'] ?>, '<?= e($r['planned_date']) ?>', '<?= e($r['asset_code']) ?>')">
                                    <i class="bi bi-calendar-week"></i>
                                </button>
                                <button type="button" class="btn btn-outline-secondary" title="Skip"
                                        onclick="openSkip(<?= $r['id'] ?>, '<?= e($r['asset_code']) ?>')">
                                    <i class="bi bi-x-octagon"></i>
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

<!-- ── Pagination ────────────────────────────────────────────── -->
<?php if ($totalPages > 1):
    $baseParams = ['year' => $filterYear];
    if ($filterQtr)    $baseParams['quarter'] = $filterQtr;
    if ($filterStatus) $baseParams['status']  = $filterStatus;
    $pageUrl = fn(int $p) => 'pm_schedule.php?' . http_build_query($baseParams + ['page' => $p]);
?>
<nav class="d-flex justify-content-center mt-3">
    <ul class="pagination pagination-sm mb-0">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e($pageUrl(max(1, $page - 1))) ?>">&laquo; ก่อนหน้า</a>
        </li>
        <?php
        $startP = max(1, $page - 2);
        $endP   = min($totalPages, $page + 2);
        if ($startP > 1): ?>
            <li class="page-item"><a class="page-link" href="<?= e($pageUrl(1)) ?>">1</a></li>
            <?php if ($startP > 2): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
        <?php endif; ?>
        <?php for ($p = $startP; $p <= $endP; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                <a class="page-link" href="<?= e($pageUrl($p)) ?>"><?= $p ?></a>
            </li>
        <?php endfor; ?>
        <?php if ($endP < $totalPages): ?>
            <?php if ($endP < $totalPages - 1): ?><li class="page-item disabled"><span class="page-link">…</span></li><?php endif; ?>
            <li class="page-item"><a class="page-link" href="<?= e($pageUrl($totalPages)) ?>"><?= $totalPages ?></a></li>
        <?php endif; ?>
        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
            <a class="page-link" href="<?= e($pageUrl(min($totalPages, $page + 1))) ?>">ถัดไป &raquo;</a>
        </li>
    </ul>
</nav>
<?php endif; ?>

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

<!-- ── Skip Modal (ใหม่) ─────────────────────────────────────────── -->
<div class="modal fade" id="skipModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="skip">
            <input type="hidden" name="schedule_id" id="sk_schedule_id">
            <div class="modal-header">
                <h5 class="modal-title text-secondary"><i class="bi bi-x-octagon"></i> ข้ามรอบ PM — <span id="sk_asset_label"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-0">
                    <label class="form-label">เหตุผล <span class="text-danger">*</span></label>
                    <textarea name="skip_reason" rows="3" class="form-control" required
                              placeholder="เช่น เครื่องถูกปลดระวางแล้ว, รอเปลี่ยนเครื่องใหม่, ผู้ใช้ลาออก"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-outline-danger">ยืนยันข้าม PM รอบนี้</button>
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
function openSkip(id, assetLabel) {
    document.getElementById('sk_schedule_id').value = id;
    document.getElementById('sk_asset_label').textContent = assetLabel;
    new bootstrap.Modal(document.getElementById('skipModal')).show();
}
</script>

<!-- ── Generate / Refresh Plan Modal ───────────────────────────────── -->
<div class="modal fade" id="generatePlanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content"
              onsubmit="return confirm('Generate/Refresh PM Plan สำหรับเงื่อนไขที่เลือก?\n(อุปกรณ์ที่ยังไม่มี schedule ค้างอยู่ และไม่เคยทำ PM มาก่อน จะถูก seed วันใหม่ / อุปกรณ์ที่เคยทำ PM แล้วจะคำนวณรอบถัดไปจากวันที่ทำจริง + interval ของ Template)')">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="generate">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-calendar-plus text-primary"></i> Generate / Refresh PM Plan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-light border small mb-3">
                    <i class="bi bi-info-circle text-primary"></i>
                    อุปกรณ์ที่ <strong>เคยทำ PM มาแล้ว</strong> จะคำนวณรอบถัดไปอัตโนมัติจาก
                    <strong>วันที่ PM ล่าสุด + จำนวนวันของ Template</strong> (ดู/แก้ค่านี้ได้ที่หน้า
                    <a href="pm_templates.php" target="_blank">จัดการ Templates</a>) — อุปกรณ์ที่<strong>ยังไม่เคยมี schedule เลย</strong>
                    จะใช้ค่าด้านล่างเพื่อกระจายวันรอบแรก
                </div>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">วันเริ่มต้น (สำหรับรอบแรกที่ยังไม่เคยมี schedule)</label>
                        <input type="date" name="seed_start_date" class="form-control"
                               value="<?= e((new DateTime('+7 days'))->format('Y-m-d')) ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">จำนวนเครื่อง/วัน (รอบแรก ต่อ site)</label>
                        <input type="number" name="items_per_day" min="1" max="50" value="8" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label small text-muted mb-1">จำกัดจำนวนสร้าง/ต่อรอบครั้งนี้</label>
                        <input type="number" name="max_items" min="1" class="form-control" placeholder="ไม่ระบุ = ไม่จำกัด">
                        <div class="form-text">เช่น 10 — ถ้าอุปกรณ์ที่เข้าเงื่อนไขมีมากกว่านี้ ส่วนที่เหลือรอกด Generate รอบถัดไป</div>
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
                        <label class="form-label small text-muted mb-1">Site (ไม่เลือก = ทุก site)</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($allSites as $site): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="gen_sites[]"
                                           value="<?= (int)$site['id'] ?>" id="gs_<?= (int)$site['id'] ?>">
                                    <label class="form-check-label" for="gs_<?= (int)$site['id'] ?>"><?= e($site['site_name']) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12">
                        <small class="text-muted">
                            ระบบจะสร้าง/ต่อ schedule ให้เฉพาะ asset ที่ status Active/In Stock และยังไม่มี schedule ค้างอยู่ (Planned/InProgress)
                            สำหรับคู่ asset+template นั้นๆ — ถ้ามีอยู่แล้วจะข้ามอัตโนมัติ ไม่สร้างซ้ำ
                            ถ้าตั้ง "จำกัดจำนวน" ไว้ ระบบจะประมวลผลตามลำดับ Hardware → Mobile → Network
                            (แต่ละกลุ่มเรียงตาม Site ก่อน) แล้วหยุดทันทีที่ครบจำนวน
                        </small>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-lightning-charge"></i> Generate / Refresh</button>
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
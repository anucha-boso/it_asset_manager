<?php
/**
 * =============================================================================
 *  Dashboard — public/index.php
 * -----------------------------------------------------------------------------
 *  สรุปภาพรวม:
 *    - การ์ดเมทริก 4 ใบ: Hardware, Software, Seats Used, Maintenance Due
 *    - กราฟ Hardware แยกตาม status & category (ใช้ Bootstrap progress bar)
 *    - ตาราง "PM ที่ใกล้ถึงกำหนด" (จาก pm_schedules) และ "License ที่ใกล้หมดอายุ"
 *
 *  Security:
 *    - ทุก query ที่รับ user input จะใช้ prepared statement
 *    - ทุก output ที่มาจาก DB ผ่าน e() = htmlspecialchars()
 *
 *  หมายเหตุ: "Upcoming PM" ย้ายมาอิง pm_schedules (rolling interval model) แทน
 *  maintenance_logs.next_pm_date ที่ deprecated แล้ว — pm_execute.php เลิกเขียน field
 *  นั้นตั้งแต่ที่ PM Schedule module เปลี่ยนมาเป็น rolling interval
 * =============================================================================
 */
//declare(strict_types=1);
require_once __DIR__ . '/../config/db_connect.php';
require_once __DIR__ . '/../config/auth.php';
iam_require_login();

// it_borrower ไม่ต้องเห็น dashboard เลย เด้งไปหน้ายืมของทันที
if (is_borrower_only()) {
    header('Location: /it-asset-manager/loans/index.php');
    exit;
}
require_role(['it_admin', 'it_staff', 'it_viewer']);

$pdo = db();
// -----------------------------------------------------------------------------
//  1) สรุปเมทริก
// -----------------------------------------------------------------------------
$totalHardware = (int) $pdo->query(
    "SELECT COUNT(*) FROM hardware_assets"
)->fetchColumn();

$totalSoftware = (int) $pdo->query(
    "SELECT COUNT(*) FROM software_licenses"
)->fetchColumn();

$seatsSummary = $pdo->query("
    SELECT
        COALESCE(SUM(total_seats), 0)                                  AS seats_total,
        COALESCE(SUM(seats_used),  0)                                  AS seats_used,
        COALESCE(SUM(total_seats), 0) - COALESCE(SUM(seats_used), 0)   AS seats_available
    FROM v_software_usage
")->fetch();

$seatsTotal     = (int) $seatsSummary['seats_total'];
$seatsUsed      = (int) $seatsSummary['seats_used'];
$seatsAvailable = (int) $seatsSummary['seats_available'];
$seatsUsedPct   = $seatsTotal > 0 ? round(($seatsUsed / $seatsTotal) * 100) : 0;

// PM ที่ครบกำหนดภายใน 30 วันถัดไป (รวมที่เลยกำหนดแล้ว) — อิง pm_schedules แทน
// maintenance_logs.next_pm_date เดิม (deprecated) นับเฉพาะ status ที่ยังไม่เสร็จ (Planned/InProgress)
$upcomingPmCount = (int) $pdo->query("
    SELECT COUNT(*)
    FROM pm_schedules
    WHERE status IN ('Planned','InProgress')
      AND planned_date IS NOT NULL
      AND planned_date <= (CURRENT_DATE + INTERVAL 30 DAY)
")->fetchColumn();

// -----------------------------------------------------------------------------
//  2) Hardware แยกตามสถานะ
// -----------------------------------------------------------------------------
$statusRows = $pdo->query("
    SELECT status, COUNT(*) AS c
    FROM hardware_assets
    GROUP BY status
    ORDER BY FIELD(status, 'Active','In Repair','In Stock','Retired')
")->fetchAll();

// -----------------------------------------------------------------------------
//  3) Hardware แยกตาม category (top 6)
// -----------------------------------------------------------------------------
$categoryRows = $pdo->query("
    SELECT COALESCE(category, 'Uncategorized') AS category, COUNT(*) AS c
    FROM hardware_assets
    GROUP BY category
    ORDER BY c DESC
    LIMIT 6
")->fetchAll();

// -----------------------------------------------------------------------------
//  4) PM ที่ใกล้ถึงกำหนด (รวมเลยกำหนด) — อิง pm_schedules (rolling interval)
//     JOIN hardware/mobile/network + pm_templates เพื่อโชว์ asset และชื่อ template
// -----------------------------------------------------------------------------
$upcomingPm = $pdo->query("
    SELECT
        ps.id AS schedule_id,
        ps.planned_date,
        ps.status,
        COALESCE(h.asset_id, m.asset_id, n.asset_id)       AS asset_code,
        COALESCE(h.brand, m.brand, n.brand)                 AS brand,
        COALESCE(h.model, m.model, n.model)                 AS model,
        s.site_name,
        pt.name AS template_name,
        DATEDIFF(ps.planned_date, CURRENT_DATE) AS days_left
    FROM pm_schedules ps
    LEFT  JOIN hardware_assets h ON h.id = ps.asset_id
    LEFT  JOIN mobile_assets   m ON m.id = ps.mobile_id
    LEFT  JOIN network_assets  n ON n.id = ps.network_id
    LEFT  JOIN sites s           ON s.id = COALESCE(h.site_id, m.site_id, n.site_id)
    INNER JOIN pm_templates pt   ON pt.id = ps.template_id
    WHERE ps.status IN ('Planned','InProgress')
      AND ps.planned_date IS NOT NULL
      AND ps.planned_date <= (CURRENT_DATE + INTERVAL 60 DAY)
    ORDER BY ps.planned_date ASC
    LIMIT 8
")->fetchAll();

// -----------------------------------------------------------------------------
//  5) License ที่ใกล้หมดอายุ (90 วัน)
// -----------------------------------------------------------------------------
$expiringLicenses = $pdo->query("
    SELECT
        software_id, software_name, publisher, vendor,
        total_seats, expiry_date,
        DATEDIFF(expiry_date, CURRENT_DATE) AS days_left
    FROM software_licenses
    WHERE expiry_date IS NOT NULL
      AND expiry_date <= (CURRENT_DATE + INTERVAL 90 DAY)
    ORDER BY expiry_date ASC
    LIMIT 8
")->fetchAll();

// -----------------------------------------------------------------------------
//  6) Software seat utilization
// -----------------------------------------------------------------------------
$topUtilization = $pdo->query("
    SELECT software_name, publisher, total_seats, seats_used, seats_available
    FROM v_software_usage
    ORDER BY (seats_used / NULLIF(total_seats,0)) DESC
    LIMIT 5
")->fetchAll();

$page_title  = 'Dashboard';
$active_menu = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>

<!-- ====================== Summary metric cards ====================== -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card metric-card metric-blue h-100">
            <div class="card-body">
                <div class="metric-label">Total Hardware Assets</div>
                <div class="metric-value"><?= number_format($totalHardware) ?></div>
                <div class="metric-sub"><i class="bi bi-pc-display"></i> across all sites</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card metric-card metric-teal h-100">
            <div class="card-body">
                <div class="metric-label">Software Licenses</div>
                <div class="metric-value"><?= number_format($totalSoftware) ?></div>
                <div class="metric-sub"><i class="bi bi-box-seam"></i> license SKUs tracked</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <div class="card metric-card metric-amber h-100">
            <div class="card-body">
                <div class="metric-label">Seats Used / Available</div>
                <div class="metric-value">
                    <?= number_format($seatsUsed) ?>
                    <span class="metric-divider">/</span>
                    <span class="metric-muted"><?= number_format($seatsTotal) ?></span>
                </div>
                <div class="progress mt-2" style="height:6px;">
                    <div class="progress-bar bg-warning" style="width: <?= (int)$seatsUsedPct ?>%"></div>
                </div>
                <div class="metric-sub mt-1"><?= number_format($seatsAvailable) ?> seats available</div>
            </div>
        </div>
    </div>
    <div class="col-12 col-md-6 col-xl-3">
        <a href="/it-asset-manager/maintenance/pm_schedule.php" class="text-decoration-none">
            <div class="card metric-card metric-red h-100">
                <div class="card-body">
                    <div class="metric-label">Upcoming PM (30d)</div>
                    <div class="metric-value"><?= number_format($upcomingPmCount) ?></div>
                    <div class="metric-sub"><i class="bi bi-tools"></i> tasks due soon</div>
                </div>
            </div>
        </a>
    </div>
</div>

<!-- ====================== Hardware breakdown ====================== -->
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header bg-white">
                <strong>Hardware by Status</strong>
            </div>
            <div class="card-body">
                <?php if (!$statusRows): ?>
                    <div class="text-muted small">ยังไม่มีข้อมูล</div>
                <?php else: foreach ($statusRows as $row):
                    $pct = $totalHardware > 0 ? round(($row['c'] / $totalHardware) * 100) : 0;
                    $color = match ($row['status']) {
                        'Active'    => '#22c55e',
                        'In Repair' => '#f59e0b',
                        'In Stock'  => '#0ea5e9',
                        'Retired'   => '#94a3b8',
                        default     => '#3b82f6',
                    };
                ?>
                    <div class="d-flex justify-content-between small mb-1">
                        <span>
                            <i class="bi bi-circle-fill me-1" style="font-size:.55rem; color:<?= $color ?>;"></i>
                            <?= e($row['status']) ?>
                        </span>
                        <span class="text-muted"><?= e($row['c']) ?> · <?= e($pct) ?>%</span>
                    </div>
                    <div class="progress mb-3" style="height:8px;">
                        <div class="progress-bar" style="width:<?= (int)$pct ?>%; background:<?= $color ?>;"></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
    <div class="col-12 col-xl-6">
        <div class="card h-100">
            <div class="card-header bg-white">
                <strong>Hardware by Category</strong>
            </div>
            <div class="card-body">
                <?php if (!$categoryRows): ?>
                    <div class="text-muted small">ยังไม่มีข้อมูล</div>
                <?php else: foreach ($categoryRows as $row):
                    $pct = $totalHardware > 0 ? round(($row['c'] / $totalHardware) * 100) : 0;
                ?>
                    <div class="d-flex justify-content-between small mb-1">
                        <span><?= e($row['category']) ?></span>
                        <span class="text-muted"><?= e($row['c']) ?></span>
                    </div>
                    <div class="progress mb-3" style="height:8px;">
                        <div class="progress-bar bg-primary" style="width: <?= (int)$pct ?>%"></div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ====================== Upcoming PM table ====================== -->
<div class="row g-3 mb-4">
    <div class="col-12 col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-calendar-event"></i> Upcoming Preventive Maintenance</strong>
                <a href="/it-asset-manager/maintenance/pm_schedule.php" class="small">ดูทั้งหมด →</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Asset</th>
                            <th>Site</th>
                            <th>Template</th>
                            <th>Planned Date</th>
                            <th class="text-end">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (!$upcomingPm): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">ไม่มีงาน PM ที่ใกล้ถึงกำหนด</td></tr>
                    <?php else: foreach ($upcomingPm as $r):
                        $daysLeft = (int) $r['days_left'];
                        if ($daysLeft < 0) {
                            $badge = 'text-bg-danger';
                            $label = abs($daysLeft) . ' วันที่เลยกำหนด';
                        } elseif ($daysLeft <= 7) {
                            $badge = 'text-bg-warning';
                            $label = "เหลือ {$daysLeft} วัน";
                        } else {
                            $badge = 'text-bg-light border';
                            $label = "เหลือ {$daysLeft} วัน";
                        }
                    ?>
                        <tr>
                            <td>
                                <a href="/it-asset-manager/maintenance/pm_execute.php?schedule_id=<?= (int)$r['schedule_id'] ?>"
                                   class="fw-semibold text-decoration-none"><?= e($r['asset_code']) ?></a>
                                <div class="text-muted small"><?= e($r['brand']) ?> <?= e($r['model']) ?></div>
                            </td>
                            <td class="text-muted small"><?= e($r['site_name'] ?? '—') ?></td>
                            <td class="small"><?= e($r['template_name']) ?></td>
                            <td><?= e($r['planned_date']) ?></td>
                            <td class="text-end">
                                <span class="badge <?= $badge ?>"><?= e($label) ?></span>
                                <?php if ($r['status'] === 'InProgress'): ?>
                                    <span class="badge text-bg-warning ms-1">InProgress</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ====================== Expiring licenses ====================== -->
    <div class="col-12 col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong><i class="bi bi-shield-exclamation"></i> Expiring Licenses (90d)</strong>
                <a href="/it-asset-manager/software/index.php" class="small">ดูทั้งหมด →</a>
            </div>
            <?php if (!$expiringLicenses): ?>
                <div class="text-center text-muted py-4">ไม่มี license ใกล้หมดอายุ</div>
            <?php else: foreach ($expiringLicenses as $lic):
                $daysLeft = (int) $lic['days_left'];
                if ($daysLeft <= 30)      { $toneBg = '#fee2e2'; $toneFg = '#991b1b'; }
                elseif ($daysLeft <= 60)  { $toneBg = '#fef3c7'; $toneFg = '#92400e'; }
                else                      { $toneBg = '#f1f5f9'; $toneFg = '#475569'; }
            ?>
                <div class="d-flex justify-content-between align-items-start px-4 py-3 border-bottom">
                    <div class="me-3">
                        <div class="fw-semibold"><?= e($lic['software_name']) ?></div>
                        <div class="text-muted small"><?= e($lic['publisher']) ?> · <?= e($lic['total_seats']) ?> seats</div>
                    </div>
                    <div class="text-end flex-shrink-0">
                        <div class="small text-muted"><?= e($lic['expiry_date']) ?></div>
                        <span class="badge mt-1" style="background:<?= $toneBg ?>; color:<?= $toneFg ?>;">
                            <?= e($daysLeft) ?> วัน
                        </span>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>
</div>

<!-- ====================== Top utilization ====================== -->
<div class="row g-3">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white">
                <strong><i class="bi bi-bar-chart-line"></i> Top Software Seat Utilization</strong>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Software</th>
                            <th>Publisher</th>
                            <th style="width:30%;">Utilization</th>
                            <th class="text-end">Used / Total</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($topUtilization as $u):
                        $pct = $u['total_seats'] > 0 ? round(($u['seats_used'] / $u['total_seats']) * 100) : 0;
                        $bar = $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                    ?>
                        <tr>
                            <td class="fw-semibold"><?= e($u['software_name']) ?></td>
                            <td class="text-muted"><?= e($u['publisher']) ?></td>
                            <td>
                                <div class="progress" style="height:8px;">
                                    <div class="progress-bar <?= $bar ?>" style="width: <?= (int)$pct ?>%"></div>
                                </div>
                            </td>
                            <td class="text-end">
                                <span class="fw-semibold"><?= e($u['seats_used']) ?></span>
                                <span class="text-muted"> / <?= e($u['total_seats']) ?></span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
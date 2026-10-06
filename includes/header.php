<?php
/**
 * Common header — Bootstrap 5 + sidebar layout
 * /var/www/lab/it-asset-manager/includes/header.php
 *
 * ตัวแปรที่ caller ส่งเข้ามาได้:
 *   $page_title    string  ชื่อหน้าใน <title>
 *   $active_menu   string  key ของเมนูที่ active:
 *                          dashboard | hardware | mobile | software |
 *                          maintenance | pm | loans | sites
 */

/* ── Session ─────────────────────────────────────────────────── */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/module_access.php';
$_is_borrower = is_borrower_only();

//require_role(['it_admin', 'it_staff', 'it_viewer']);

$page_title  = $page_title  ?? 'IT Asset Manager';
$active_menu = $active_menu ?? 'dashboard';

/* ── Helpers ─────────────────────────────────────────────────── */
function nav_active(string $key, string $active): string {
    return $key === $active ? 'active' : '';
}

function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* ── Alert badges ────────────────────────────────────────────── */
$_pm_alert       = 0;
$_warranty_alert = 0;
$_loan_overdue   = 0;

try {
    $_pdo_hdr = db();

    $_pm_alert = (int)$_pdo_hdr->query("
        SELECT COUNT(*) FROM pm_schedules
        WHERE status IN ('Planned','InProgress')
          AND planned_date IS NOT NULL
          AND planned_date <= (CURRENT_DATE + INTERVAL 30 DAY)
    ")->fetchColumn();

    $_warranty_alert = (int)$_pdo_hdr->query("
        SELECT COUNT(*) FROM hardware_assets
        WHERE warranty_expiry IS NOT NULL
          AND warranty_expiry BETWEEN CURRENT_DATE
              AND (CURRENT_DATE + INTERVAL 30 DAY)
          AND status NOT IN ('Retired')
    ")->fetchColumn();

    $_loan_overdue = (int)$_pdo_hdr->query("
        SELECT COUNT(*) FROM asset_loans
        WHERE status = 'Overdue'
    ")->fetchColumn();

} catch (Throwable $_e) {
    /* ไม่ทำให้หน้าพังถ้า DB error */
}

/* ── User info จาก session ───────────────────────────────────── */
$_iam_fullname = $_SESSION['iam_fullname'] ?? '';
$_iam_username = $_SESSION['iam_username'] ?? '';
$_iam_role     = $_SESSION['iam_role']     ?? '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($page_title) ?> · Pacific Cold Storage IT</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600;700&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/it-asset-manager/css/style.css" rel="stylesheet">
    <link rel="alternate icon" type="image/png" href="/it-asset-manager/assets/img/PACIFIC_Cold_Chain_Logo.png">
    <link rel="icon" type="image/svg+xml" href="/it-asset-manager/assets/img/Pacific-svg.svg">

    <style>
    .nav-section-label {
        font-size: 10px;
        text-transform: uppercase;
        letter-spacing: .8px;
        color: #db7c0fff;
        padding: 14px 12px 4px;
        font-weight: 600;
    }
    .sidebar-badge {
        margin-left: auto;
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 999px;
        background: #ef4444;
        color: #fff;
        font-weight: 600;
        line-height: 1.4;
    }
    .role-badge {
        font-size: 10px;
        padding: 1px 6px;
        border-radius: 999px;
        background: rgba(56,189,248,.25);
        color: #7dd3fc;
        font-weight: 600;
        display: inline-block;
        margin-top: 2px;
    }
    </style>
</head>
<body>

<div class="app-shell">
    <!-- ══════════════ SIDEBAR ══════════════ -->
    <aside class="app-sidebar">

        <div class="brand">
            <div class="brand-mark" style="background:transparent;padding:0;overflow:hidden;">
                <img src="/it-asset-manager/assets/img/Pacific-svg.svg"
                     alt="PCS Logo"
                     style="width:44px;height:44px;object-fit:contain;">
            </div>
            <div class="brand-text">
                <div class="brand-title">Pacific Cold Storage</div>
                <div class="brand-sub">IT Asset Manager</div>
            </div>
        </div>

        <nav class="nav flex-column nav-pills">

<?php
// ------ เช็คสิทธิ์แต่ละ module ครั้งเดียว เก็บไว้ใช้ซ้ำ (it_admin bypass อัตโนมัติใน can_access_module) ------
$m_assets      = can_access_module('ASSETS');
$m_mobile      = can_access_module('MOBILE');
$m_software    = can_access_module('SOFTWARE');
$m_network     = can_access_module('NETWORK');
$m_maintenance = can_access_module('MAINTENANCE');
$m_loans       = can_access_module('LOANS');
$m_access_req  = can_access_module('ACCESS_REQUESTS');
$m_sites       = can_access_module('SITES');
$m_emp_dir     = can_access_module('EMPLOYEE_DIRECTORY');
?>

    <!-- Dashboard: เห็นได้ทุกคนที่ login (ไม่ผูกกับ module grant) -->
    <a class="nav-link <?= nav_active('dashboard', $active_menu) ?>"
       href="/it-asset-manager/index.php">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>

<?php if ($m_assets || $m_mobile || $m_software || $m_network): ?>
    <div class="nav-section-label">Assets</div>
<?php endif; ?>

<?php if ($m_assets): ?>
    <a class="nav-link <?= nav_active('hardware', $active_menu) ?>"
       href="/it-asset-manager/assets/index.php">
        <i class="bi bi-pc-display"></i> Hardware Assets
        <?php if ($_warranty_alert > 0): ?>
            <span class="sidebar-badge" title="Warranty ใกล้หมด"><?= $_warranty_alert ?></span>
        <?php endif; ?>
    </a>
<?php endif; ?>

<?php if ($m_mobile): ?>
    <a class="nav-link <?= nav_active('mobile', $active_menu) ?>"
       href="/it-asset-manager/assets/mobile.php">
        <i class="bi bi-phone"></i> Mobile / Handheld
    </a>
<?php endif; ?>

<?php if ($m_software): ?>
    <a class="nav-link <?= nav_active('software', $active_menu) ?>"
       href="/it-asset-manager/software/index.php">
        <i class="bi bi-box-seam"></i> Software Licenses
    </a>
<?php endif; ?>

<?php if ($m_network): ?>
    <a class="nav-link <?= nav_active('network', $active_menu) ?>"
       href="/it-asset-manager/network/index.php">
        <i class="bi bi-hdd-network"></i> Network Assets
    </a>
<?php endif; ?>

<?php if ($m_maintenance): ?>
    <div class="nav-section-label">Maintenance</div>

    <a class="nav-link <?= nav_active('pm', $active_menu) ?>"
       href="/it-asset-manager/maintenance/pm_schedule.php">
        <i class="bi bi-calendar-check"></i> PM Schedule
        <?php if ($_pm_alert > 0): ?>
            <span class="sidebar-badge" title="PM รอดำเนินการ"><?= $_pm_alert ?></span>
        <?php endif; ?>
    </a>

    <a class="nav-link <?= nav_active('maintenance', $active_menu) ?>"
       href="/it-asset-manager/maintenance/index.php">
        <i class="bi bi-tools"></i> Maintenance Logs
    </a>
<?php endif; ?>

<?php if ($m_loans): ?>
    <div class="nav-section-label">Loan System</div>

    <a class="nav-link <?= nav_active('loans', $active_menu) ?>"
       href="/it-asset-manager/loans/index.php">
        <i class="bi bi-box-arrow-right"></i> Loan
        <?php if ($_loan_overdue > 0): ?>
            <span class="sidebar-badge" title="เลยกำหนดคืน"><?= $_loan_overdue ?></span>
        <?php endif; ?>
    </a>
<?php endif; ?>

<?php if ($m_access_req): ?>
    <div class="nav-section-label">Request</div>

        <a class="nav-link <?= nav_active('access_requests', $active_menu) ?>"
       href="/it-asset-manager/access-requests/index.php">
        <i class="bi bi-key"></i> Request
    </a>
<?php endif; ?>

<?php if ($m_sites || $m_emp_dir): ?>
    <div class="nav-section-label">System</div>
<?php endif; ?>

<?php if ($m_sites): ?>
    <a class="nav-link <?= nav_active('sites', $active_menu) ?>"
       href="/it-asset-manager/sites/index.php">
        <i class="bi bi-geo-alt"></i> Sites
    </a>
<?php endif; ?>

<?php if ($m_emp_dir): ?>
    <a class="nav-link <?= nav_active('employee_directory', $active_menu) ?>"
       href="/it-asset-manager/employees/directory.php">
        <i class="bi bi-people"></i> Employee Directory
    </a>
<?php endif; ?>

    <?php if (($_SESSION['iam_role'] ?? '') === 'it_admin'): ?>
    <div class="nav-section-label">Administration</div>

        <a class="nav-link <?= nav_active('module_access', $active_menu) ?>"
       href="/it-asset-manager/administration/module-access.php">
        <i class="bi bi-sliders"></i> Module Access
    </a>

        <a class="nav-link <?= nav_active('access_applications', $active_menu) ?>"
       href="/it-asset-manager/administration/access-applications.php">
        <i class="bi bi-diagram-3"></i> Approver Setup
    </a>
    <?php endif; ?>

</nav>

        <!-- User + Logout -->
        <div class="sidebar-foot">
            <?php if ($_iam_username): ?>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <div style="width:30px;height:30px;border-radius:50%;
                                background:rgba(112, 134, 122, 0.92);flex-shrink:0;
                                display:flex;align-items:center;justify-content:center;
                                font-size:13px;font-weight:700;color:#38bdf8;">
                        <?= e(mb_strtoupper(mb_substr($_iam_fullname ?: $_iam_username, 0, 1))) ?>
                    </div>
                    <div style="overflow:hidden;min-width:0;">
                        <div style="font-size:12px;color:#e2e8f0;font-weight:500;
                                    white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                            <?= e($_iam_fullname ?: $_iam_username) ?>
                        </div>
                        <div class="role-badge"><?= e($_iam_role) ?></div>
                    </div>
                </div>
                <a href="/it-asset-manager/logout.php"
                   class="btn btn-sm w-100"
                   style="font-size:12px;background:rgba(239,68,68,.15);
                          color:#fca5a5;border:1px solid rgba(70, 168, 214, 0.3);">
                    <i class="bi bi-box-arrow-right"></i> ออกจากระบบ
                </a>
            <?php else: ?>
                <small class="text-muted">v0.2.0 · Phase 2</small>
            <?php endif; ?>
        </div>
    </aside>

    <!-- ══════════════ MAIN ══════════════ -->
    <main class="app-main">
        <header class="app-topbar">
            <h1 class="topbar-title"><?= e($page_title) ?></h1>
            <div class="topbar-actions">
                <span class="text-muted small me-3">
                    <i class="bi bi-calendar3"></i>
                    <?= e(date('D, d M Y')) ?>
                </span>

                <?php if ($_pm_alert > 0): ?>
                    <a href="/it-asset-manager/maintenance/pm_schedule.php"
                       class="badge text-bg-warning text-decoration-none me-2"
                       title="PM รอดำเนินการ <?= $_pm_alert ?> รายการ">
                        <i class="bi bi-bell-fill"></i> PM <?= $_pm_alert ?>
                    </a>
                <?php endif; ?>

                <?php if ($_iam_username): ?>
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light dropdown-toggle"
                                data-bs-toggle="dropdown"
                                style="font-size:13px;">
                            <i class="bi bi-person-circle"></i>
                            <?= e($_iam_fullname ?: $_iam_username) ?>
                            <span class="badge text-bg-primary ms-1"
                                  style="font-size:10px;">
                                <?= e($_iam_role) ?>
                            </span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm"
                            style="min-width:200px;font-size:13px;">
                            <li>
                                <div class="px-3 py-2">
                                    <div class="fw-semibold"><?= e($_iam_fullname) ?></div>
                                    <div class="text-muted small"><?= e($_iam_username) ?></div>
                                </div>
                            </li>
                            <li>
                                <a class="dropdown-item" href="/it-asset-manager/profile/index.php">
                                    <i class="bi bi-person-gear me-1"></i> โปรไฟล์ของฉัน
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <a class="dropdown-item text-danger"
                                   href="/it-asset-manager/logout.php">
                                    <i class="bi bi-box-arrow-right me-1"></i>
                                    ออกจากระบบ
                                </a>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a href="/it-asset-manager/login.php"
                       class="badge text-bg-light text-decoration-none">
                        <i class="bi bi-person-circle"></i> เข้าสู่ระบบ
                    </a>
                <?php endif; ?>
            </div>
        </header>
        <section class="app-content">
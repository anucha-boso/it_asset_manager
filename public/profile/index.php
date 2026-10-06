<?php
/**
 * Profile — ดูภาพรวม Asset/License/Loan/Access ของพนักงาน
 * public/profile/index.php
 *
 * 2 โหมด:
 *   1) ไม่มี ?emp=      → โปรไฟล์ของตัวเอง (ผ่าน require_employee_link) แก้ไข Email ได้
 *   2) มี ?emp=<id>     → ดูโปรไฟล์พนักงานคนอื่น (อ่านอย่างเดียว)
 *                          เฉพาะผู้ที่มีสิทธิ์ module EMPLOYEE_DIRECTORY (it_admin ผ่านเสมอ, คนอื่นต้องถูก grant ที่ Module Access)
 *                          it_borrower จะได้ 403 (กัน IDOR จากการแก้ ?emp= ใน URL เอง)
 *
 * <id> คือ employees.id ใน cc_central_employee_db (ตัวเดียวกับ assigned_employee_id)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';   // can_access_module()
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/require_employee_link.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);

$pdo = db();

// ------ เลือกพนักงานที่จะแสดง (ตัวเอง หรือ คนอื่นผ่าน ?emp=) ------
// สำคัญ: เช็คสิทธิ์ฝั่ง server เสมอ — การซ่อนลิงก์อย่างเดียวไม่พอ
$empParam    = $_GET['emp'] ?? '';
$isViewOther = ($empParam !== '');

if ($isViewOther) {
    if (!can_access_module('EMPLOYEE_DIRECTORY')) {
        http_response_code(403);
        exit('คุณไม่มีสิทธิ์ดูโปรไฟล์ของพนักงานคนอื่น');
    }
    $employeeId = filter_var($empParam, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($employeeId === false) {
        http_response_code(400);
        exit('รหัสพนักงานไม่ถูกต้อง');
    }
} else {
    // admin ที่ยังไม่ได้ link กับ employee ก็ยังเข้าโหมดดูคนอื่นได้ เพราะไม่ผ่านบรรทัดนี้
    $employeeId = require_employee_link();
}

$empStmt = employee_db()->prepare("
    SELECT title, first_name, last_name, department, position, person_code
    FROM employees WHERE id = :id
");
$empStmt->execute([':id' => $employeeId]);
$employee = $empStmt->fetch();
if (!$employee) {
    if ($isViewOther) {
        http_response_code(404);
        exit('ไม่พบข้อมูลพนักงานรหัสนี้');
    }
    http_response_code(500);
    exit('ไม่พบข้อมูลพนักงาน กรุณาติดต่อ IT Admin');
}
$fullName = trim($employee['title'] . ' ' . $employee['first_name'] . ' ' . $employee['last_name']);

// query string สำหรับต่อท้ายลิงก์ในหน้านี้ (เช่น history.php) ให้คงโหมดเดิม
$empQs = $isViewOther ? '?emp=' . $employeeId : '';

// ------ Email (เฉพาะโปรไฟล์ตัวเอง — ไม่ดึง PII ของคนอื่นมาแสดง) ------
$currentEmail = '';
if (!$isViewOther) {
    $contactStmt = $pdo->prepare("SELECT email FROM employee_contacts WHERE employee_id = :id");
    $contactStmt->execute([':id' => $employeeId]);
    $currentEmail = $contactStmt->fetchColumn() ?: '';
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // โหมดดูคนอื่นเป็น read-only — กัน POST ตรงๆ แม้ฟอร์มจะถูกซ่อนแล้ว
    if ($isViewOther) {
        http_response_code(403);
        exit('ไม่สามารถแก้ไขโปรไฟล์ของพนักงานคนอื่นจากหน้านี้ได้');
    }

    csrf_verify();
    $email = trim((string)($_POST['email'] ?? ''));

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    if (!$errors) {
        try {
            $pdo->prepare("
                INSERT INTO employee_contacts (employee_id, email, updated_by_ad)
                VALUES (:eid, :email, :ad)
                ON DUPLICATE KEY UPDATE email = :email2, updated_by_ad = :ad2
            ")->execute([
                ':eid' => $employeeId, ':email' => $email !== '' ? $email : null,
                ':ad' => $_SESSION['iam_username'] ?? null,
                ':email2' => $email !== '' ? $email : null,
                ':ad2' => $_SESSION['iam_username'] ?? null,
            ]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'บันทึกโปรไฟล์เรียบร้อยแล้ว'];
            header('Location: /it-asset-manager/profile/index.php');
            exit;
        } catch (PDOException $e) {
            error_log('[PROFILE SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ';
        }
    }
}

// ------ Hardware Assets ------
$hwStmt = $pdo->prepare("
    SELECT asset_id, category, brand, model, status
    FROM hardware_assets
    WHERE assigned_employee_id = :eid
    ORDER BY status, asset_id
");
$hwStmt->execute([':eid' => $employeeId]);
$myHardwareAssets = $hwStmt->fetchAll();

// ------ Mobile Assets ------
$mobStmt = $pdo->prepare("
    SELECT asset_id, device_type AS category, brand, model, status
    FROM mobile_assets
    WHERE assigned_employee_id = :eid
    ORDER BY status, asset_id
");
$mobStmt->execute([':eid' => $employeeId]);
$myMobileAssets = $mobStmt->fetchAll();

// ------ Assets รวม (Hardware + Mobile) ------
$myAssets = array_merge($myHardwareAssets, $myMobileAssets);

// ------ Software Licenses ------
$licStmt = $pdo->prepare("
    SELECT sl.software_name, sl.publisher, m.status, m.install_date
    FROM software_allocation_map m
    JOIN software_licenses sl ON sl.id = m.software_id
    WHERE m.assigned_employee_id = :eid
    ORDER BY m.status, sl.software_name
");
$licStmt->execute([':eid' => $employeeId]);
$myLicenses = $licStmt->fetchAll();

// ------ Loans (Hardware + Mobile + Network) ------
$loanStmt = $pdo->prepare("
    SELECT l.loan_code, l.status, l.loan_date, l.expected_return,
        DATEDIFF(l.expected_return, CURRENT_DATE) AS days_left,
        COALESCE(h.asset_id, m.asset_id, n.asset_id) AS asset_code,
        COALESCE(h.brand, m.brand, n.brand) AS brand,
        COALESCE(h.model, m.model, n.model) AS model
    FROM asset_loans l
    LEFT JOIN hardware_assets h ON h.id = l.asset_id
    LEFT JOIN mobile_assets   m ON m.id = l.mobile_id
    LEFT JOIN network_assets  n ON n.id = l.network_asset_id
    WHERE l.borrower_employee_id = :eid
    ORDER BY FIELD(l.status,'Overdue','OnLoan','Pending','Approved','Returned','Lost','Damaged'), l.loan_date DESC
    LIMIT 20
");
$loanStmt->execute([':eid' => $employeeId]);
$myLoans = $loanStmt->fetchAll();

// ------ Access / Permission (completed requests) ------
// กรองด้วย beneficiary_employee_id เท่านั้น และ dedupe เหลือรายการล่าสุดต่อ 1 แอป
$accStmt = $pdo->prepare("
    SELECT r.request_no, a.app_name, lv.level_name, r.access_level_text, r.status
    FROM access_requests r
    JOIN access_applications a ON a.id = r.application_id
    LEFT JOIN access_application_levels lv ON lv.id = r.access_level_id
    WHERE r.beneficiary_employee_id = :eid
      AND r.status = 'Completed'
    ORDER BY r.completed_at DESC
");
$accStmt->execute([':eid' => $employeeId]);
$accessRaw = $accStmt->fetchAll();

$myAccess  = [];
$seenApps  = [];
foreach ($accessRaw as $acc) {
    if (in_array($acc['app_name'], $seenApps, true)) continue;
    $seenApps[] = $acc['app_name'];
    $myAccess[] = $acc;
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title  = $isViewOther ? 'โปรไฟล์พนักงาน: ' . $fullName : 'โปรไฟล์ของฉัน';
$active_menu = $isViewOther ? 'employee_directory' : '';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($isViewOther): ?>
    <div class="d-flex justify-content-between align-items-center mb-2">
        <a href="/it-asset-manager/employees/directory.php" class="text-decoration-none small text-muted">
            <i class="bi bi-arrow-left"></i> กลับไปรายชื่อพนักงาน
        </a>
        <span class="badge text-bg-light border"><i class="bi bi-eye"></i> โหมดดูอย่างเดียว</span>
    </div>
<?php endif; ?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<!-- ====== Profile Header Banner ====== -->
<div class="card mb-3 overflow-hidden">
    <div style="background:linear-gradient(135deg,#1e3a8a,#3b82f6); padding:24px 28px; color:#fff;">
        <div class="d-flex align-items-center gap-3">
            <div style="width:64px;height:64px;border-radius:50%;background:rgba(255,255,255,.2);
                        display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;
                        flex-shrink:0;">
                <?= e(mb_strtoupper(mb_substr($employee['first_name'], 0, 1))) ?>
            </div>
            <div>
                <div style="font-size:20px;font-weight:700;"><?= e($fullName) ?></div>
                <div style="opacity:.85;font-size:13px;">
                    รหัส <?= e($employee['person_code']) ?> · <?= e($employee['department'] ?? '—') ?>
                    <?php if ($employee['position']): ?> · <?= e($employee['position']) ?><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ====== Summary Cards ====== -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-blue h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-pc-display"></i> Assets</div>
                <div class="metric-value"><?= count($myAssets) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-amber h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-box-seam"></i> Licenses</div>
                <div class="metric-value"><?= count($myLicenses) ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-teal h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-box-arrow-right"></i> Loans</div>
                <div class="metric-value">
                    <?= count(array_filter($myLoans, fn($l) => in_array($l['status'], ['Pending','Approved','OnLoan','Overdue'], true))) ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card metric-card metric-red h-100">
            <div class="card-body">
                <div class="metric-label"><i class="bi bi-shield-lock"></i> Access</div>
                <div class="metric-value"><?= count($myAccess) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ====== Assets (Grid) ====== -->
<div class="card mb-3">
    <div class="card-header bg-white"><strong><i class="bi bi-pc-display"></i> Assets (Hardware + Mobile)</strong></div>
    <div class="card-body">
        <?php if (!$myAssets): ?>
            <div class="text-muted text-center py-3">ไม่มีอุปกรณ์ที่ถือครองอยู่</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($myAssets as $a): ?>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold"><?= e($a['brand'] . ' ' . $a['model']) ?></div>
                            <div class="text-muted small mb-2"><?= e($a['asset_id']) ?> · <?= e($a['category']) ?></div>
                            <span class="badge <?= $a['status'] === 'Active' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= e($a['status']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ====== Licenses (Grid) ====== -->
<div class="card mb-3">
    <div class="card-header bg-white"><strong><i class="bi bi-box-seam"></i> Licenses</strong></div>
    <div class="card-body">
        <?php if (!$myLicenses): ?>
            <div class="text-muted text-center py-3">ไม่มี Software ที่ Allocate อยู่</div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($myLicenses as $s): ?>
                    <div class="col-md-4">
                        <div class="border rounded p-3 h-100">
                            <div class="fw-semibold"><?= e($s['software_name']) ?></div>
                            <div class="text-muted small mb-2"><?= e($s['publisher'] ?? '—') ?></div>
                            <span class="badge <?= $s['status'] === 'Installed' ? 'text-bg-success' : 'text-bg-secondary' ?>">
                                <?= e($s['status']) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ====== Loans ====== -->
<div class="card mb-3">
    <div class="card-header bg-white"><strong><i class="bi bi-box-arrow-right"></i> Loans</strong></div>
    <div class="card-body">
        <?php if (!$myLoans): ?>
            <div class="text-muted text-center py-3">ไม่มีประวัติการยืมอุปกรณ์</div>
        <?php else: ?>
            <div class="row g-2">
                <?php foreach ($myLoans as $ln):
                    $lnStClass = match ($ln['status']) {
                        'OnLoan'   => 'text-bg-primary',
                        'Overdue'  => 'text-bg-danger',
                        'Pending'  => 'text-bg-warning',
                        'Approved' => 'text-bg-info',
                        'Returned' => 'text-bg-success',
                        'Lost'     => 'text-bg-danger',
                        'Damaged'  => 'text-bg-secondary',
                        default    => 'text-bg-light border',
                    };
                    $lnDays = (int)$ln['days_left'];
                ?>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center border rounded p-2 px-3">
                            <div>
                                <div class="fw-semibold"><?= e($ln['asset_code']) ?></div>
                                <div class="text-muted small">
                                    <?= e($ln['brand']) ?> <?= e($ln['model']) ?> · <?= e($ln['loan_code']) ?>
                                </div>
                                <?php if (in_array($ln['status'], ['OnLoan','Overdue'], true)): ?>
                                    <div class="small <?= $lnDays < 0 ? 'text-danger fw-semibold' : 'text-muted' ?>">
                                        <?= $lnDays < 0 ? 'เลยกำหนด ' . abs($lnDays) . ' วัน' : 'กำหนดคืน ' . e($ln['expected_return']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <span class="badge <?= $lnStClass ?>"><?= e($ln['status']) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ====== Access / Permission ====== -->
<div class="card mb-3">
    <div class="card-header" style="background:#fff7ed;border-bottom:1px solid #fed7aa;">
        <strong class="text-warning-emphasis"><i class="bi bi-shield-lock"></i> Access / Permission</strong>
    </div>
    <div class="card-body">
        <?php if (!$myAccess): ?>
            <div class="text-muted text-center py-3">ยังไม่มีสิทธิ์ที่อนุมัติแล้ว</div>
        <?php else: ?>
            <div class="row g-2">
                <?php foreach ($myAccess as $acc): ?>
                    <div class="col-md-6">
                        <div class="d-flex justify-content-between align-items-center border rounded p-2 px-3">
                            <div>
                                <div class="fw-semibold"><?= e($acc['app_name']) ?></div>
                                <div class="text-muted small"><?= e($acc['request_no']) ?></div>
                            </div>
                            <span class="badge text-bg-warning-subtle text-warning-emphasis border border-warning-subtle">
                                <?= e($acc['level_name'] ?: ($acc['access_level_text'] ?: 'User')) ?>
                            </span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="mb-3">
    <a href="/it-asset-manager/profile/history.php<?= e($empQs) ?>" class="small text-decoration-none">
        <i class="bi bi-clock-history"></i> ดูประวัติทั้งหมด →
    </a>
</div>

<?php if (!$isViewOther): ?>
<!-- ====== Email Settings (เฉพาะโปรไฟล์ตัวเอง) ====== -->
<div class="card mb-3" style="max-width:500px;">
    <div class="card-header bg-white"><strong><i class="bi bi-envelope"></i> การแจ้งเตือน</strong></div>
    <div class="card-body">
        <form method="post">
            <?php csrf_field(); ?>
            <label class="form-label">Email สำหรับรับการแจ้งเตือน</label>
            <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                   value="<?= e($currentEmail) ?>" placeholder="name@company.com">
            <?php if (isset($errors['email'])): ?>
                <div class="invalid-feedback"><?= e($errors['email']) ?></div>
            <?php endif; ?>
            <button class="btn btn-primary mt-3"><i class="bi bi-check-lg"></i> บันทึก</button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
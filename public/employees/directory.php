<?php
/**
 * Employee Directory — ดูโปรไฟล์ของพนักงานคนไหนก็ได้
 * public/employees/directory.php
 * เฉพาะ it_admin / it_staff
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';

require_role(['it_admin', 'it_staff']);

$pdo = db();

// ------ ค้นหาพนักงาน (server-side filter) ------
$searchQ = trim((string)($_GET['q'] ?? ''));

$employees = [];

if ($searchQ !== '') {
    $stmt = employee_db()->prepare("
        SELECT id, title, first_name, last_name, department, position, person_code
        FROM employees
        WHERE resign_status = 'Active'
          AND (first_name LIKE :q1 OR last_name LIKE :q2 OR person_code LIKE :q3)
        ORDER BY first_name
        LIMIT 30
    ");
    $like = '%' . $searchQ . '%';
    $stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like]);
    $employees = $stmt->fetchAll();
}

// ------ ถ้ามีการเลือกพนักงาน (?id=) ให้ดึงข้อมูลโปรไฟล์เต็ม ------
$selectedId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$selectedEmployee = null;
$myAssets = $myLicenses = $myAccess = $myApplications = [];

if ($selectedId > 0) {
    $empStmt = employee_db()->prepare("
        SELECT id, title, first_name, last_name, department, position, person_code
        FROM employees WHERE id = :id
    ");
    $empStmt->execute([':id' => $selectedId]);
    $selectedEmployee = $empStmt->fetch();

    if ($selectedEmployee) {
        $hwStmt = $pdo->prepare("
            SELECT asset_id, category, brand, model, status
            FROM hardware_assets WHERE assigned_employee_id = :eid
            ORDER BY status, asset_id
        ");
        $hwStmt->execute([':eid' => $selectedId]);
        $myAssets = $hwStmt->fetchAll();

        // Software Licenses (เดิมชื่อ $myApps — เปลี่ยนชื่อให้ตรงความหมาย)
        $licStmt = $pdo->prepare("
            SELECT sl.software_name, sl.publisher, m.status
            FROM software_allocation_map m
            JOIN software_licenses sl ON sl.id = m.software_id
            WHERE m.assigned_employee_id = :eid
            ORDER BY m.status, sl.software_name
        ");
        $licStmt->execute([':eid' => $selectedId]);
        $myLicenses = $licStmt->fetchAll();

        $accStmt = $pdo->prepare("
            SELECT r.request_no, a.app_name, lv.level_name, r.access_level_text
            FROM access_requests r
            JOIN access_applications a ON a.id = r.application_id
            LEFT JOIN access_application_levels lv ON lv.id = r.access_level_id
            WHERE (r.requestor_employee_id = :eid1 OR r.beneficiary_employee_id = :eid2)
              AND r.status = 'Completed'
            ORDER BY r.completed_at DESC
        ");
        $accStmt->execute([':eid1' => $selectedId, ':eid2' => $selectedId]);
        $myAccess = $accStmt->fetchAll();

        // Applications = distinct app_name จาก $myAccess (ไม่ query เพิ่ม)
        $myApplications = array_values(array_unique(array_column($myAccess, 'app_name')));
        sort($myApplications);
    }
}

$page_title  = 'Employee Directory';
$active_menu = 'employee_directory';
require __DIR__ . '/../../includes/header.php';
?>

<div class="row g-3 align-items-stretch">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-white">
                <form method="get" class="d-flex gap-2">
                    <input type="text" name="q" value="<?= e($searchQ) ?>" class="form-control form-control-sm"
                           placeholder="ค้นหาชื่อ หรือรหัสพนักงาน...">
                    <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search"></i></button>
                </form>
            </div>
            <div class="list-group list-group-flush" style="max-height:600px; overflow-y:auto;">
                <?php if ($searchQ === ''): ?>
                    <div class="p-4 text-center text-muted small">พิมพ์ชื่อหรือรหัสพนักงานเพื่อค้นหา</div>
                <?php elseif (!$employees): ?>
                    <div class="p-4 text-center text-muted small">ไม่พบพนักงานที่ตรงกับคำค้นหา</div>
                <?php else: foreach ($employees as $emp):
                    $isActive = $selectedId === (int)$emp['id'];
                ?>
                    <a href="?id=<?= e($emp['id']) ?>&q=<?= urlencode($searchQ) ?>"
                       class="list-group-item list-group-item-action <?= $isActive ? 'active' : '' ?>">
                        <div class="fw-semibold"><?= e($emp['title'] . ' ' . $emp['first_name'] . ' ' . $emp['last_name']) ?></div>
                        <div class="small <?= $isActive ? '' : 'text-muted' ?>">
                            <?= e($emp['person_code']) ?> · <?= e($emp['department'] ?? '—') ?>
                        </div>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <?php if (!$selectedEmployee): ?>
            <div class="card h-100">
                <div class="card-body d-flex align-items-center justify-content-center text-muted" style="min-height:300px;">
                    <div class="text-center">
                        <i class="bi bi-person-badge" style="font-size:32px;"></i>
                        <div class="mt-2">ค้นหาและเลือกพนักงานทางซ้ายเพื่อดูโปรไฟล์</div>
                    </div>
                </div>
            </div>
        <?php else:
            $fullName = trim($selectedEmployee['title'] . ' ' . $selectedEmployee['first_name'] . ' ' . $selectedEmployee['last_name']);
        ?>
            <div class="card mb-3 overflow-hidden">
                <div style="background:linear-gradient(135deg,#1e3a8a,#3b82f6); padding:20px 24px; color:#fff;">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:56px;height:56px;border-radius:50%;background:rgba(255,255,255,.2);
                                    display:flex;align-items:center;justify-content:center;font-size:22px;font-weight:700;
                                    flex-shrink:0;">
                            <?= e(mb_strtoupper(mb_substr($selectedEmployee['first_name'], 0, 1))) ?>
                        </div>
                        <div>
                            <div style="font-size:18px;font-weight:700;"><?= e($fullName) ?></div>
                            <div style="opacity:.85;font-size:13px;">
                                รหัส <?= e($selectedEmployee['person_code']) ?> · <?= e($selectedEmployee['department'] ?? '—') ?>
                                <?php if ($selectedEmployee['position']): ?> · <?= e($selectedEmployee['position']) ?><?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Summary cards — 4 ใบ ตาม mockup -->
            <div class="row g-3 mb-3">
                <div class="col-6 col-md-3">
                    <div class="card metric-card metric-blue h-100">
                        <div class="card-body py-3">
                            <div class="metric-label small">Assets</div>
                            <div class="metric-value"><?= count($myAssets) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card metric-card metric-teal h-100">
                        <div class="card-body py-3">
                            <div class="metric-label small">Applications</div>
                            <div class="metric-value"><?= count($myApplications) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card metric-card metric-amber h-100">
                        <div class="card-body py-3">
                            <div class="metric-label small">Licenses</div>
                            <div class="metric-value"><?= count($myLicenses) ?></div>
                        </div>
                    </div>
                </div>
                <div class="col-6 col-md-3">
                    <div class="card metric-card metric-red h-100">
                        <div class="card-body py-3">
                            <div class="metric-label small">Access</div>
                            <div class="metric-value"><?= count($myAccess) ?></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header bg-white"><strong><i class="bi bi-pc-display"></i> Hardware Assets</strong></div>
                <div class="card-body">
                    <?php if (!$myAssets): ?>
                        <div class="text-muted text-center py-2">ไม่มีอุปกรณ์ที่ถือครองอยู่</div>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($myAssets as $a): ?>
                                <div class="col-md-6">
                                    <div class="border rounded p-2 px-3 d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold small"><?= e($a['brand'] . ' ' . $a['model']) ?></div>
                                            <div class="text-muted small"><?= e($a['asset_id']) ?></div>
                                        </div>
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

            <!-- Applications — list เฉยๆ, distinct app จาก Access ที่ Completed -->
            <div class="card mb-3">
                <div class="card-header bg-white"><strong><i class="bi bi-grid-3x3-gap"></i> Applications</strong></div>
                <div class="card-body">
                    <?php if (!$myApplications): ?>
                        <div class="text-muted text-center py-2">ยังไม่มีสิทธิ์การใช้งานโปรแกรมใดที่อนุมัติแล้ว</div>
                    <?php else: ?>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach ($myApplications as $appName): ?>
                                <span class="badge text-bg-light border px-3 py-2"><?= e($appName) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Licenses — เดิมชื่อ Applications -->
            <div class="card mb-3">
                <div class="card-header bg-white"><strong><i class="bi bi-box-seam"></i> Licenses</strong></div>
                <div class="card-body">
                    <?php if (!$myLicenses): ?>
                        <div class="text-muted text-center py-2">ไม่มี Software ที่ Allocate อยู่</div>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($myLicenses as $s): ?>
                                <div class="col-md-6">
                                    <div class="border rounded p-2 px-3 d-flex justify-content-between align-items-center">
                                        <div class="fw-semibold small"><?= e($s['software_name']) ?></div>
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

            <div class="card">
                <div class="card-header" style="background:#fff7ed;border-bottom:1px solid #fed7aa;">
                    <strong class="text-warning-emphasis"><i class="bi bi-shield-lock"></i> Access / Permission</strong>
                </div>
                <div class="card-body">
                    <?php if (!$myAccess): ?>
                        <div class="text-muted text-center py-2">ยังไม่มีสิทธิ์ที่อนุมัติแล้ว</div>
                    <?php else: ?>
                        <div class="row g-2">
                            <?php foreach ($myAccess as $acc): ?>
                                <div class="col-md-6">
                                    <div class="d-flex justify-content-between align-items-center border rounded p-2 px-3">
                                        <div class="fw-semibold small"><?= e($acc['app_name']) ?></div>
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
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
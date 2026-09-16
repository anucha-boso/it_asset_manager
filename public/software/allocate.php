<?php
/**
 * =============================================================================
 *  Software Allocation Map — manage assignments of one license
 *  public/software/allocate.php?id=<software_id>
 * -----------------------------------------------------------------------------
 *  หน้านี้ทำ 3 อย่าง:
 *   1. แสดงสรุป seat (used/total/available) ของ license นี้
 *   2. ตารางแสดง allocation ทั้งหมด พร้อมปุ่ม Uninstall / ลบ
 *   3. ฟอร์มเพิ่ม allocation ใหม่ (เลือก asset + ค้นหาพนักงานจาก HR หรือกรอก User AD เอง)
 *
 *  แก้ไข (แทนที่ free-text assigned_user_ad เดิม):
 *   - เพิ่ม live-search พนักงานจาก cc_central_employee_db (ผ่าน employee_db())
 *     เขียนค่าลง assigned_employee_id ซึ่งเป็นคอลัมน์ที่หน้า Employee Profile/
 *     Directory ใช้ query หา "Licenses" ของพนักงาน — เดิมคอลัมน์นี้ไม่เคยถูกเซ็ต
 *     ทำให้ license ที่ allocate ผ่านหน้านี้ไม่เคยไปโผล่ที่หน้าโปรไฟล์เลย
 *   - ค่า assigned_user_ad ที่บันทึกจริงเมื่อเลือกพนักงานจาก search จะดึงจาก
 *     employee_db() ฝั่งเซิร์ฟเวอร์เสมอ (ไม่เชื่อค่าที่ client ส่งมา แม้ JS จะ
 *     auto-fill ให้ดูก็ตาม) — กัน tamper ผ่าน DevTools
 *   - ยังเก็บช่อง "User AD กรอกเอง" ไว้สำหรับกรณีไม่มีคนในระบบ HR (contractor/vendor)
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_role(['it_admin','it_staff']);



require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/employee_log.php';
$pdo = db();

$swId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
if ($swId <= 0) { http_response_code(400); exit('Missing software id'); }

// load license
$licStmt = $pdo->prepare("SELECT * FROM software_licenses WHERE id = :id");
$licStmt->execute([':id' => $swId]);
$license = $licStmt->fetch();
if (!$license) { http_response_code(404); exit('License not found'); }

$errors = [];

// -----------------------------------------------------------------------------
//  Edit mode — ?id=<license_id>&edit=<allocation_id>
//  ตรวจจาก $_GET เสมอ (แม้ตอน POST) เพราะฟอร์มไม่มี action attribute จึง submit
//  กลับมาที่ URL เดิมรวม query string นี้ด้วย ทำให้ใช้ตัวแปรชุดเดียวกันได้ทั้ง
//  ตอนโหลดหน้าแรก (GET) และตอน validation fail แล้ว re-render (POST)
// -----------------------------------------------------------------------------
$editId  = isset($_GET['edit']) && ctype_digit((string)$_GET['edit']) ? (int)$_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $editStmt = $pdo->prepare("SELECT * FROM software_allocation_map WHERE id = :id AND software_id = :sid");
    $editStmt->execute([':id' => $editId, ':sid' => $swId]);
    $editRow = $editStmt->fetch();
    if (!$editRow) { $editId = 0; } // id ปลอม/ไม่ตรง license นี้ → ถือว่าไม่ได้อยู่ในโหมดแก้ไข
}

// -----------------------------------------------------------------------------
//  POST actions
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $assetId   = isset($_POST['asset_id']) && ctype_digit((string)$_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
        $empId     = isset($_POST['assigned_employee_id']) && ctype_digit((string)$_POST['assigned_employee_id']) ? (int)$_POST['assigned_employee_id'] : null;
        $manualAd  = trim((string)($_POST['assigned_user_ad'] ?? '')) ?: null;
        $instDate  = trim((string)($_POST['install_date'] ?? '')) ?: null;
        $notes     = trim((string)($_POST['notes'] ?? '')) ?: null;

        $userAd       = null; // ค่าที่จะบันทึกจริงลง assigned_user_ad
        $empDisplay   = null; // ใช้แค่โชว์ error message ถ้าจำเป็น

        // ----- ถ้าเลือกพนักงานจาก search: ยืนยันตัวตนจาก DB เสมอ ไม่เชื่อค่า client -----
        if ($empId !== null) {
            $empStmt = employee_db()->prepare("
                SELECT id, person_code, title, first_name, last_name
                FROM employees
                WHERE id = :id AND resign_status = 'Active'
            ");
            $empStmt->execute([':id' => $empId]);
            $emp = $empStmt->fetch();
            if (!$emp) {
                $errors['add'] = 'ไม่พบพนักงานที่เลือก หรือพนักงานไม่ active แล้ว กรุณาค้นหาใหม่';
            } else {
                $userAd     = $emp['person_code'];
                $empDisplay = trim($emp['title'] . ' ' . $emp['first_name'] . ' ' . $emp['last_name']);
            }
        } elseif ($manualAd !== null) {
            $userAd = $manualAd;
        }

        // ----- ถ้าเลือก asset จาก search: ยืนยันว่ามีจริงและสถานะพร้อมใช้เสมอ
        //       ไม่เชื่อค่า client (เดิมเชื่อได้เพราะเป็น <select> ที่ query กรองมาแล้ว
        //       ตอนนี้เปลี่ยนเป็นช่องค้นหาอิสระ ต้องยืนยันฝั่งเซิร์ฟเวอร์เอง) -----
        if (!$errors && $assetId !== null) {
            $assetStmt = $pdo->prepare("
                SELECT id, asset_id, brand, model
                FROM hardware_assets
                WHERE id = :id AND status IN ('Active','In Stock')
            ");
            $assetStmt->execute([':id' => $assetId]);
            if (!$assetStmt->fetch()) {
                $errors['add'] = 'ไม่พบ Asset ที่เลือก หรือสถานะไม่ใช่ Active/In Stock กรุณาค้นหาใหม่';
            }
        }

        if (!$errors && !$assetId && !$empId && !$manualAd) {
            $errors['add'] = 'ต้องระบุ Asset, พนักงาน (จากระบบค้นหา) หรือ User AD อย่างน้อยหนึ่งอย่าง';
        }
        if (!$errors && $instDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $instDate)) {
            $errors['add'] = 'รูปแบบวันที่ไม่ถูกต้อง';
        }

        // ตรวจ seat ว่าง
        if (!$errors) {
            $usedStmt = $pdo->prepare("SELECT COUNT(*) FROM software_allocation_map WHERE software_id=:id AND status='Installed'");
            $usedStmt->execute([':id' => $swId]);
            $used = (int)$usedStmt->fetchColumn();
            if ($used >= (int)$license['total_seats']) {
                $errors['add'] = 'ไม่มี seat เหลือสำหรับ allocate (ใช้เต็ม ' . $used . ' / ' . $license['total_seats'] . ')';
            }
        }

        // ตรวจซ้ำ — asset เดียวกัน + license เดียวกัน + ยัง installed
        if (!$errors && $assetId) {
            $dup = $pdo->prepare("SELECT id FROM software_allocation_map
                                  WHERE software_id=:sid AND asset_id=:aid AND status='Installed'");
            $dup->execute([':sid' => $swId, ':aid' => $assetId]);
            if ($dup->fetchColumn()) {
                $errors['add'] = 'อุปกรณ์นี้มี license นี้ติดตั้งอยู่แล้ว';
            }
        }

        // ตรวจซ้ำ — พนักงานคนเดียวกัน (จาก search) + license เดียวกัน + ยัง installed
        if (!$errors && $empId) {
            $dupEmp = $pdo->prepare("SELECT id FROM software_allocation_map
                                     WHERE software_id=:sid AND assigned_employee_id=:eid AND status='Installed'");
            $dupEmp->execute([':sid' => $swId, ':eid' => $empId]);
            if ($dupEmp->fetchColumn()) {
                $errors['add'] = ($empDisplay ?: 'พนักงานคนนี้') . ' มี license นี้ allocate อยู่แล้ว';
            }
        }

        // ตรวจซ้ำ — user AD เดียวกัน (กรณีกรอกเอง ไม่ผูก employee) + license เดียวกัน + ยัง installed
        if (!$errors && !$empId && $manualAd) {
            $dupUser = $pdo->prepare("SELECT id FROM software_allocation_map
                                      WHERE software_id=:sid AND assigned_user_ad=:uad AND status='Installed'");
            $dupUser->execute([':sid' => $swId, ':uad' => $manualAd]);
            if ($dupUser->fetchColumn()) {
                $errors['add'] = 'ผู้ใช้นี้มี license นี้ allocate อยู่แล้ว';
            }
        }

        if (!$errors) {
            $currentUser = iam_user();
            $stmt = $pdo->prepare("
                INSERT INTO software_allocation_map
                    (software_id, asset_id, assigned_user_ad, assigned_employee_id, install_date, status, notes, created_by)
                VALUES
                    (:sid, :aid, :uad, :eid, :idt, 'Installed', :notes, :created_by)
            ");
            $stmt->execute([
                ':sid'        => $swId,
                ':aid'        => $assetId,
                ':uad'        => $userAd,
                ':eid'        => $empId,
                ':idt'        => $instDate,
                ':notes'      => $notes,
                ':created_by' => $currentUser['username'],
            ]);

            // ── History log (เฟส 1: เฉพาะ Software/Hardware/Mobile — ยังไม่รวม
            //    Access Request จนกว่าจะทำเฟส 2) — log เฉพาะตอนผูกกับพนักงานจริง
            //    (มี $empId) ไม่ log ถ้าเป็นแค่ manual AD/asset ที่ไม่มี employee ───
            if ($empId !== null) {
                logEmployeeTransaction(
                    $pdo,
                    $empId,
                    'App Assign',
                    'software_allocation_map',
                    (int)$pdo->lastInsertId(),
                    'Allocate ' . $license['software_name'],
                    $currentUser['username'] ?? null
                );
            }

            $_SESSION['flash'] = ['type'=>'success','message'=>'เพิ่ม allocation เรียบร้อย'];
            header("Location: /it-asset-manager/software/allocate.php?id={$swId}");
            exit;
        }
    }

    if ($action === 'update' && $editId > 0 && $editRow) {
        $assetId   = isset($_POST['asset_id']) && ctype_digit((string)$_POST['asset_id']) ? (int)$_POST['asset_id'] : null;
        $empId     = isset($_POST['assigned_employee_id']) && ctype_digit((string)$_POST['assigned_employee_id']) ? (int)$_POST['assigned_employee_id'] : null;
        $manualAd  = trim((string)($_POST['assigned_user_ad'] ?? '')) ?: null;
        $instDate  = trim((string)($_POST['install_date'] ?? '')) ?: null;
        $notes     = trim((string)($_POST['notes'] ?? '')) ?: null;

        $userAd     = null;
        $empDisplay = null;

        // ----- ตรวจสอบเหมือน 'add' ทุกจุด (ยืนยัน employee/asset จาก DB เสมอ) -----
        if ($empId !== null) {
            $empStmt = employee_db()->prepare("
                SELECT id, person_code, title, first_name, last_name
                FROM employees
                WHERE id = :id AND resign_status = 'Active'
            ");
            $empStmt->execute([':id' => $empId]);
            $emp = $empStmt->fetch();
            if (!$emp) {
                $errors['edit'] = 'ไม่พบพนักงานที่เลือก หรือพนักงานไม่ active แล้ว กรุณาค้นหาใหม่';
            } else {
                $userAd     = $emp['person_code'];
                $empDisplay = trim($emp['title'] . ' ' . $emp['first_name'] . ' ' . $emp['last_name']);
            }
        } elseif ($manualAd !== null) {
            $userAd = $manualAd;
        }

        if (!$errors && $assetId !== null) {
            $assetStmt = $pdo->prepare("
                SELECT id, asset_id, brand, model
                FROM hardware_assets
                WHERE id = :id AND status IN ('Active','In Stock')
            ");
            $assetStmt->execute([':id' => $assetId]);
            if (!$assetStmt->fetch()) {
                $errors['edit'] = 'ไม่พบ Asset ที่เลือก หรือสถานะไม่ใช่ Active/In Stock กรุณาค้นหาใหม่';
            }
        }

        if (!$errors && !$assetId && !$empId && !$manualAd) {
            $errors['edit'] = 'ต้องระบุ Asset, พนักงาน (จากระบบค้นหา) หรือ User AD อย่างน้อยหนึ่งอย่าง';
        }
        if (!$errors && $instDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $instDate)) {
            $errors['edit'] = 'รูปแบบวันที่ไม่ถูกต้อง';
        }

        // หมายเหตุ: ไม่เช็ค seat limit ตรงนี้ — การแก้ไขไม่ได้เพิ่มจำนวนแถว
        // Installed ขึ้นใหม่ (ยังเป็น record เดิม 1 แถว) จึงไม่กระทบ total_seats

        // ตรวจซ้ำ — เหมือน 'add' ทุกจุด แต่ "ยกเว้นตัวเอง" (id != :editId) ออก
        // จากการเช็ค เพราะไม่งั้นจะชนกับ record ของตัวเองเสมอ
        if (!$errors && $assetId) {
            $dup = $pdo->prepare("SELECT id FROM software_allocation_map
                                  WHERE software_id=:sid AND asset_id=:aid AND status='Installed' AND id != :eid");
            $dup->execute([':sid' => $swId, ':aid' => $assetId, ':eid' => $editId]);
            if ($dup->fetchColumn()) {
                $errors['edit'] = 'อุปกรณ์นี้มี license นี้ติดตั้งอยู่แล้ว (ที่ record อื่น)';
            }
        }
        if (!$errors && $empId) {
            $dupEmp = $pdo->prepare("SELECT id FROM software_allocation_map
                                     WHERE software_id=:sid AND assigned_employee_id=:eid2 AND status='Installed' AND id != :eid");
            $dupEmp->execute([':sid' => $swId, ':eid2' => $empId, ':eid' => $editId]);
            if ($dupEmp->fetchColumn()) {
                $errors['edit'] = ($empDisplay ?: 'พนักงานคนนี้') . ' มี license นี้ allocate อยู่แล้ว (ที่ record อื่น)';
            }
        }
        if (!$errors && !$empId && $manualAd) {
            $dupUser = $pdo->prepare("SELECT id FROM software_allocation_map
                                      WHERE software_id=:sid AND assigned_user_ad=:uad AND status='Installed' AND id != :eid");
            $dupUser->execute([':sid' => $swId, ':uad' => $manualAd, ':eid' => $editId]);
            if ($dupUser->fetchColumn()) {
                $errors['edit'] = 'ผู้ใช้นี้มี license นี้ allocate อยู่แล้ว (ที่ record อื่น)';
            }
        }

        if (!$errors) {
            $pdo->prepare("
                UPDATE software_allocation_map
                SET asset_id = :aid, assigned_user_ad = :uad, assigned_employee_id = :eid,
                    install_date = :idt, notes = :notes
                WHERE id = :id AND software_id = :sid
            ")->execute([
                ':aid'   => $assetId,
                ':uad'   => $userAd,
                ':eid'   => $empId,
                ':idt'   => $instDate,
                ':notes' => $notes,
                ':id'    => $editId,
                ':sid'   => $swId,
            ]);

            // ── History log — log เฉพาะตอน "พนักงานที่ผูกอยู่" เปลี่ยนไปจริงๆ
            //    (เหมือน pattern Transfer ของ hardware/mobile — log ทั้ง 2 ฝั่ง) ──
            $oldEmpId = $editRow['assigned_employee_id'] !== null ? (int)$editRow['assigned_employee_id'] : null;
            if ($empId !== $oldEmpId) {
                $currentUser = iam_user();
                $performedBy = $currentUser['username'] ?? null;
                if ($oldEmpId !== null) {
                    logEmployeeTransaction($pdo, $oldEmpId, 'App Revoke', 'software_allocation_map', $editId,
                        'Edit: reassigned away — ' . $license['software_name'], $performedBy);
                }
                if ($empId !== null) {
                    logEmployeeTransaction($pdo, $empId, 'App Assign', 'software_allocation_map', $editId,
                        'Edit: assigned to you — ' . $license['software_name'], $performedBy);
                }
            }

            $_SESSION['flash'] = ['type'=>'success','message'=>'แก้ไข allocation เรียบร้อย'];
            header("Location: /it-asset-manager/software/allocate.php?id={$swId}");
            exit;
        }
    }

    if ($action === 'uninstall') {
        $allocId = isset($_POST['alloc_id']) && ctype_digit((string)$_POST['alloc_id']) ? (int)$_POST['alloc_id'] : 0;
        if ($allocId > 0) {
            // ดึง employee link ไว้ก่อน UPDATE เพราะ UPDATE ไม่คืนค่าเดิมกลับมา
            $preStmt = $pdo->prepare("SELECT assigned_employee_id FROM software_allocation_map WHERE id=:id AND software_id=:sid");
            $preStmt->execute([':id' => $allocId, ':sid' => $swId]);
            $preEmpId = $preStmt->fetchColumn();

            $stmt = $pdo->prepare("UPDATE software_allocation_map
                                   SET status='Uninstalled', uninstall_date=:udt
                                   WHERE id=:id AND software_id=:sid");
            $stmt->execute([':udt' => date('Y-m-d'), ':id'=>$allocId, ':sid'=>$swId]);

            if ($stmt->rowCount() > 0 && $preEmpId !== false && $preEmpId !== null) {
                $currentUser = iam_user();
                logEmployeeTransaction(
                    $pdo,
                    (int)$preEmpId,
                    'App Revoke',
                    'software_allocation_map',
                    $allocId,
                    'Uninstall ' . $license['software_name'],
                    $currentUser['username'] ?? null
                );
            }

            $_SESSION['flash'] = ['type'=>'success','message'=>'ปรับสถานะเป็น Uninstalled แล้ว'];
        }
        header("Location: /it-asset-manager/software/allocate.php?id={$swId}");
        exit;
    }

    if ($action === 'delete') {
        $allocId = isset($_POST['alloc_id']) && ctype_digit((string)$_POST['alloc_id']) ? (int)$_POST['alloc_id'] : 0;
        if ($allocId > 0) {
            $stmt = $pdo->prepare("DELETE FROM software_allocation_map WHERE id=:id AND software_id=:sid");
            $stmt->execute([':id'=>$allocId, ':sid'=>$swId]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'ลบ allocation เรียบร้อย'];
        }
        header("Location: /it-asset-manager/software/allocate.php?id={$swId}");
        exit;
    }
}

// -----------------------------------------------------------------------------
//  Read data
// -----------------------------------------------------------------------------
$allocations = $pdo->prepare("
    SELECT m.*, h.asset_id AS asset_code, h.brand, h.model, s.site_name
    FROM software_allocation_map m
    LEFT JOIN hardware_assets h ON h.id = m.asset_id
    LEFT JOIN sites s          ON s.id = h.site_id
    WHERE m.software_id = :id
    ORDER BY m.status ASC, m.install_date DESC, m.id DESC
");
$allocations->execute([':id' => $swId]);
$allocations = $allocations->fetchAll();

$usedNow = 0;
foreach ($allocations as $r) if ($r['status'] === 'Installed') $usedNow++;
$available = max(0, (int)$license['total_seats'] - $usedNow);

// ----- ดึงชื่อพนักงานมาต่อในระดับ PHP (cross-DB join ทำไม่ได้) -----
$empIds = array_values(array_unique(array_filter(array_column($allocations, 'assigned_employee_id'))));
$empMap = [];
if ($empIds) {
    $placeholders = implode(',', array_fill(0, count($empIds), '?'));
    $empStmt = employee_db()->prepare("
        SELECT id, person_code, title, first_name, last_name, department
        FROM employees
        WHERE id IN ({$placeholders})
    ");
    $empStmt->execute($empIds);
    foreach ($empStmt->fetchAll() as $e) {
        $empMap[(int)$e['id']] = [
            'name' => trim($e['title'] . ' ' . $e['first_name'] . ' ' . $e['last_name']),
            'sub'  => $e['person_code'] . ' · ' . $e['department'],
        ];
    }
}

// ----- Prefill labels สำหรับโหมดแก้ไข (แสดงชื่อพนักงาน/asset ปัจจุบันในช่องค้นหา) -----
$editEmpLabel   = null;
$editAssetLabel = null;
if ($editId > 0 && $editRow) {
    if ($editRow['assigned_employee_id']) {
        $editEmpLabel = $empMap[(int)$editRow['assigned_employee_id']]['name'] ?? null;
        if ($editEmpLabel === null) {
            // ไม่อยู่ใน $empMap ที่ build จาก $allocations (ควรจะอยู่แล้วเพราะเป็น
            // allocation เดียวกัน) กันไว้เผื่อ query แยกอีกที
            $eStmt = employee_db()->prepare("SELECT title, first_name, last_name FROM employees WHERE id = :id");
            $eStmt->execute([':id' => (int)$editRow['assigned_employee_id']]);
            if ($e = $eStmt->fetch()) {
                $editEmpLabel = trim($e['title'] . ' ' . $e['first_name'] . ' ' . $e['last_name']);
            }
        }
    }
    if ($editRow['asset_id']) {
        $aStmt = $pdo->prepare("SELECT asset_id, brand, model FROM hardware_assets WHERE id = :id");
        $aStmt->execute([':id' => (int)$editRow['asset_id']]);
        if ($a = $aStmt->fetch()) {
            $editAssetLabel = $a['asset_id'];
        }
    }
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title  = 'Allocations · ' . $license['software_name'];
$active_menu = 'software';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/software/index.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับไปหน้า Software
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($license['software_name']) ?></h2>
    <div class="text-muted small">
        <?= e($license['software_id']) ?> · <?= e($license['publisher']) ?>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- ========== seat summary ========== -->
<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card metric-card metric-blue">
            <div class="card-body">
                <div class="metric-label">Total Seats</div>
                <div class="metric-value"><?= number_format((int)$license['total_seats']) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card metric-card metric-amber">
            <div class="card-body">
                <div class="metric-label">Used</div>
                <div class="metric-value"><?= number_format($usedNow) ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card metric-card metric-teal">
            <div class="card-body">
                <div class="metric-label">Available</div>
                <div class="metric-value"><?= number_format($available) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ========== Add/Edit allocation form ========== -->
<div class="card mb-4">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <strong><?= $editId > 0 ? 'แก้ไข Allocation' : 'เพิ่ม Allocation' ?></strong>
        <?php if ($editId > 0): ?>
            <a href="/it-asset-manager/software/allocate.php?id=<?= e($swId) ?>" class="small text-decoration-none">
                <i class="bi bi-x-lg"></i> ยกเลิกแก้ไข
            </a>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php $formError = $errors['edit'] ?? $errors['add'] ?? null; ?>
        <?php if ($formError): ?>
            <div class="alert alert-danger py-2 small"><?= e($formError) ?></div>
        <?php endif; ?>
        <form method="post" id="allocForm">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="<?= $editId > 0 ? 'update' : 'add' ?>">

            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small text-muted mb-1">ค้นหาพนักงาน (จากระบบ HR)</label>
                    <input type="text" id="empSearchInput" class="form-control form-control-sm"
                           placeholder="พิมพ์ชื่อ นามสกุล หรือรหัสพนักงาน" autocomplete="off"
                           value="<?= e($editEmpLabel ?? '') ?>">
                    <div id="empSearchResults" class="list-group mt-1"></div>
                    <input type="hidden" name="assigned_employee_id" id="empSelectedId"
                           value="<?= $editRow ? e($editRow['assigned_employee_id'] ?? '') : '' ?>">
                    <div class="small mt-1">
                        <span id="empSelectedLabel" class="fw-semibold text-success">
                            <?= $editEmpLabel ? 'เลือกแล้ว: ' . e($editEmpLabel) : '' ?>
                        </span>
                        <a href="#" id="empClearBtn" class="text-danger ms-2" style="<?= $editEmpLabel ? '' : 'display:none;' ?>">ล้างค่า</a>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label small text-muted mb-1">หรือ User AD กรอกเอง (กรณีไม่มีในระบบ HR เช่น contractor/vendor)</label>
                    <input type="text" name="assigned_user_ad" id="manualAdInput" maxlength="100"
                           class="form-control form-control-sm" placeholder="somchai.p"
                           value="<?= e($editRow['assigned_user_ad'] ?? '') ?>"
                           <?= $editEmpLabel ? 'readonly' : '' ?>>
                </div>
            </div>

            <div class="row g-2 mb-3">
                <div class="col-md-8">
                    <label class="form-label small text-muted mb-1">Hardware Asset</label>
                    <input type="text" id="assetSearchInput" class="form-control form-control-sm"
                           placeholder="พิมพ์ Asset ID, ยี่ห้อ หรือรุ่น" autocomplete="off"
                           value="<?= e($editAssetLabel ?? '') ?>">
                    <div id="assetSearchResults" class="list-group mt-1"></div>
                    <input type="hidden" name="asset_id" id="assetSelectedId"
                           value="<?= $editRow ? e($editRow['asset_id'] ?? '') : '' ?>">
                    <div class="small mt-1">
                        <span id="assetSelectedLabel" class="fw-semibold text-success">
                            <?= $editAssetLabel ? 'เลือกแล้ว: ' . e($editAssetLabel) : '' ?>
                        </span>
                        <a href="#" id="assetClearBtn" class="text-danger ms-2" style="<?= $editAssetLabel ? '' : 'display:none;' ?>">ล้างค่า</a>
                    </div>
                </div>
            </div>

            <div class="row g-2">
                <div class="col-md-3">
                    <label class="form-label small text-muted mb-1">Install Date</label>
                    <input type="date" name="install_date" class="form-control form-control-sm"
                           value="<?= e($editRow['install_date'] ?? date('Y-m-d')) ?>">
                </div>
                <div class="col-md-5">
                    <label class="form-label small text-muted mb-1">Notes</label>
                    <input type="text" name="notes" maxlength="200" class="form-control form-control-sm"
                           value="<?= e($editRow['notes'] ?? '') ?>">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button class="btn btn-sm <?= $editId > 0 ? 'btn-warning' : 'btn-primary' ?> w-100"
                            <?= ($editId === 0 && $available <= 0) ? 'disabled' : '' ?>>
                        <i class="bi <?= $editId > 0 ? 'bi-check-lg' : 'bi-plus-lg' ?>"></i>
                        <?= $editId > 0 ? 'บันทึกการแก้ไข' : 'เพิ่ม Allocation' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- ========== Allocation list ========== -->
<div class="card">
    <div class="card-header bg-white"><strong>Allocations</strong></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Asset</th>
                    <th>Site</th>
                    <th>ผู้ใช้งาน</th>
                    <th>Install Date</th>
                    <th>Uninstall Date</th>
                    <th>Status</th>
                    <th>By</th>
                    <th>Notes</th>
                    <th class="text-end" style="width:130px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$allocations): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">ยังไม่มี allocation</td></tr>
            <?php else: foreach ($allocations as $r):
                $st = $r['status'] === 'Installed' ? 'text-bg-success' : 'text-bg-secondary';
                $empId = $r['assigned_employee_id'] !== null ? (int)$r['assigned_employee_id'] : null;
                $empInfo = $empId !== null ? ($empMap[$empId] ?? null) : null;
            ?>
                <tr>
                    <td>
                        <?php if ($r['asset_code']): ?>
                            <div class="fw-semibold"><?= e($r['asset_code']) ?></div>
                            <div class="text-muted small"><?= e($r['brand']) ?> <?= e($r['model']) ?></div>
                        <?php else: ?>
                            <span class="text-muted small">— ไม่ผูก asset —</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted small"><?= e($r['site_name'] ?? '—') ?></td>
                    <td>
                        <?php if ($empInfo): ?>
                            <div class="fw-semibold"><?= e($empInfo['name']) ?></div>
                            <div class="text-muted small"><?= e($empInfo['sub']) ?></div>
                        <?php elseif ($empId !== null): ?>
                            <span class="text-muted small">พนักงาน ID <?= e($empId) ?> (ไม่พบข้อมูล/resign แล้ว)</span>
                        <?php else: ?>
                            <?= e($r['assigned_user_ad'] ?: '—') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= e($r['install_date'] ?: '—') ?></td>
                    <td class="text-muted small"><?= e($r['uninstall_date'] ?: '—') ?></td>
                    <td><span class="badge <?= $st ?>"><?= e($r['status']) ?></span></td>
                    <td class="text-muted small"><?= e($r['created_by'] ?: '—') ?></td>
                    <td class="text-muted small"><?= e($r['notes'] ?: '') ?></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="/it-asset-manager/software/allocate.php?id=<?= e($swId) ?>&edit=<?= e($r['id']) ?>"
                               class="btn btn-outline-secondary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if ($r['status'] === 'Installed'): ?>
                                <form action="" method="post" style="display:contents"
                                      data-confirm="เปลี่ยนสถานะเป็น Uninstalled?">
                                    <?php csrf_field(); ?>
                                    <input type="hidden" name="action" value="uninstall">
                                    <input type="hidden" name="alloc_id" value="<?= e($r['id']) ?>">
                                    <button class="btn btn-outline-warning" title="Uninstall">
                                        <i class="bi bi-arrow-down-square"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <form action="" method="post" style="display:contents" data-confirm="ลบ allocation นี้?">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="alloc_id" value="<?= e($r['id']) ?>">
                                <button class="btn btn-outline-danger" title="Delete">
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

<script>
// ------ Employee live search (pattern เดียวกับ access-requests/form.php) ------
const empInput  = document.getElementById('empSearchInput');
const empResult = document.getElementById('empSearchResults');
const empHidden = document.getElementById('empSelectedId');
const empLabel  = document.getElementById('empSelectedLabel');
const empClear  = document.getElementById('empClearBtn');
const manualAd  = document.getElementById('manualAdInput');

let empTimer = null;
empInput.addEventListener('input', () => {
    clearTimeout(empTimer);
    const q = empInput.value.trim();
    empResult.innerHTML = '';
    if (q.length < 2) return;
    empTimer = setTimeout(() => {
        fetch('/it-asset-manager/software/employee_search_ajax.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(list => {
                empResult.innerHTML = '';
                list.forEach(item => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action';
                    btn.innerHTML = '<div class="fw-semibold">' + item.label + '</div>'
                        + '<div class="text-muted small">' + item.sub + '</div>';
                    btn.addEventListener('click', () => {
                        empHidden.value = item.id;
                        empLabel.textContent = 'เลือกแล้ว: ' + item.label;
                        empResult.innerHTML = '';
                        empInput.value = item.label;

                        // auto-fill ให้เห็นว่าจะบันทึกเป็น person_code อะไร (ค่าจริงเซิร์ฟเวอร์ยืนยันเองอีกที)
                        manualAd.value = item.person_code;
                        manualAd.readOnly = true;
                        empClear.style.display = '';
                    });
                    empResult.appendChild(btn);
                });
            });
    }, 300); // debounce กันยิง request ถี่เกินไป
});

empClear.addEventListener('click', (ev) => {
    ev.preventDefault();
    empHidden.value = '';
    empLabel.textContent = '';
    empInput.value = '';
    manualAd.value = '';
    manualAd.readOnly = false;
    empClear.style.display = 'none';
});

// ------ Hardware Asset live search (pattern เดียวกับด้านบน) ------
const assetInput  = document.getElementById('assetSearchInput');
const assetResult = document.getElementById('assetSearchResults');
const assetHidden = document.getElementById('assetSelectedId');
const assetLabel  = document.getElementById('assetSelectedLabel');
const assetClear  = document.getElementById('assetClearBtn');

let assetTimer = null;
assetInput.addEventListener('input', () => {
    clearTimeout(assetTimer);
    const q = assetInput.value.trim();
    assetResult.innerHTML = '';
    if (q.length < 2) return;
    assetTimer = setTimeout(() => {
        fetch('/it-asset-manager/software/asset_search_ajax.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(list => {
                assetResult.innerHTML = '';
                list.forEach(item => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action';
                    btn.innerHTML = '<div class="fw-semibold">' + item.label + '</div>'
                        + '<div class="text-muted small">' + item.sub + '</div>';
                    btn.addEventListener('click', () => {
                        assetHidden.value = item.id;
                        assetLabel.textContent = 'เลือกแล้ว: ' + item.label;
                        assetResult.innerHTML = '';
                        assetInput.value = item.label;
                        assetClear.style.display = '';
                    });
                    assetResult.appendChild(btn);
                });
            });
    }, 300);
});

assetClear.addEventListener('click', (ev) => {
    ev.preventDefault();
    assetHidden.value = '';
    assetLabel.textContent = '';
    assetInput.value = '';
    assetClear.style.display = 'none';
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
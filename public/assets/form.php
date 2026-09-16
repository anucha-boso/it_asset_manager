<?php
/**
 * Hardware Asset — Add / Edit
 * /var/www/lab/it-asset-manager/assets/form.php
 *
 * Sections:
 *   A  ข้อมูลทั่วไป   (asset_id, category, department, site, location, user, register_date)
 *   B  Hardware Specs  (brand, model, serial, service_tag, cpu, ram_gb, storage, gpu, warranty)
 *   C  Network         (ip, ip_assignment, mac_address, mac_wifi)
 *   D  OS & License    (os_name, os_bit, os_product_key, office_license, office_product_key)
 *   E  Peripherals     (Monitor×2, Mouse, KB, Adapter, Bag)
 *   F  สถานะ           (status, is_loanable, loan_pool_name, purchase_date, notes)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_role(['it_admin','it_staff']);



require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/employee_log.php';
$pdo = db();

/* ── ID / mode ────────────────────────────────────────────────── */
$id     = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$errors = [];
$empId  = null; // จะถูกกำหนดค่าจาก DB (โหมดแก้ไข) หรือจาก POST (ตอนบันทึก) ด้านล่าง
$oldEmpId = null; // ค่า assigned_employee_id เดิมก่อนบันทึก — ใช้เทียบว่าเป็น
                  // Assign/Transfer/Return สำหรับ history log (เฉพาะโหมดแก้ไข)

/* ── Default row ──────────────────────────────────────────────── */
$row = [
    /* A */
    'asset_id'          => '',
    'category'          => 'PC',
    'department'        => '',
    'site_id'           => '',
    'location'  => '',
    'assigned_to_ad'    => '',
    'assigned_employee_id' => '',
    'user_display'      => '',
    'register_date'     => '',
    /* B */
    'brand'             => '',
    'model'             => '',
    'serial_number'     => '',
    'service_tag'       => '',
    'cpu'               => '',
    'ram_gb'            => '',
    'storage'           => '',
    'gpu'               => '',
    'warranty_expiry'   => '',
    'purchase_date'     => '',
    /* C */
    'ip_address'        => '',
    'ip_assignment'     => 'DHCP',
    'mac_address'       => '',
    'mac_wifi'          => '',
    /* D */
    'os_name'           => '',
    'os_bit'            => '64',
    'os_product_key'    => '',
    'office_license'    => '',
    'office_product_key'=> '',
    /* F */
    'status'            => 'Active',
    'is_loanable'       => 0,
    'loan_pool_name'    => '',
    'specifications'    => '',
];

/* ── Load existing record (edit mode) ────────────────────────── */
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM hardware_assets WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) { http_response_code(404); exit('Asset not found'); }
    $row = array_merge($row, $existing);
    if ($row['assigned_employee_id'] !== null && $row['assigned_employee_id'] !== '') {
        $empId = (int)$row['assigned_employee_id'];
        $oldEmpId = $empId; // เก็บค่าเดิมไว้เทียบหลัง POST (ตัวแปร $empId จะถูก
                            // เขียนทับด้วยค่าใหม่จากฟอร์มตอนประมวลผล POST ด้านล่าง)
    }

    /* Load peripherals */
    $periStmt = $pdo->prepare(
        "SELECT * FROM asset_peripherals WHERE asset_id = :aid ORDER BY type, slot"
    );
    $periStmt->execute([':aid' => $id]);
    $peripherals = $periStmt->fetchAll();
    /* index by type-slot */
    $periMap = [];
    foreach ($peripherals as $p) {
        $periMap[$p['type'] . '_' . $p['slot']] = $p;
    }
} else {
    $periMap = [];
}

/* ── Reference data ───────────────────────────────────────────── */
$sites = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();

$categories   = ['PC','Notebook','Surface','Server','NAS',
                  'Printer','Scanner','UPS','Handheld','Tablet',
                  'PrintServer','Other'];
$departments  = ['IT','HR','MK','WH','CS','EN','AC','PLP','QA','PC','ส่วนกลาง'];
$statuses     = ['Active','In Repair','In Stock','Retired','On Loan','Reserved'];
$osOptions    = ['Windows 10','Windows 11','Windows Server 2016',
                  'Windows Server 2019','Windows Server 2022','Linux','macOS'];
$officeLics   = ['OEM','VL(OLP)','365','Perpetual','N/A'];
$periTypes    = [
    'Monitor_1'  => 'Monitor 1 S/N',
    'Monitor_2'  => 'Monitor 2 S/N',
    'Mouse_1'    => 'Mouse S/N',
    'Keyboard_1' => 'Keyboard S/N',
    'Adapter_1'  => 'Adapter S/N',
    'Bag_1'      => 'Bag S/N',
];

/* ── POST handler ─────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    /* collect all scalar fields */
    foreach (array_keys($row) as $k) {
        $row[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $row['is_loanable'] = isset($_POST['is_loanable']) ? 1 : 0;

    /* ── Employee link (จากระบบ HR search) ─────────────────────────
       ถ้ามีการเลือกพนักงานจากช่องค้นหา (assigned_employee_id ถูกส่งมา)
       เซิร์ฟเวอร์จะ query cc_central_employee_db เพื่อยืนยันตัวตนเอง
       เสมอ แล้ว "เขียนทับ" เฉพาะ user_display (ชื่อ-นามสกุลที่แสดงผล)
       ด้วยข้อมูลจริงจาก HR — ไม่เชื่อค่าที่ client ส่งมาตรงๆ (กัน tamper
       ผ่าน DevTools) ส่วน assigned_to_ad (Windows AD username) ไม่แตะ
       เลย เป็นคนละความหมายกับ employee_id/person_code โดยเจตนา — กรอก
       อิสระต่อไปตามที่ผู้ใช้พิมพ์เอง
       ถ้าไม่ได้เลือกพนักงาน (เช่น เครื่องส่วนกลาง/ยังไม่มีคนใช้เฉพาะ)
       user_display จะเป็นค่าที่พิมพ์เองตามปกติ และ assigned_employee_id
       จะถูกเก็บเป็น NULL ──────────────────────────────────────────── */
    $empId = ctype_digit((string)$row['assigned_employee_id']) && $row['assigned_employee_id'] !== ''
        ? (int)$row['assigned_employee_id'] : null;

    if ($empId !== null) {
        $empStmt = employee_db()->prepare("
            SELECT id, person_code, title, first_name, last_name
            FROM employees
            WHERE id = :id AND resign_status = 'Active'
        ");
        $empStmt->execute([':id' => $empId]);
        $emp = $empStmt->fetch();
        if (!$emp) {
            $errors['assigned_employee_id'] = 'ไม่พบพนักงานที่เลือก หรือพนักงานไม่ active แล้ว กรุณาค้นหาใหม่';
        } else {
            /* หมายเหตุ: assigned_to_ad ไม่แตะเลย — เป็น Windows AD username จริง
               คนละความหมายกับ person_code ของพนักงาน ปล่อยให้กรอกอิสระต่อไป
               (ยังใช้จริงใน assets/index.php สำหรับค้นหา/แสดงผล และ import.php) */
            $row['user_display']   = trim($emp['title'] . ' ' . $emp['first_name'] . ' ' . $emp['last_name']);
        }
    }

    /* ── Validation ── */
    if ($row['asset_id'] === '')
        $errors['asset_id'] = 'จำเป็นต้องระบุรหัสอุปกรณ์';

    if (!in_array($row['category'], $categories, true))
        $errors['category'] = 'Category ไม่ถูกต้อง';

    if (!in_array($row['status'], $statuses, true))
        $errors['status'] = 'Status ไม่ถูกต้อง';

    /* date fields */
    foreach (['register_date','purchase_date','warranty_expiry'] as $df) {
        if ($row[$df] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$df]))
            $errors[$df] = 'รูปแบบวันที่ไม่ถูกต้อง';
    }

    /* numeric */
    if ($row['ram_gb'] !== '' && !ctype_digit($row['ram_gb']))
        $errors['ram_gb'] = 'ต้องเป็นตัวเลข';

    /* site FK */
    if ($row['site_id'] !== '' && !ctype_digit($row['site_id']))
        $errors['site_id'] = 'Site ไม่ถูกต้อง';

    /* unique asset_id */
    if (!isset($errors['asset_id'])) {
        $uq = $pdo->prepare(
            "SELECT id FROM hardware_assets WHERE asset_id = :aid" .
            ($isEdit ? " AND id <> :id" : "")
        );
        $uq->bindValue(':aid', $row['asset_id']);
        if ($isEdit) $uq->bindValue(':id', $id, PDO::PARAM_INT);
        $uq->execute();
        if ($uq->fetchColumn())
            $errors['asset_id'] = 'รหัสอุปกรณ์นี้มีอยู่แล้ว';
    }

    /* ── Save ── */
    if (!$errors) {
        $nullables = ['department','site_id','location','assigned_to_ad',
                      'user_display','register_date','brand','model','serial_number',
                      'service_tag','cpu','ram_gb','storage','gpu','warranty_expiry',
                      'purchase_date','ip_address','ip_assignment','mac_address',
                      'mac_wifi','os_name','os_bit','os_product_key','office_license',
                      'office_product_key','loan_pool_name','specifications'];

        /* build bind — เฉพาะ key ที่ใช้ใน SQL เท่านั้น */
        $sqlFields = [
            'asset_id','category','department','site_id','location',
            'assigned_to_ad','assigned_employee_id','user_display','register_date',
            'brand','model','serial_number','service_tag',
            'cpu','ram_gb','storage','gpu',
            'warranty_expiry','purchase_date',
            'ip_address','ip_assignment','mac_address','mac_wifi',
            'os_name','os_bit','os_product_key',
            'office_license','office_product_key',
            'status','is_loanable','loan_pool_name','specifications',
        ];
        $bind = [];
        foreach ($sqlFields as $k) {
            $bind[$k] = $row[$k] ?? null;
        }
        foreach ($nullables as $k) {
            if (isset($bind[$k]) && $bind[$k] === '') $bind[$k] = null;
        }
        $bind['is_loanable'] = $row['is_loanable'];
        if (!empty($bind['ram_gb'])) $bind['ram_gb'] = (int)$bind['ram_gb'];
        if (!empty($bind['site_id'])) $bind['site_id'] = (int)$bind['site_id'];
        /* assigned_employee_id: int หรือ NULL เท่านั้น — มาจาก $empId ที่ผ่านการ
           ยืนยันกับ employee_db() แล้วด้านบน ไม่ใช้ค่า $row ตรงๆ */
        $bind['assigned_employee_id'] = $empId;

        try {
            $pdo->beginTransaction();

            if ($isEdit) {
                $sql = "UPDATE hardware_assets SET
                    asset_id=:asset_id, category=:category, department=:department,
                    site_id=:site_id, location=:location,
                    assigned_to_ad=:assigned_to_ad, assigned_employee_id=:assigned_employee_id,
                    user_display=:user_display,
                    register_date=:register_date,
                    brand=:brand, model=:model, serial_number=:serial_number,
                    service_tag=:service_tag, cpu=:cpu, ram_gb=:ram_gb,
                    storage=:storage, gpu=:gpu,
                    warranty_expiry=:warranty_expiry, purchase_date=:purchase_date,
                    ip_address=:ip_address, ip_assignment=:ip_assignment,
                    mac_address=:mac_address, mac_wifi=:mac_wifi,
                    os_name=:os_name, os_bit=:os_bit,
                    os_product_key=:os_product_key,
                    office_license=:office_license,
                    office_product_key=:office_product_key,
                    status=:status, is_loanable=:is_loanable,
                    loan_pool_name=:loan_pool_name,
                    specifications=:specifications
                WHERE id=:id";
                $bind[':id'] = $id;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bind);
            } else {
                $sql = "INSERT INTO hardware_assets
                    (asset_id, category, department, site_id, location,
                     assigned_to_ad, assigned_employee_id, user_display, register_date,
                     brand, model, serial_number, service_tag,
                     cpu, ram_gb, storage, gpu,
                     warranty_expiry, purchase_date,
                     ip_address, ip_assignment, mac_address, mac_wifi,
                     os_name, os_bit, os_product_key,
                     office_license, office_product_key,
                     status, is_loanable, loan_pool_name, specifications)
                VALUES
                    (:asset_id, :category, :department, :site_id, :location,
                     :assigned_to_ad, :assigned_employee_id, :user_display, :register_date,
                     :brand, :model, :serial_number, :service_tag,
                     :cpu, :ram_gb, :storage, :gpu,
                     :warranty_expiry, :purchase_date,
                     :ip_address, :ip_assignment, :mac_address, :mac_wifi,
                     :os_name, :os_bit, :os_product_key,
                     :office_license, :office_product_key,
                     :status, :is_loanable, :loan_pool_name, :specifications)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($bind);
                $id = (int)$pdo->lastInsertId();
            }

            /* ── Peripherals: delete all → re-insert ── */
            $pdo->prepare("DELETE FROM asset_peripherals WHERE asset_id = :aid")
                ->execute([':aid' => $id]);

            $periInsert = $pdo->prepare(
                "INSERT INTO asset_peripherals (asset_id, type, slot, serial_number)
                 VALUES (:aid, :type, :slot, :sn)"
            );
            $periDefs = [
                ['Monitor',  1, 'sn_monitor_1'],
                ['Monitor',  2, 'sn_monitor_2'],
                ['Mouse',    1, 'sn_mouse'],
                ['Keyboard', 1, 'sn_keyboard'],
                ['Adapter',  1, 'sn_adapter'],
                ['Bag',      1, 'sn_bag'],
            ];
            foreach ($periDefs as [$type, $slot, $postKey]) {
                $sn = trim((string)($_POST[$postKey] ?? ''));
                if ($sn !== '') {
                    $periInsert->execute([
                        ':aid'  => $id,
                        ':type' => $type,
                        ':slot' => $slot,
                        ':sn'   => $sn,
                    ]);
                }
            }

            $pdo->commit();

            // ── History log (เฟส 1) — เทียบค่า employee เดิม vs ใหม่ ──────────
            //    - เดิมไม่มี ใหม่มี  → Asset Assign
            //    - เดิมมี ใหม่มีคนละคน → Asset Transfer
            //    - เดิมมี ใหม่ไม่มี  → Asset Return
            //    - เดิม/ใหม่เหมือนกัน (รวมถึงไม่มีทั้งคู่) → ไม่ log อะไร
            if ($empId !== $oldEmpId) {
                $assetLabel = trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? '')) . ' (' . $row['asset_id'] . ')';
                $currentUser = iam_user();
                $performedBy = $currentUser['username'] ?? null;

                if ($oldEmpId === null && $empId !== null) {
                    logEmployeeTransaction($pdo, $empId, 'Asset Assign', 'hardware_assets', $id,
                        'Assign ' . $assetLabel, $performedBy);
                } elseif ($oldEmpId !== null && $empId !== null) {
                    // Transfer ต้อง log ทั้ง 2 ฝั่ง — คนใหม่ได้รับ, คนเก่าถูกโอนออก
                    // (เดิม log แค่ฝั่งคนใหม่ ทำให้ประวัติของคนเก่าดูเหมือนไม่มี
                    //  อะไรเกิดขึ้นกับ asset ตัวนี้เลย ทั้งที่เพิ่งเสียมันไป)
                    logEmployeeTransaction($pdo, $empId, 'Asset Transfer', 'hardware_assets', $id,
                        'Transfer ' . $assetLabel . ' (received)', $performedBy);
                    logEmployeeTransaction($pdo, $oldEmpId, 'Asset Transfer', 'hardware_assets', $id,
                        'Transfer ' . $assetLabel . ' (transferred away)', $performedBy);
                } elseif ($oldEmpId !== null && $empId === null) {
                    logEmployeeTransaction($pdo, $oldEmpId, 'Asset Return', 'hardware_assets', $id,
                        'Return ' . $assetLabel, $performedBy);
                }
            }

            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => $isEdit ? 'บันทึกการแก้ไขเรียบร้อย' : 'เพิ่ม Asset เรียบร้อย',
            ];
            header('Location: /it-asset-manager/assets/index.php');
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[ASSET SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }

    /* re-populate periMap from POST on validation fail */
    $periMap = [];
    $periKeys = [
        'Monitor_1'  => 'sn_monitor_1',
        'Monitor_2'  => 'sn_monitor_2',
        'Mouse_1'    => 'sn_mouse',
        'Keyboard_1' => 'sn_keyboard',
        'Adapter_1'  => 'sn_adapter',
        'Bag_1'      => 'sn_bag',
    ];
    foreach ($periKeys as $mapKey => $postKey) {
        $sn = trim((string)($_POST[$postKey] ?? ''));
        if ($sn !== '') $periMap[$mapKey] = ['serial_number' => $sn];
    }
}

/* helper: get peripheral S/N from map */
$periSN = function(string $key) use ($periMap): string {
    return $periMap[$key]['serial_number'] ?? '';
};

$page_title  = $isEdit ? 'Edit Asset · ' . htmlspecialchars((string)($row['asset_id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'Add Hardware Asset';
$active_menu = 'hardware';
require __DIR__ . '/../../includes/header.php';
?>

<!-- ── Breadcrumb ─────────────────────────────────────────────── -->
<div class="mb-3">
    <a href="/it-asset-manager/assets/index.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับรายการ Hardware
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($page_title) ?></h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<form method="post" novalidate>
<?php csrf_field(); ?>

<!-- ════════════════════════════════════════════════════════
     SECTION A — ข้อมูลทั่วไป
     ════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-primary rounded-pill">A</span>
        <strong>ข้อมูลทั่วไป</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <div class="col-md-4">
                <label class="form-label">รหัสอุปกรณ์ <span class="text-danger">*</span></label>
                <input type="text" name="asset_id" maxlength="30"
                       value="<?= e($row['asset_id']) ?>"
                       class="form-control font-monospace <?= isset($errors['asset_id']) ? 'is-invalid' : '' ?>"
                       placeholder="CS-65-002-PCSIT">
                <?php if (isset($errors['asset_id'])): ?>
                    <div class="invalid-feedback"><?= e($errors['asset_id']) ?></div>
                <?php endif; ?>
                <div class="form-text">รูปแบบ: TYPE-YY-NNN-DEPT</div>
            </div>

            <div class="col-md-4">
                <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                <select name="category" class="form-select <?= isset($errors['category']) ? 'is-invalid' : '' ?>">
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c) ?>" <?= $row['category'] === $c ? 'selected' : '' ?>>
                            <?= e($c) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4">
                <label class="form-label">หน่วยงาน</label>
                <select name="department" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= e($d) ?>" <?= $row['department'] === $d ? 'selected' : '' ?>>
                            <?= e($d) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Site</label>
                <select name="site_id" class="form-select <?= isset($errors['site_id']) ? 'is-invalid' : '' ?>">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= e($s['id']) ?>"
                            <?= (string)$row['site_id'] === (string)$s['id'] ? 'selected' : '' ?>>
                            <?= e($s['site_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Location</label>
                <input type="text" name="location" maxlength="200"
                       value="<?= e($row['location']) ?>"
                       class="form-control" placeholder="Office B8 / ห้อง Server / ลานโหลด อ.6">
            </div>

            <div class="col-md-6">
                <label class="form-label">ค้นหาพนักงานผู้ใช้งาน (จากระบบ HR)</label>
                <input type="text" id="empSearchInput" class="form-control"
                       placeholder="พิมพ์ชื่อ นามสกุล หรือรหัสพนักงาน" autocomplete="off"
                       value="<?= $empId ? e($row['user_display']) : '' ?>">
                <div id="empSearchResults" class="list-group mt-1"></div>
                <input type="hidden" name="assigned_employee_id" id="empSelectedId"
                       value="<?= e($row['assigned_employee_id']) ?>">
                <div class="small mt-1">
                    <span id="empSelectedLabel" class="fw-semibold text-success">
                        <?= $empId ? 'เลือกแล้ว: ' . e($row['user_display']) : '' ?>
                    </span>
                    <a href="#" id="empClearBtn" class="text-danger ms-2"
                       style="<?= $empId ? '' : 'display:none;' ?>">ล้างค่า</a>
                </div>
                <?php if (isset($errors['assigned_employee_id'])): ?>
                    <div class="text-danger small mt-1"><?= e($errors['assigned_employee_id']) ?></div>
                <?php endif; ?>
                <div class="form-text">ถ้าเป็นเครื่องส่วนกลาง/ยังไม่มีผู้ใช้เฉพาะ ไม่ต้องเลือก — กรอกช่องขวาแทน</div>
            </div>

            <div class="col-md-3">
                <label class="form-label">ชื่อผู้ใช้งาน</label>
                <input type="text" name="user_display" id="userDisplayInput" maxlength="150"
                       value="<?= e($row['user_display']) ?>"
                       class="form-control" placeholder="อภิวรรณ / ส่วนกลาง" <?= $empId ? 'readonly' : '' ?>>
            </div>

            <div class="col-md-3">
                <label class="form-label">
                    AD Username
                    <span class="text-muted small">(Windows login — ไม่เกี่ยวกับ ID พนักงาน)</span>
                </label>
                <input type="text" name="assigned_to_ad" id="assignedAdInput" maxlength="100"
                       value="<?= e($row['assigned_to_ad']) ?>"
                       class="form-control" placeholder="anucha.u">
            </div>

            <div class="col-md-4">
                <label class="form-label">วันที่ลงทะเบียน</label>
                <input type="date" name="register_date"
                       value="<?= e($row['register_date']) ?>"
                       class="form-control <?= isset($errors['register_date']) ? 'is-invalid' : '' ?>">
            </div>

        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     SECTION B — Hardware Specs
     ════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-secondary rounded-pill">B</span>
        <strong>Hardware Specs</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <div class="col-md-4">
                <label class="form-label">Brand <span class="text-danger">*</span></label>
                <input type="text" name="brand" maxlength="60" list="brand-list"
                       value="<?= e($row['brand']) ?>"
                       class="form-control" placeholder="Dell / HP / Asus">
                <datalist id="brand-list">
                    <?php foreach (['Dell','HP','Asus','Microsoft','Lenovo','Apple','Acer','QNAP','IBM','Zebra','Honeywell','Samsung'] as $b): ?>
                        <option value="<?= e($b) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="col-md-8">
                <label class="form-label">Model</label>
                <input type="text" name="model" maxlength="100"
                       value="<?= e($row['model']) ?>"
                       class="form-control" placeholder="Optiplex 3000 / Latitude 3420 / PowerEdge R640">
            </div>

            <div class="col-md-4">
                <label class="form-label">Serial Number</label>
                <input type="text" name="serial_number" maxlength="100"
                       value="<?= e($row['serial_number']) ?>"
                       class="form-control font-monospace">
            </div>

            <div class="col-md-4">
                <label class="form-label">
                    Service Tag
                    <span class="text-muted small">(Dell)</span>
                </label>
                <input type="text" name="service_tag" maxlength="50"
                       value="<?= e($row['service_tag']) ?>"
                       class="form-control font-monospace" placeholder="7T9FVQ3">
                <div class="form-text">
                    <a href="https://www.dell.com/support" target="_blank" rel="noopener"
                       class="text-decoration-none">
                        <i class="bi bi-box-arrow-up-right"></i> ตรวจ warranty Dell
                    </a>
                </div>
            </div>

            <div class="col-md-4">
                <label class="form-label">Purchase Date</label>
                <input type="date" name="purchase_date"
                       value="<?= e($row['purchase_date']) ?>"
                       class="form-control <?= isset($errors['purchase_date']) ? 'is-invalid' : '' ?>">
            </div>

            <div class="col-md-8">
                <label class="form-label">CPU</label>
                <input type="text" name="cpu" maxlength="150"
                       value="<?= e($row['cpu']) ?>"
                       class="form-control" placeholder="Intel Core i5-12600 3.3GHz / Xeon Silver 4210R">
            </div>

            <div class="col-md-2">
                <label class="form-label">RAM (GB)</label>
                <input type="number" name="ram_gb" min="1" max="2048" step="1"
                       value="<?= e($row['ram_gb']) ?>"
                       class="form-control <?= isset($errors['ram_gb']) ? 'is-invalid' : '' ?>"
                       placeholder="8">
                <?php if (isset($errors['ram_gb'])): ?>
                    <div class="invalid-feedback"><?= e($errors['ram_gb']) ?></div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <label class="form-label">Storage</label>
                <input type="text" name="storage" maxlength="100" list="storage-list"
                       value="<?= e($row['storage']) ?>"
                       class="form-control" placeholder="256GB SSD / 1TB HDD">
                <datalist id="storage-list">
                    <?php foreach (['128GB SSD','256GB SSD','512GB SSD','1TB SSD','256SSD+1TB HDD','480GB SSD','1TB HDD','2TB HDD'] as $s): ?>
                        <option value="<?= e($s) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>

            <div class="col-md-6">
                <label class="form-label">GPU</label>
                <input type="text" name="gpu" maxlength="100" list="gpu-list"
                       value="<?= e($row['gpu']) ?>"
                       class="form-control" placeholder="ON Board / Nvidia GeForce GTX 1650">
                <datalist id="gpu-list">
                    <option value="ON Board">
                    <option value="Nvidia GeForce GTX 1650">
                    <option value="Nvidia Quadro P1000">
                    <option value="AMD Radeon">
                </datalist>
            </div>

            <div class="col-md-4">
                <label class="form-label">Warranty Expiry</label>
                <input type="date" name="warranty_expiry"
                       value="<?= e($row['warranty_expiry']) ?>"
                       class="form-control <?= isset($errors['warranty_expiry']) ? 'is-invalid' : '' ?>">
            </div>

        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     SECTION C — Network
     ════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-success rounded-pill">C</span>
        <strong>Network</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <div class="col-md-4">
                <label class="form-label">IP Address</label>
                <input type="text" name="ip_address" maxlength="45"
                       value="<?= e($row['ip_address']) ?>"
                       class="form-control font-monospace" placeholder="172.16.56.131">
            </div>

            <div class="col-md-3">
                <label class="form-label">IP Assignment</label>
                <select name="ip_assignment" class="form-select">
                    <option value="Static" <?= $row['ip_assignment'] === 'Static' ? 'selected' : '' ?>>Static</option>
                    <option value="DHCP"   <?= $row['ip_assignment'] === 'DHCP'   ? 'selected' : '' ?>>DHCP</option>
                </select>
            </div>

            <div class="col-md-5">
                <label class="form-label">MAC Address (LAN)</label>
                <input type="text" name="mac_address" maxlength="50"
                       value="<?= e($row['mac_address']) ?>"
                       class="form-control font-monospace" placeholder="AA:BB:CC:DD:EE:FF">
            </div>

            <div class="col-md-5">
                <label class="form-label">
                    MAC Address (WiFi)
                    <span class="text-muted small">สำหรับ Notebook</span>
                </label>
                <input type="text" name="mac_wifi" maxlength="50"
                       value="<?= e($row['mac_wifi']) ?>"
                       class="form-control font-monospace" placeholder="AA:BB:CC:DD:EE:FF">
            </div>

        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     SECTION D — OS & License
     ════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-warning rounded-pill">D</span>
        <strong>OS & License</strong>
        <span class="ms-auto badge text-bg-light border text-muted small">
            <i class="bi bi-lock"></i> ข้อมูล sensitive — เฉพาะ IT admin
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <div class="col-md-5">
                <label class="form-label">Operating System</label>
                <select name="os_name" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($osOptions as $o): ?>
                        <option value="<?= e($o) ?>" <?= $row['os_name'] === $o ? 'selected' : '' ?>>
                            <?= e($o) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Bit</label>
                <select name="os_bit" class="form-select">
                    <option value="">—</option>
                    <option value="64" <?= $row['os_bit'] == '64' ? 'selected' : '' ?>>64-bit</option>
                    <option value="32" <?= $row['os_bit'] == '32' ? 'selected' : '' ?>>32-bit</option>
                </select>
            </div>

            <div class="col-md-5">
                <label class="form-label">Windows Product Key</label>
                <input type="text" name="os_product_key" maxlength="120"
                       value="<?= e($row['os_product_key']) ?>"
                       class="form-control font-monospace"
                       placeholder="XXXXX-XXXXX-XXXXX-XXXXX-XXXXX"
                       autocomplete="off">
            </div>

            <div class="col-md-4">
                <label class="form-label">Office License Type</label>
                <select name="office_license" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($officeLics as $ol): ?>
                        <option value="<?= e($ol) ?>" <?= $row['office_license'] === $ol ? 'selected' : '' ?>>
                            <?= e($ol) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-8">
                <label class="form-label">Office Product Key</label>
                <input type="text" name="office_product_key" maxlength="120"
                       value="<?= e($row['office_product_key']) ?>"
                       class="form-control font-monospace"
                       placeholder="XXXXX-XXXXX-XXXXX-XXXXX-XXXXX"
                       autocomplete="off">
            </div>

        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     SECTION E — Peripherals S/N
     ════════════════════════════════════════════════════════ -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-danger rounded-pill">E</span>
        <strong>Peripherals S/N</strong>
        <span class="text-muted small ms-2">กรอกเฉพาะที่มี</span>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <div class="col-md-6">
                <label class="form-label"><i class="bi bi-display text-muted me-1"></i>Monitor 1 S/N</label>
                <input type="text" name="sn_monitor_1" maxlength="150"
                       value="<?= e($periSN('Monitor_1')) ?>"
                       class="form-control font-monospace" placeholder="CN-065K5F-xxxxx">
            </div>

            <div class="col-md-6">
                <label class="form-label"><i class="bi bi-display text-muted me-1"></i>Monitor 2 S/N</label>
                <input type="text" name="sn_monitor_2" maxlength="150"
                       value="<?= e($periSN('Monitor_2')) ?>"
                       class="form-control font-monospace" placeholder="CN-065K5F-xxxxx">
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-mouse text-muted me-1"></i>Mouse S/N</label>
                <input type="text" name="sn_mouse" maxlength="150"
                       value="<?= e($periSN('Mouse_1')) ?>"
                       class="form-control font-monospace">
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-keyboard text-muted me-1"></i>Keyboard S/N</label>
                <input type="text" name="sn_keyboard" maxlength="150"
                       value="<?= e($periSN('Keyboard_1')) ?>"
                       class="form-control font-monospace">
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-plug text-muted me-1"></i>Adapter S/N</label>
                <input type="text" name="sn_adapter" maxlength="150"
                       value="<?= e($periSN('Adapter_1')) ?>"
                       class="form-control font-monospace">
            </div>

            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-bag text-muted me-1"></i>Bag S/N</label>
                <input type="text" name="sn_bag" maxlength="150"
                       value="<?= e($periSN('Bag_1')) ?>"
                       class="form-control font-monospace">
            </div>

        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════
     SECTION F — สถานะ
     ════════════════════════════════════════════════════════ -->
<div class="card mb-4">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-dark rounded-pill">F</span>
        <strong>สถานะ</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">

            <div class="col-md-4">
                <label class="form-label">Status <span class="text-danger">*</span></label>
                <select name="status" class="form-select <?= isset($errors['status']) ? 'is-invalid' : '' ?>">
                    <?php foreach ($statuses as $s): ?>
                        <option value="<?= e($s) ?>" <?= $row['status'] === $s ? 'selected' : '' ?>>
                            <?= e($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-4 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="is_loanable" id="is_loanable" value="1"
                           <?= $row['is_loanable'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_loanable">
                        เครื่องส่วนกลาง (ยืมได้)
                    </label>
                </div>
            </div>

            <div class="col-md-4" id="loan-pool-wrap"
                 style="<?= $row['is_loanable'] ? '' : 'display:none' ?>">
                <label class="form-label">Loan Pool Name</label>
                <input type="text" name="loan_pool_name" maxlength="50"
                       value="<?= e($row['loan_pool_name']) ?>"
                       class="form-control" placeholder="IT Loan Pool">
            </div>

            <div class="col-12">
                <label class="form-label">หมายเหตุ / Specifications เพิ่มเติม</label>
                <textarea name="specifications" rows="3" class="form-control"
                          placeholder="ข้อมูลอื่นๆ เช่น GPU model, expansion slots, ประวัติซ่อม"><?= e($row['specifications']) ?></textarea>
            </div>

        </div>
    </div>
</div>

<!-- ── Action buttons ──────────────────────────────────────────── -->
<div class="d-flex justify-content-end gap-2 mb-5">
    <a href="/it-asset-manager/assets/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-x-lg"></i> ยกเลิก
    </a>
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i>
        <?= $isEdit ? 'บันทึกการแก้ไข' : 'เพิ่ม Asset' ?>
    </button>
</div>

</form>

<script>
/* toggle loan pool name field */
document.getElementById('is_loanable').addEventListener('change', function () {
    document.getElementById('loan-pool-wrap').style.display = this.checked ? '' : 'none';
});

/* ------ Employee live search (pattern เดียวกับ software/allocate.php) ------ */
const empInput  = document.getElementById('empSearchInput');
const empResult = document.getElementById('empSearchResults');
const empHidden = document.getElementById('empSelectedId');
const empLabel  = document.getElementById('empSelectedLabel');
const empClear  = document.getElementById('empClearBtn');
const userInput = document.getElementById('userDisplayInput');

let empTimer = null;
empInput.addEventListener('input', () => {
    clearTimeout(empTimer);
    const q = empInput.value.trim();
    empResult.innerHTML = '';
    if (q.length < 2) return;
    empTimer = setTimeout(() => {
        fetch('/it-asset-manager/assets/employee_search_ajax.php?q=' + encodeURIComponent(q))
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

                        /* auto-fill "ชื่อผู้ใช้งาน" ให้เห็นว่าจะบันทึกเป็นอะไร (server ยืนยันซ้ำเองอีกที)
                           AD Username ไม่แตะ — เป็น field อิสระ คนละความหมายกับ employee */
                        userInput.value = item.label;
                        userInput.readOnly = true;
                        empClear.style.display = '';
                    });
                    empResult.appendChild(btn);
                });
            });
    }, 300);
});

empClear.addEventListener('click', (ev) => {
    ev.preventDefault();
    empHidden.value = '';
    empLabel.textContent = '';
    empInput.value = '';
    userInput.value = '';
    userInput.readOnly = false;
    empClear.style.display = 'none';
});

/* section collapse (optional UX — click header to toggle body) */
document.querySelectorAll('.card-header').forEach(hdr => {
    hdr.style.cursor = 'pointer';
    hdr.addEventListener('click', () => {
        const body = hdr.nextElementSibling;
        if (body && body.classList.contains('card-body')) {
            body.style.display = body.style.display === 'none' ? '' : '';
        }
    });
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
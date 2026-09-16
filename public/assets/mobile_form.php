<?php
ini_set("display_errors",1);error_reporting(E_ALL);
/**
 * Mobile Asset — Add / Edit
 * /var/www/lab/it-asset-manager/public/assets/mobile_form.php
 *
 * Sections:
 *   A  ข้อมูลทั่วไป   (asset_id, device_type, company, dept, site, user, location, register_date)
 *   B  Hardware        (brand, model, serial, imei, ram, storage)
 *   C  Network & SIM   (mac_wifi, anydesk_id, sim_provider, phone_number, sim_owner)
 *   D  OS & Apps       (os_version, main_app, other_apps)
 *   E  บัญชีองค์กร    (org_email, org_password_hint)
 *   F  Accessories     (sn_charger, sn_cradle_case, accessories_note)
 *   G  สถานะ           (status, is_loanable, notes)
 */
//declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_role(['it_admin','it_staff']);



require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/employee_log.php';
$pdo = db();

$id     = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$errors = [];
$empId  = null; // จะถูกกำหนดค่าจาก DB (โหมดแก้ไข) หรือจาก POST (ตอนบันทึก) ด้านล่าง
$oldEmpId = null; // ค่า assigned_employee_id เดิมก่อนบันทึก — ใช้เทียบ Assign/
                  // Transfer/Return สำหรับ history log (เฉพาะโหมดแก้ไข)

$row = [
    'asset_id'          => '',
    'device_type'       => 'Smartphone',
    'company'           => 'PCS',
    'department'        => '',
    'site_id'           => '',
    'user_name'         => '',
    'assigned_employee_id' => '',
    'location_detail'   => '',
    'register_date'     => '',
    'brand'             => '',
    'model'             => '',
    'serial_number'     => '',
    'imei'              => '',
    'ram'               => '',
    'storage'           => '',
    'mac_wifi'          => '',
    'anydesk_id'        => '',
    'sim_provider'      => '',
    'phone_number'      => '',
    'sim_owner'         => '',
    'os_version'        => '',
    'main_app'          => '',
    'other_apps'        => '',
    'org_email'         => '',
    'org_password_hint' => '',
    'sn_charger'        => '',
    'sn_cradle_case'    => '',
    'accessories_note'  => '',
    'is_loanable'       => 0,
    'status'            => 'Active',
    'notes'             => '',
    'updated_at'        => date('Y-m-d'),
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM mobile_assets WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) { http_response_code(404); exit('Mobile asset not found'); }
    $row = array_merge($row, $existing);
    if ($row['assigned_employee_id'] !== null && $row['assigned_employee_id'] !== '') {
        $empId = (int)$row['assigned_employee_id'];
        $oldEmpId = $empId;
    }
}

$sites       = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();
$deviceTypes = ['Smartphone','Handheld','Tablet','Barcode Reader','Barcode Scanner','Rugged PDA','Feature Phone','Other'];
$departments = ['IT','HR','MK','WH','CS','EN','AC','PLP','QA','PC','ส่วนกลาง'];
$companies   = ['PCS','JPK','JPAC','PACS','ส่วนกลาง'];
$simProviders= ['DTAC','True','AIS','NT','ซิมโรงงาน','ไม่มี SIM'];
$statuses    = ['Active','In Repair','In Stock','Retired','On Loan','ชำรุด','คืนแล้ว','สูญหาย','รอ Reset'];

/* ── POST ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    foreach (array_keys($row) as $k) {
        $row[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $row['is_loanable'] = isset($_POST['is_loanable']) ? 1 : 0;
    $row['updated_at']  = date('Y-m-d');

    /* ── Employee link (จากระบบ HR search) ─────────────────────────
       เหมือน hardware assets/form.php — เซิร์ฟเวอร์ยืนยันจาก
       cc_central_employee_db เสมอ เขียนทับเฉพาะ user_name (ชื่อแสดงผล)
       ไม่เชื่อค่า client ตรงๆ ถ้าไม่ได้เลือกพนักงาน (เช่น device ยังไม่มี
       ผู้รับผิดชอบเฉพาะ) จะปล่อยให้เป็นค่าที่พิมพ์เอง และ
       assigned_employee_id จะถูกเก็บเป็น NULL ─────────────────────── */
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
            $row['user_name'] = trim($emp['title'] . ' ' . $emp['first_name'] . ' ' . $emp['last_name']);
        }
    }

    /* validation */
    if ($row['asset_id'] === '')
        $errors['asset_id'] = 'จำเป็นต้องระบุรหัสอุปกรณ์';
    if (!in_array($row['device_type'], $deviceTypes, true))
        $errors['device_type'] = 'ประเภทไม่ถูกต้อง';
    if (!in_array($row['status'], $statuses, true))
        $errors['status'] = 'Status ไม่ถูกต้อง';
    if ($row['register_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['register_date']))
        $errors['register_date'] = 'รูปแบบวันที่ไม่ถูกต้อง';

    /* unique asset_id */
    if (!isset($errors['asset_id'])) {
        $uq = $pdo->prepare(
            "SELECT id FROM mobile_assets WHERE asset_id = :aid" .
            ($isEdit ? " AND id <> :id" : "")
        );
        $uq->bindValue(':aid', $row['asset_id']);
        if ($isEdit) $uq->bindValue(':id', $id, PDO::PARAM_INT);
        $uq->execute();
        if ($uq->fetchColumn())
            $errors['asset_id'] = 'รหัสอุปกรณ์นี้มีอยู่แล้ว';
    }

    if (!$errors) {
        $sqlFields = [
            'asset_id','device_type','company','department','site_id',
            'user_name','assigned_employee_id','location_detail','register_date',
            'brand','model','serial_number','imei','ram','storage',
            'mac_wifi','anydesk_id',
            'sim_provider','phone_number','sim_owner',
            'os_version','main_app','other_apps',
            'org_email','org_password_hint',
            'sn_charger','sn_cradle_case','accessories_note',
            'is_loanable','status','notes','updated_at',
        ];

        $bind = [];
        $nullables = [
            'company','department','site_id','user_name','location_detail',
            'register_date','brand','model','serial_number','imei','ram','storage',
            'mac_wifi','anydesk_id','sim_provider','phone_number','sim_owner',
            'os_version','main_app','other_apps','org_email','org_password_hint',
            'sn_charger','sn_cradle_case','accessories_note','notes',
        ];
        foreach ($sqlFields as $k) {
            $v = $row[$k] ?? null;
            if (in_array($k, $nullables) && ($v === '' || $v === null)) $v = null;
            $bind[$k] = $v;
        }
        $bind['is_loanable'] = (int)$row['is_loanable'];
        if (!empty($bind['site_id']) && ctype_digit((string)$bind['site_id']))
            $bind['site_id'] = (int)$bind['site_id'];
        else $bind['site_id'] = null;
        /* assigned_employee_id: int หรือ NULL เท่านั้น — มาจาก $empId ที่ผ่านการ
           ยืนยันกับ employee_db() แล้วด้านบน ไม่ใช้ค่า $row ตรงๆ */
        $bind['assigned_employee_id'] = $empId;

        try {
            if ($isEdit) {
                $sets = implode(', ', array_map(fn($k) => "{$k}=:{$k}", $sqlFields));
                $pdo->prepare("UPDATE mobile_assets SET {$sets} WHERE id=:id_pk")
                    ->execute(array_merge($bind, ['id_pk' => $id]));
            } else {
                $cols = implode(', ', $sqlFields);
                $vals = implode(', ', array_map(fn($k) => ":{$k}", $sqlFields));
                $pdo->prepare("INSERT INTO mobile_assets ({$cols}) VALUES ({$vals})")
                    ->execute($bind);
                $id = (int)$pdo->lastInsertId();
            }

            // ── History log (เฟส 1) — pattern เดียวกับ hardware assets/form.php ──
            if ($empId !== $oldEmpId) {
                $assetLabel = trim(($row['brand'] ?? '') . ' ' . ($row['model'] ?? '')) . ' (' . $row['asset_id'] . ')';
                $currentUser = iam_user();
                $performedBy = $currentUser['username'] ?? null;

                if ($oldEmpId === null && $empId !== null) {
                    logEmployeeTransaction($pdo, $empId, 'Asset Assign', 'mobile_assets', $id,
                        'Assign ' . $assetLabel, $performedBy);
                } elseif ($oldEmpId !== null && $empId !== null) {
                    // Transfer ต้อง log ทั้ง 2 ฝั่ง เหมือน assets/form.php
                    logEmployeeTransaction($pdo, $empId, 'Asset Transfer', 'mobile_assets', $id,
                        'Transfer ' . $assetLabel . ' (received)', $performedBy);
                    logEmployeeTransaction($pdo, $oldEmpId, 'Asset Transfer', 'mobile_assets', $id,
                        'Transfer ' . $assetLabel . ' (transferred away)', $performedBy);
                } elseif ($oldEmpId !== null && $empId === null) {
                    logEmployeeTransaction($pdo, $oldEmpId, 'Asset Return', 'mobile_assets', $id,
                        'Return ' . $assetLabel, $performedBy);
                }
            }

            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => $isEdit ? 'บันทึกการแก้ไขเรียบร้อย' : 'เพิ่ม Mobile Asset เรียบร้อย',
            ];
            header('Location: /it-asset-manager/assets/mobile.php');
            exit;

        } catch (PDOException $e) {
            error_log('[MOBILE SAVE] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$page_title  = $isEdit
    ? 'Edit Mobile · ' . htmlspecialchars((string)($row['asset_id'] ?? ''), ENT_QUOTES, 'UTF-8')
    : 'Add Mobile Asset';
$active_menu = 'mobile';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/assets/mobile.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับรายการ Mobile
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($page_title) ?></h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<form method="post" novalidate>
<?php csrf_field(); ?>

<!-- ── SECTION A : ข้อมูลทั่วไป ──────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-primary rounded-pill">A</span>
        <strong>ข้อมูลทั่วไป</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">รหัสอุปกรณ์ <span class="text-danger">*</span></label>
                <input type="text" name="asset_id" maxlength="30"
                       value="<?= e($row['asset_id']) ?>"
                       class="form-control font-monospace <?= isset($errors['asset_id'])?'is-invalid':'' ?>"
                       placeholder="MOB-A13-001 / HH-64-001-PCSWH">
                <?php if (isset($errors['asset_id'])): ?>
                    <div class="invalid-feedback"><?= e($errors['asset_id']) ?></div>
                <?php endif; ?>
                <div class="form-text">MOB-รุ่น-NNN หรือ HH-YY-NNN-DEPT</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                <select name="device_type" class="form-select <?= isset($errors['device_type'])?'is-invalid':'' ?>">
                    <?php foreach ($deviceTypes as $dt): ?>
                        <option value="<?= e($dt) ?>" <?= $row['device_type']===$dt?'selected':'' ?>><?= e($dt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- company: ไม่แสดงในหน้าจอแล้ว (ให้ตรงกับ Hardware Asset module ที่ใช้แค่
                 Site + หน่วยงาน ไม่มี company แยก) แต่ยังส่งเป็น hidden field เพื่อรักษา
                 ค่าเดิมของอุปกรณ์ที่เคย import จาก Excel ไว้ ไม่ให้ถูกเขียนทับเป็นค่าว่าง
                 หากต้องแก้ค่านี้ ต้องแก้ผ่าน import Excel หรือ SQL โดยตรง -->
            <input type="hidden" name="company" value="<?= e($row['company']) ?>">
            <div class="col-md-4">
                <label class="form-label">หน่วยงาน</label>
                <select name="department" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= e($d) ?>" <?= $row['department']===$d?'selected':'' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Site</label>
                <select name="site_id" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= e($s['id']) ?>" <?= (string)$row['site_id']===(string)$s['id']?'selected':'' ?>>
                            <?= e($s['site_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">วันที่ลงทะเบียน</label>
                <input type="date" name="register_date"
                       value="<?= e($row['register_date']) ?>"
                       class="form-control <?= isset($errors['register_date'])?'is-invalid':'' ?>">
            </div>

            <div class="col-md-6">
                <label class="form-label">ค้นหาพนักงานผู้รับผิดชอบ (จากระบบ HR)</label>
                <input type="text" id="empSearchInput" class="form-control"
                       placeholder="พิมพ์ชื่อ นามสกุล หรือรหัสพนักงาน" autocomplete="off"
                       value="<?= $empId ? e($row['user_name']) : '' ?>">
                <div id="empSearchResults" class="list-group mt-1"></div>
                <input type="hidden" name="assigned_employee_id" id="empSelectedId"
                       value="<?= e($row['assigned_employee_id']) ?>">
                <div class="small mt-1">
                    <span id="empSelectedLabel" class="fw-semibold text-success">
                        <?= $empId ? 'เลือกแล้ว: ' . e($row['user_name']) : '' ?>
                    </span>
                    <a href="#" id="empClearBtn" class="text-danger ms-2"
                       style="<?= $empId ? '' : 'display:none;' ?>">ล้างค่า</a>
                </div>
                <?php if (isset($errors['assigned_employee_id'])): ?>
                    <div class="text-danger small mt-1"><?= e($errors['assigned_employee_id']) ?></div>
                <?php endif; ?>
                <div class="form-text">ถ้ายังไม่มีผู้รับผิดชอบเฉพาะ ไม่ต้องเลือก — กรอกช่องขวาแทน</div>
            </div>
            <div class="col-md-6">
                <label class="form-label">ผู้รับผิดชอบ</label>
                <input type="text" name="user_name" id="userNameInput" maxlength="150"
                       value="<?= e($row['user_name']) ?>"
                       class="form-control" placeholder="ชื่อ-นามสกุล / ว่าง" <?= $empId ? 'readonly' : '' ?>>
            </div>

            <div class="col-12">
                <label class="form-label">Location / จุดติดตั้ง</label>
                <input type="text" name="location_detail" maxlength="200"
                       value="<?= e($row['location_detail']) ?>"
                       class="form-control" placeholder="อาคาร 9 / ลานโหลด อ.6 / ส่วนกลาง WH-A">
            </div>
        </div>
    </div>
</div>

<!-- ── SECTION B : Hardware ───────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-secondary rounded-pill">B</span>
        <strong>Hardware</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Brand</label>
                <input type="text" name="brand" maxlength="60" list="mob-brands"
                       value="<?= e($row['brand']) ?>" class="form-control"
                       placeholder="Samsung / Zebra / Honeywell">
                <datalist id="mob-brands">
                    <?php foreach (['Samsung','Apple','Zebra','Honeywell','Unitech','Datalogic','Huawei','Xiaomi','Other'] as $b): ?>
                        <option value="<?= e($b) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-5">
                <label class="form-label">Model</label>
                <input type="text" name="model" maxlength="100"
                       value="<?= e($row['model']) ?>" class="form-control"
                       placeholder="Galaxy A13 / TC52 / MC330">
            </div>
            <div class="col-md-2">
                <label class="form-label">RAM</label>
                <input type="text" name="ram" maxlength="20" list="mob-ram"
                       value="<?= e($row['ram']) ?>" class="form-control" placeholder="4GB">
                <datalist id="mob-ram">
                    <option value="2GB"><option value="3GB"><option value="4GB">
                    <option value="6GB"><option value="8GB"><option value="16GB">
                </datalist>
            </div>
            <div class="col-md-2">
                <label class="form-label">Storage</label>
                <input type="text" name="storage" maxlength="30" list="mob-storage"
                       value="<?= e($row['storage']) ?>" class="form-control" placeholder="64GB">
                <datalist id="mob-storage">
                    <option value="16GB"><option value="32GB"><option value="64GB">
                    <option value="128GB"><option value="256GB">
                </datalist>
            </div>
            <div class="col-md-6">
                <label class="form-label">Serial Number</label>
                <input type="text" name="serial_number" maxlength="60"
                       value="<?= e($row['serial_number']) ?>"
                       class="form-control font-monospace" placeholder="R58T51M5ALE">
            </div>
            <div class="col-md-6">
                <label class="form-label">
                    IMEI
                    <span class="text-muted small">สำหรับ Smartphone</span>
                </label>
                <input type="text" name="imei" maxlength="20"
                       value="<?= e($row['imei']) ?>"
                       class="form-control font-monospace" placeholder="15 หลัก">
            </div>
        </div>
    </div>
</div>

<!-- ── SECTION C : Network & SIM ──────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-success rounded-pill">C</span>
        <strong>Network & SIM</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">MAC Address (WiFi)</label>
                <input type="text" name="mac_wifi" maxlength="50"
                       value="<?= e($row['mac_wifi']) ?>"
                       class="form-control font-monospace" placeholder="AA:BB:CC:DD:EE:FF">
            </div>
            <div class="col-md-4">
                <label class="form-label">AnyDesk ID</label>
                <input type="text" name="anydesk_id" maxlength="20"
                       value="<?= e($row['anydesk_id']) ?>"
                       class="form-control font-monospace" placeholder="123456789">
            </div>
            <div class="col-md-4">
                <label class="form-label">ผู้ให้บริการ SIM</label>
                <select name="sim_provider" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($simProviders as $sp): ?>
                        <option value="<?= e($sp) ?>" <?= $row['sim_provider']===$sp?'selected':'' ?>><?= e($sp) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">เบอร์โทรศัพท์</label>
                <input type="text" name="phone_number" maxlength="20"
                       value="<?= e($row['phone_number']) ?>"
                       class="form-control" placeholder="086-xxx-xxxx">
            </div>
            <div class="col-md-4">
                <label class="form-label">SIM เจ้าของ</label>
                <select name="sim_owner" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <option value="บริษัท"  <?= $row['sim_owner']==='บริษัท' ?'selected':'' ?>>บริษัท</option>
                    <option value="ส่วนตัว" <?= $row['sim_owner']==='ส่วนตัว'?'selected':'' ?>>ส่วนตัว</option>
                </select>
            </div>
        </div>
    </div>
</div>

<!-- ── SECTION D : OS & Apps ──────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-warning rounded-pill">D</span>
        <strong>OS & Apps</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">OS Version</label>
                <input type="text" name="os_version" maxlength="50" list="mob-os"
                       value="<?= e($row['os_version']) ?>"
                       class="form-control" placeholder="Android 12 / iOS 17">
                <datalist id="mob-os">
                    <?php foreach (['Android 11','Android 12','Android 13','Android 14','iOS 16','iOS 17','iOS 18'] as $os): ?>
                        <option value="<?= e($os) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-4">
                <label class="form-label">App หลัก</label>
                <input type="text" name="main_app" maxlength="100" list="mob-apps"
                       value="<?= e($row['main_app']) ?>"
                       class="form-control" placeholder="CCMS / WMS / SmartStore">
                <datalist id="mob-apps">
                    <?php foreach (['CCMS / WMS','SmartStore','Redmine','AnyDesk / Remote','Line / Email','CCMS / WMS / SmartStore'] as $ap): ?>
                        <option value="<?= e($ap) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-4">
                <label class="form-label">App อื่นๆ</label>
                <input type="text" name="other_apps" maxlength="255"
                       value="<?= e($row['other_apps']) ?>"
                       class="form-control" placeholder="Line, Email, AnyDesk">
            </div>
        </div>
    </div>
</div>

<!-- ── SECTION E : บัญชีองค์กร ───────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-danger rounded-pill">E</span>
        <strong>บัญชีองค์กร</strong>
        <span class="ms-auto badge text-bg-light border text-muted small">
            <i class="bi bi-lock"></i> sensitive — เฉพาะ IT admin
        </span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Email องค์กร</label>
                <input type="email" name="org_email" maxlength="150"
                       value="<?= e($row['org_email']) ?>"
                       class="form-control" placeholder="name.jwdcoldchain@gmail.com"
                       autocomplete="off">
            </div>
            <div class="col-md-6">
                <label class="form-label">
                    Password Hint
                    <span class="text-muted small">(hint เท่านั้น ไม่เก็บ plain text)</span>
                </label>
                <input type="text" name="org_password_hint" maxlength="100"
                       value="<?= e($row['org_password_hint']) ?>"
                       class="form-control" placeholder="Pcs@1234"
                       autocomplete="off">
            </div>
        </div>
    </div>
</div>

<!-- ── SECTION F : Accessories ────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge rounded-pill" style="background:#8b5cf6">F</span>
        <strong>Accessories S/N</strong>
        <span class="text-muted small ms-2">กรอกเฉพาะที่มี</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-plug text-muted me-1"></i>Charger S/N</label>
                <input type="text" name="sn_charger" maxlength="60"
                       value="<?= e($row['sn_charger']) ?>"
                       class="form-control font-monospace">
            </div>
            <div class="col-md-4">
                <label class="form-label"><i class="bi bi-phone text-muted me-1"></i>Cradle / Case S/N</label>
                <input type="text" name="sn_cradle_case" maxlength="60"
                       value="<?= e($row['sn_cradle_case']) ?>"
                       class="form-control font-monospace">
            </div>
            <div class="col-md-4">
                <label class="form-label">อุปกรณ์เสริมอื่นๆ</label>
                <input type="text" name="accessories_note" maxlength="255"
                       value="<?= e($row['accessories_note']) ?>"
                       class="form-control" placeholder="สาย USB-C, Screen protector">
            </div>
        </div>
    </div>
</div>

<!-- ── SECTION G : สถานะ ──────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-dark rounded-pill">G</span>
        <strong>สถานะ</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Status <span class="text-danger">*</span></label>
                <select name="status" class="form-select <?= isset($errors['status'])?'is-invalid':'' ?>">
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?= e($st) ?>" <?= $row['status']===$st?'selected':'' ?>><?= e($st) ?></option>
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
            <div class="col-12">
                <label class="form-label">หมายเหตุ</label>
                <textarea name="notes" rows="2" class="form-control"
                          placeholder="ประวัติซ่อม, หมายเหตุพิเศษ"><?= e($row['notes']) ?></textarea>
            </div>
        </div>
    </div>
</div>

<!-- ── Actions ───────────────────────────────────────────────── -->
<div class="d-flex justify-content-end gap-2 mb-5">
    <a href="/it-asset-manager/assets/mobile.php" class="btn btn-outline-secondary">
        <i class="bi bi-x-lg"></i> ยกเลิก
    </a>
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i>
        <?= $isEdit ? 'บันทึกการแก้ไข' : 'เพิ่ม Mobile Asset' ?>
    </button>
</div>

</form>

<script>
/* ------ Employee live search (pattern เดียวกับ assets/form.php) ------ */
const empInput  = document.getElementById('empSearchInput');
const empResult = document.getElementById('empSearchResults');
const empHidden = document.getElementById('empSelectedId');
const empLabel  = document.getElementById('empSelectedLabel');
const empClear  = document.getElementById('empClearBtn');
const userInput = document.getElementById('userNameInput');

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
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
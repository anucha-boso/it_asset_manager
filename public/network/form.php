<?php
/**
 * Network Asset — Add / Edit
 * /var/www/lab/it-asset-manager/public/network/form.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_role(['it_admin','it_staff']);
require_module_access('NETWORK');

$pdo    = db();
$id     = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$errors = [];

$row = [
    'asset_id'          => '',
    'device_type'       => 'Switch',
    'site_id'           => '',
    'location'          => '',
    'rack_position'     => '',
    'brand'             => 'Cisco',
    'model'             => '',
    'hostname'          => '',
    'serial_number'     => '',
    'mac_address'       => '',
    'ip_mgmt'           => '',
    'ip_production'     => '',
    'vlan_info'         => '',
    'firmware_version'  => '',
    'firmware_updated'  => '',
    'sim_provider'      => '',
    'sim_number'        => '',
    'sim_owner'         => '',
    'mgmt_username'     => '',
    'mgmt_password_hint'=> '',
    'is_loanable'       => 0,
    'loan_pool_name'    => '',
    'status'            => 'Active',
    'purchase_date'     => '',
    'warranty_expiry'   => '',
    'notes'             => '',
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM network_assets WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) { http_response_code(404); exit('Not found'); }
    $row = array_merge($row, $existing);

    /* Load WiFi USB peripherals */
    $periStmt = $pdo->prepare("
        SELECT * FROM asset_peripherals
        WHERE network_asset_id = :nid ORDER BY type, slot
    ");
    $periStmt->execute([':nid' => $id]);
    $peripherals = $periStmt->fetchAll();
} else {
    $peripherals = [];
}

$sites       = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();
$deviceTypes = ['Switch','Router','Firewall','Access Point',
                'Load Balancer','Patch Panel','Media Converter','Router SIM','Other'];
$statuses    = ['Active','Inactive','In Repair','Retired','On Loan'];
$simProviders= ['AIS','DTAC','True','NT','ไม่มี SIM'];

/* ── POST ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    foreach (array_keys($row) as $k) {
        $row[$k] = trim((string)($_POST[$k] ?? ''));
    }
    $row['is_loanable'] = isset($_POST['is_loanable']) ? 1 : 0;

    if ($row['asset_id'] === '')
        $errors['asset_id'] = 'จำเป็นต้องระบุรหัสอุปกรณ์';
    if (!in_array($row['device_type'], $deviceTypes, true))
        $errors['device_type'] = 'ประเภทไม่ถูกต้อง';

    /* unique asset_id */
    if (!isset($errors['asset_id'])) {
        $uq = $pdo->prepare("SELECT id FROM network_assets WHERE asset_id=:aid"
            . ($isEdit ? " AND id<>:id" : ""));
        $uq->bindValue(':aid', $row['asset_id']);
        if ($isEdit) $uq->bindValue(':id', $id, PDO::PARAM_INT);
        $uq->execute();
        if ($uq->fetchColumn()) $errors['asset_id'] = 'รหัสอุปกรณ์นี้มีอยู่แล้ว';
    }

    if (!$errors) {
        $sqlFields = [
            'asset_id','device_type','site_id','location','rack_position',
            'brand','model','hostname','serial_number','mac_address',
            'ip_mgmt','ip_production','vlan_info',
            'firmware_version','firmware_updated',
            'sim_provider','sim_number','sim_owner',
            'mgmt_username','mgmt_password_hint',
            'is_loanable','loan_pool_name',
            'status','purchase_date','warranty_expiry','notes',
        ];

        $nullables = [
            'site_id','location','rack_position','brand','model','hostname',
            'serial_number','mac_address','ip_mgmt','ip_production','vlan_info',
            'firmware_version','firmware_updated','sim_provider','sim_number',
            'sim_owner','mgmt_username','mgmt_password_hint','loan_pool_name',
            'purchase_date','warranty_expiry','notes',
        ];

        $bind = [];
        foreach ($sqlFields as $k) {
            $v = $row[$k] ?? null;
            if (in_array($k, $nullables) && ($v === '' || $v === null)) $v = null;
            $bind[$k] = $v;
        }
        $bind['is_loanable'] = (int)$row['is_loanable'];
        if (!empty($bind['site_id'])) $bind['site_id'] = (int)$bind['site_id'];
        else $bind['site_id'] = null;

        try {
            $pdo->beginTransaction();

            if ($isEdit) {
                $sets = implode(', ', array_map(fn($k) => "{$k}=:{$k}", $sqlFields));
                $pdo->prepare("UPDATE network_assets SET {$sets} WHERE id=:id_pk")
                    ->execute(array_merge($bind, ['id_pk' => $id]));
            } else {
                $cols = implode(', ', $sqlFields);
                $vals = implode(', ', array_map(fn($k) => ":{$k}", $sqlFields));
                $stmt = $pdo->prepare("INSERT INTO network_assets ({$cols}) VALUES ({$vals})");
                $stmt->execute($bind);
                $id = (int)$pdo->lastInsertId();
            }

            /* WiFi USB Peripherals */
            $pdo->prepare("DELETE FROM asset_peripherals WHERE network_asset_id=:nid")
                ->execute([':nid' => $id]);

            $wifiUsbs = $_POST['wifi_usb_sn'] ?? [];
            $periIns  = $pdo->prepare("
                INSERT INTO asset_peripherals
                    (network_asset_id, type, slot, serial_number)
                VALUES (:nid, 'Other', :slot, :sn)
            ");
            foreach ((array)$wifiUsbs as $i => $sn) {
                $sn = trim($sn);
                if ($sn !== '') {
                    $periIns->execute([
                        ':nid'  => $id,
                        ':slot' => $i + 1,
                        ':sn'   => $sn,
                    ]);
                }
            }

            $pdo->commit();
            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => $isEdit ? 'บันทึกการแก้ไขเรียบร้อย' : 'เพิ่ม Network Asset เรียบร้อย',
            ];
            header('Location: /it-asset-manager/network/index.php');
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[NET SAVE] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$isRouterSim = $row['device_type'] === 'Router SIM';
$page_title  = $isEdit
    ? 'Edit Network · ' . htmlspecialchars((string)($row['asset_id'] ?? ''), ENT_QUOTES, 'UTF-8')
    : 'Add Network Asset';
$active_menu = 'network';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/network/index.php"
       class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับรายการ Network
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($page_title) ?></h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<form method="post" novalidate>
<?php csrf_field(); ?>

<!-- ── A: ข้อมูลทั่วไป ───────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-primary rounded-pill">A</span>
        <strong>ข้อมูลทั่วไป</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Asset ID <span class="text-danger">*</span></label>
                <input type="text" name="asset_id" maxlength="30"
                       value="<?= e($row['asset_id']) ?>"
                       class="form-control font-monospace <?= isset($errors['asset_id'])?'is-invalid':'' ?>"
                       placeholder="NET-SW-001">
                <?php if (isset($errors['asset_id'])): ?>
                    <div class="invalid-feedback"><?= e($errors['asset_id']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                <select name="device_type" id="device_type" class="form-select">
                    <?php foreach ($deviceTypes as $dt): ?>
                        <option value="<?= e($dt) ?>"
                            <?= $row['device_type']===$dt?'selected':'' ?>><?= e($dt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Site</label>
                <select name="site_id" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= e($s['id']) ?>"
                            <?= (string)$row['site_id']===(string)$s['id']?'selected':'' ?>>
                            <?= e($s['site_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status <span class="text-danger">*</span></label>
                <select name="status" class="form-select">
                    <?php foreach ($statuses as $st): ?>
                        <option value="<?= e($st) ?>"
                            <?= $row['status']===$st?'selected':'' ?>><?= e($st) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Location</label>
                <input type="text" name="location" maxlength="200"
                       value="<?= e($row['location']) ?>"
                       class="form-control" placeholder="Building 8 / CS Office">
            </div>
            <div class="col-md-4">
                <label class="form-label">Rack Position</label>
                <input type="text" name="rack_position" maxlength="50"
                       value="<?= e($row['rack_position']) ?>"
                       class="form-control" placeholder="PACK3 #1 / Rack A2">
            </div>
            <div class="col-md-4">
                <label class="form-label">Hostname</label>
                <input type="text" name="hostname" maxlength="150"
                       value="<?= e($row['hostname']) ?>"
                       class="form-control font-monospace"
                       placeholder="B8-SVR-CoreSWR3-CBS350-24T">
            </div>
        </div>
    </div>
</div>

<!-- ── B: Hardware ────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-secondary rounded-pill">B</span>
        <strong>Hardware</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Brand</label>
                <input type="text" name="brand" maxlength="60" list="net-brands"
                       value="<?= e($row['brand']) ?>" class="form-control">
                <datalist id="net-brands">
                    <?php foreach (['Cisco','Juniper','MikroTik','Ubiquiti','Aruba','TP-Link','D-Link','Huawei','Other'] as $b): ?>
                        <option value="<?= e($b) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-5">
                <label class="form-label">Model</label>
                <input type="text" name="model" maxlength="100"
                       value="<?= e($row['model']) ?>" class="form-control"
                       placeholder="CBS350-24T-4G / SG350-52MP-K9">
            </div>
            <div class="col-md-4">
                <label class="form-label">Serial Number</label>
                <input type="text" name="serial_number" maxlength="60"
                       value="<?= e($row['serial_number']) ?>"
                       class="form-control font-monospace">
            </div>
            <div class="col-md-4">
                <label class="form-label">MAC Address</label>
                <input type="text" name="mac_address" maxlength="50"
                       value="<?= e($row['mac_address']) ?>"
                       class="form-control font-monospace"
                       placeholder="AA:BB:CC:DD:EE:FF">
            </div>
            <div class="col-md-4">
                <label class="form-label">Purchase Date</label>
                <input type="date" name="purchase_date"
                       value="<?= e($row['purchase_date']) ?>" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Warranty Expiry</label>
                <input type="date" name="warranty_expiry"
                       value="<?= e($row['warranty_expiry']) ?>" class="form-control">
            </div>
        </div>
    </div>
</div>

<!-- ── C: Network ─────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-success rounded-pill">C</span>
        <strong>Network</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">IP Management (vlan40)</label>
                <input type="text" name="ip_mgmt" maxlength="45"
                       value="<?= e($row['ip_mgmt']) ?>"
                       class="form-control font-monospace"
                       placeholder="192.168.40.100">
            </div>
            <div class="col-md-3">
                <label class="form-label">IP Production (vlan20)</label>
                <input type="text" name="ip_production" maxlength="45"
                       value="<?= e($row['ip_production']) ?>"
                       class="form-control font-monospace"
                       placeholder="172.16.60.30">
            </div>
            <div class="col-md-3">
                <label class="form-label">VLAN / Port Info</label>
                <input type="text" name="vlan_info" maxlength="100"
                       value="<?= e($row['vlan_info']) ?>"
                       class="form-control" placeholder="1-46 v20 / port 1">
            </div>
            <div class="col-md-3">
                <label class="form-label">Firmware Version</label>
                <input type="text" name="firmware_version" maxlength="50"
                       value="<?= e($row['firmware_version']) ?>"
                       class="form-control font-monospace"
                       placeholder="3.3.0.16">
            </div>
            <div class="col-md-3">
                <label class="form-label">Firmware Updated</label>
                <input type="date" name="firmware_updated"
                       value="<?= e($row['firmware_updated']) ?>" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">Management Username</label>
                <input type="text" name="mgmt_username" maxlength="50"
                       value="<?= e($row['mgmt_username']) ?>"
                       class="form-control" placeholder="admin">
            </div>
            <div class="col-md-6">
                <label class="form-label">
                    Password Hint
                    <span class="badge text-bg-warning text-dark ms-1" style="font-size:10px;">sensitive</span>
                </label>
                <input type="text" name="mgmt_password_hint" maxlength="100"
                       value="<?= e($row['mgmt_password_hint']) ?>"
                       class="form-control" autocomplete="off"
                       placeholder="hint เท่านั้น ไม่เก็บ plain text">
            </div>
        </div>
    </div>
</div>

<!-- ── D: SIM (Router SIM เท่านั้น) ──────────────────────────── -->
<div class="card mb-3" id="sim-section"
     style="<?= $isRouterSim ? '' : 'display:none' ?>">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-warning rounded-pill text-dark">D</span>
        <strong>SIM Card</strong>
        <span class="text-muted small ms-1">เฉพาะ Router SIM</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">ผู้ให้บริการ SIM</label>
                <select name="sim_provider" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($simProviders as $sp): ?>
                        <option value="<?= e($sp) ?>"
                            <?= $row['sim_provider']===$sp?'selected':'' ?>><?= e($sp) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">เบอร์ SIM</label>
                <input type="text" name="sim_number" maxlength="20"
                       value="<?= e($row['sim_number']) ?>"
                       class="form-control" placeholder="089-xxx-xxxx">
            </div>
            <div class="col-md-3">
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

<!-- ── E: WiFi USB Adapters (Router SIM เท่านั้น) ────────────── -->
<div class="card mb-3" id="wifi-usb-section"
     style="<?= $isRouterSim ? '' : 'display:none' ?>">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge rounded-pill" style="background:#8b5cf6">E</span>
        <strong>WiFi USB Adapters</strong>
        <span class="text-muted small ms-1">อุปกรณ์เสริมที่ยืมไปพร้อมกัน</span>
    </div>
    <div class="card-body">
        <div id="wifi-usb-list">
            <?php
            $wifiCount = max(1, count($peripherals));
            for ($i = 0; $i < $wifiCount; $i++):
                $sn = $peripherals[$i]['serial_number'] ?? '';
            ?>
            <div class="d-flex gap-2 mb-2 wifi-usb-row">
                <div class="input-group">
                    <span class="input-group-text bg-white small">
                        <i class="bi bi-usb-symbol text-muted me-1"></i> S/N
                    </span>
                    <input type="text" name="wifi_usb_sn[]" maxlength="100"
                           value="<?= e($sn) ?>"
                           class="form-control font-monospace"
                           placeholder="Serial Number WiFi USB Adapter <?= $i+1 ?>">
                    <?php if ($i > 0): ?>
                        <button type="button" class="btn btn-outline-danger"
                                onclick="this.closest('.wifi-usb-row').remove()">
                            <i class="bi bi-x"></i>
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>
        <button type="button" class="btn btn-sm btn-outline-secondary mt-1"
                onclick="addWifiUsb()">
            <i class="bi bi-plus-lg"></i> เพิ่ม WiFi USB Adapter
        </button>
    </div>
</div>

<!-- ── F: Loan ────────────────────────────────────────────────── -->
<div class="card mb-3" id="loan-section"
     style="<?= $isRouterSim ? '' : 'display:none' ?>">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-dark rounded-pill">F</span>
        <strong>Loan Pool</strong>
        <span class="text-muted small ms-1">เฉพาะอุปกรณ์ที่ให้ยืม</span>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4 d-flex align-items-center">
                <div class="form-check form-switch mb-0">
                    <input class="form-check-input" type="checkbox" role="switch"
                           name="is_loanable" id="is_loanable" value="1"
                           <?= $row['is_loanable'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_loanable">
                        ยืมได้ (Loanable)
                    </label>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label">Loan Pool Name</label>
                <input type="text" name="loan_pool_name" maxlength="50"
                       value="<?= e($row['loan_pool_name']) ?>"
                       class="form-control" placeholder="IT Emergency Router">
            </div>
        </div>
    </div>
</div>

<!-- ── Notes ─────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-body">
        <label class="form-label">หมายเหตุ</label>
        <textarea name="notes" rows="2" class="form-control"><?= e($row['notes']) ?></textarea>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mb-5">
    <a href="/it-asset-manager/network/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-x-lg"></i> ยกเลิก
    </a>
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg"></i>
        <?= $isEdit ? 'บันทึกการแก้ไข' : 'เพิ่ม Network Asset' ?>
    </button>
</div>

</form>

<script>
/* toggle SIM / WiFi USB / Loan sections ตาม device_type */
document.getElementById('device_type').addEventListener('change', function() {
    const isRouterSim = this.value === 'Router SIM';
    ['sim-section','wifi-usb-section','loan-section'].forEach(id => {
        document.getElementById(id).style.display = isRouterSim ? '' : 'none';
    });
});

function addWifiUsb() {
    const list = document.getElementById('wifi-usb-list');
    const idx  = list.querySelectorAll('.wifi-usb-row').length + 1;
    const div  = document.createElement('div');
    div.className = 'd-flex gap-2 mb-2 wifi-usb-row';
    div.innerHTML = `
        <div class="input-group">
            <span class="input-group-text bg-white small">
                <i class="bi bi-usb-symbol text-muted me-1"></i> S/N
            </span>
            <input type="text" name="wifi_usb_sn[]" maxlength="100"
                   class="form-control font-monospace"
                   placeholder="Serial Number WiFi USB Adapter ${idx}">
            <button type="button" class="btn btn-outline-danger"
                    onclick="this.closest('.wifi-usb-row').remove()">
                <i class="bi bi-x"></i>
            </button>
        </div>`;
    list.appendChild(div);
}
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

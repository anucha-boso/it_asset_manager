<?php
/**
 * =============================================================================
 *  Asset Bulk Import — from IT_Asset_Inventory_PCS.xlsx style workbook
 *  public/assets/import.php
 * -----------------------------------------------------------------------------
 *  ขั้นตอน:
 *    1) อัปโหลดไฟล์ .xlsx  → parse ด้วย PhpSpreadsheet
 *    2) แสดง preview + validate (asset_id ซ้ำ / ค่าที่ enum ไม่รู้จัก) ให้ตรวจก่อน
 *    3) กดยืนยัน → insert จริงแบบ PDO transaction
 *
 *  Sheet mapping:
 *    PC-Notebook-Server  -> hardware_assets
 *    Handheld-Tablet     -> mobile_assets (device_type ตามคอลัมน์ 'ประเภท')
 *    Smartphone          -> mobile_assets (device_type = 'Smartphone')
 *
 *  ข้อกำหนดเซิร์ฟเวอร์:
 *    composer require phpoffice/phpspreadsheet
 *    Nginx: client_max_body_size 20m; (ปรับใน server block ที่ /assets/import.php)
 * =============================================================================
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

$pdo = db();

if (isset($_GET['reset'])) {
    unset($_SESSION['import_preview']);
    header('Location: /it-asset-manager/assets/import.php');
    exit;
}

// -----------------------------------------------------------------------------
//  Helpers — แปลงค่า cell แบบปลอดภัย (ไฟล์จริงมี format วันที่ปนกันหลายแบบ)
// -----------------------------------------------------------------------------
function cellStr($value): ?string {
    if ($value === null) return null;
    $s = (string)$value;
    $s = str_replace(["\xC2\xA0", "\xEF\xBB\xBF"], '', $s); // strip NBSP / BOM ที่หลุดมาจาก Excel
    $s = trim($s);
    return $s === '' || $s === '-' ? null : $s;
}

function cellDate($value): ?string {
    if ($value === null || $value === '') return null;
    if ($value instanceof \DateTimeInterface) return $value->format('Y-m-d');
    if (is_numeric($value)) {
        try { return ExcelDate::excelToDateTimeObject((float)$value)->format('Y-m-d'); }
        catch (\Throwable $e) { /* fallthrough */ }
    }
    $s = trim((string)$value);
    if ($s === '' || $s === '-') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return "{$m[1]}-{$m[2]}-{$m[3]}";
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $s, $m)) return sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
    return null;
}

function cellInt($value): ?int {
    if ($value === null || $value === '') return null;
    if (preg_match('/(\d+)/', (string)$value, $m)) return (int)$m[1];
    return null;
}

// -----------------------------------------------------------------------------
//  Column maps: [excel_col_index (0-based)] => db_field
//  แถวข้อมูลจริงเริ่มที่แถว index 2 (แถว 0-1 เป็น header 2 ชั้น)
// -----------------------------------------------------------------------------
const HW_MAP = [
    1 => 'asset_id', 2 => 'category', 3 => 'department', 4 => 'user_display',
    5 => 'location', 6 => 'register_date', 7 => 'brand', 8 => 'model',
    9 => 'service_tag', 10 => 'serial_number', 11 => 'cpu', 12 => 'ram_gb',
    13 => 'storage', 14 => 'warranty_expiry', 15 => 'ip_address',
    16 => 'mac_address', 17 => 'mac_wifi', 18 => 'ip_assignment',
    19 => 'os_name', 20 => 'os_bit', 21 => 'os_product_key',
    22 => 'office_license', 23 => 'office_product_key', 39 => 'status',
];
// คอลัมน์ที่ไม่มีที่เก็บตรงตัว -> รวมเป็นข้อความต่อท้ายใน specifications
const HW_EXTRA_LABELS = [
    24 => 'Bitdefender', 25 => 'SmartStore', 26 => 'Redmine', 27 => 'Budget',
    28 => 'Central Store', 29 => 'TruckLoad PLP', 30 => 'Payroll',
    31 => 'อื่นๆ (software)', 32 => 'S/N Monitor 1', 33 => 'S/N Monitor 2',
    34 => 'S/N Mouse', 35 => 'S/N Keyboard', 36 => 'S/N Adapter', 37 => 'S/N Bag',
    38 => 'Accessories อื่นๆ', 41 => 'หมายเหตุ',
];
const HW_DATE_FIELDS = ['register_date', 'warranty_expiry'];
const HW_INT_FIELDS  = ['ram_gb', 'os_bit'];

// ค่าดิบที่พบจริงในไฟล์ inventory (WINDOWS_DESKTOP ฯลฯ) -> enum ที่ hardware_assets.category ยอมรับจริง
// ปรับ mapping นี้ได้เลยถ้าอยากให้ค่าไหน map ต่างจากนี้
const HW_CATEGORY_NORMALIZE = [
    'WINDOWS_DESKTOP'  => 'PC',
    'WINDOWS_LAPTOP'   => 'Notebook',
    'WINDOWS_SERVER'   => 'Server',
    'HYPERV_VMM_HOST'  => 'Server',
    'HYPERV_VMM_GUEST' => 'Server',
];
const HW_CATEGORY_ENUM = ['PC','Notebook','Surface','Server','NAS','Printer','Scanner','UPS','Handheld','Tablet','PrintServer','Other'];
const HW_STATUS_ENUM   = ['Active','In Repair','In Stock','Retired','On Loan','Reserved'];
const HW_IP_ASSIGN_ENUM = ['Static','DHCP'];

const MOBILE_DEVICE_TYPE_ENUM = ['Smartphone','Handheld','Tablet','Barcode Reader','Barcode Scanner','Rugged PDA','Feature Phone','Other'];
const MOBILE_STATUS_ENUM = ['Active','In Repair','In Stock','Retired','On Loan','ชำรุด','คืนแล้ว','สูญหาย','รอ Reset'];
const MOBILE_SIM_OWNER_ENUM = ['บริษัท','ส่วนตัว'];

/** คืน error message ถ้า $value ไม่ว่างและไม่อยู่ใน $allowed, คืน null ถ้าผ่าน */
function checkEnum(?string $value, array $allowed, string $fieldLabel): ?string {
    if ($value === null || $value === '') return null;
    if (in_array($value, $allowed, true)) return null;
    return "{$fieldLabel} ค่า \"{$value}\" ไม่ตรงกับที่ระบบยอมรับ (" . implode('/', $allowed) . ")";
}

// Handheld-Tablet (24 cols)
const HH_MAP = [
    1 => 'asset_id', 2 => 'device_type', 3 => 'department', 4 => 'user_name',
    5 => 'location_detail', 6 => 'register_date', 7 => 'brand', 8 => 'model',
    9 => 'serial_number', 10 => 'ram', 11 => 'storage', 12 => 'mac_wifi',
    13 => 'phone_number', 14 => 'anydesk_id', 15 => 'os_version',
    16 => 'main_app', 17 => 'other_apps', 18 => 'sn_charger',
    19 => 'sn_cradle_case', 20 => 'accessories_note', 21 => 'status',
    23 => 'notes',
];
// Smartphone (22 cols)
const SP_MAP = [
    1 => 'asset_id', 3 => 'company', 4 => 'user_name', 5 => 'department',
    6 => 'register_date', 7 => 'brand', 8 => 'imei', 9 => 'mac_wifi',
    10 => 'ram', 11 => 'storage', 12 => 'os_version', 13 => 'org_email',
    14 => 'org_password_hint', 15 => 'anydesk_id', 16 => 'sim_provider',
    17 => 'phone_number', 18 => 'sim_owner', 19 => 'status', 21 => 'notes',
];
const MOBILE_DATE_FIELDS = ['register_date'];

// ค่า max length จริงจาก DESCRIBE hardware_assets / mobile_assets — กันปัญหา
// "Data too long for column" ล่วงหน้าทั้งไฟล์ (เจอแล้วที่ storage, กันเผื่อ column อื่นด้วย)
const HW_FIELD_MAXLEN = [
    'asset_id' => 50, 'department' => 20, 'brand' => 50, 'model' => 100,
    'serial_number' => 100, 'service_tag' => 50, 'cpu' => 150, 'storage' => 100,
    'location' => 100, 'assigned_to_ad' => 100, 'user_display' => 150,
    'ip_address' => 45, 'mac_address' => 17, 'mac_wifi' => 50, 'os_name' => 60,
    'os_product_key' => 120, 'office_license' => 50, 'office_product_key' => 120,
];
const MOBILE_FIELD_MAXLEN = [
    'asset_id' => 30, 'company' => 50, 'department' => 20, 'user_name' => 150,
    'location_detail' => 200, 'brand' => 60, 'model' => 100, 'serial_number' => 60,
    'imei' => 20, 'ram' => 20, 'storage' => 30, 'mac_wifi' => 50, 'anydesk_id' => 20,
    'os_version' => 50, 'main_app' => 100, 'other_apps' => 255, 'sim_provider' => 30,
    'phone_number' => 20, 'org_email' => 150, 'org_password_hint' => 100,
    'sn_charger' => 60, 'sn_cradle_case' => 60, 'accessories_note' => 255,
];

// ตัดค่าที่ยาวเกิน max length ให้พอดี แล้วเก็บของเต็มไว้ใน overflow field (text)
// เพื่อไม่เสียข้อมูลจริง แค่ column หน้าตารางแสดงแบบย่อ
function applyMaxLen(array &$rec, array $maxLens, string $overflowField): void {
    $overflow = [];
    foreach ($maxLens as $field => $len) {
        if (!empty($rec[$field]) && mb_strlen($rec[$field]) > $len) {
            $overflow[] = "{$field} (full): {$rec[$field]}";
            $rec[$field] = mb_substr($rec[$field], 0, max(0, $len - 3)) . '...';
        }
    }
    if ($overflow) {
        $rec[$overflowField] = trim(($rec[$overflowField] ?? '') . ' | ' . implode(' | ', $overflow), ' |');
    }
}

// -----------------------------------------------------------------------------
//  Parse a sheet into normalized rows
// -----------------------------------------------------------------------------
function parseSheetRows(array $sheetRows, array $map, array $dateFields, array $intFields = [], array $extraLabels = [], string $kind = 'hw', ?callable $genAssetId = null): array {
    $out = [];
    foreach ($sheetRows as $r => $row) {
        if ($r < 3) continue; // skip 3-row header block: title row + group-header row + column-label row
        $rec = [];
        foreach ($map as $idx => $field) {
            $val = $row[$idx] ?? null;
            if (in_array($field, $dateFields, true))      $rec[$field] = cellDate($val);
            elseif (in_array($field, $intFields, true))   $rec[$field] = cellInt($val);
            else                                           $rec[$field] = cellStr($val);
        }
        // แถวไม่มี asset_id -> ถ้ามี generator (เช่น Smartphone ที่ผู้ใช้ไม่ได้กรอกรหัสไว้) ให้สร้างให้อัตโนมัติ
        if (empty($rec['asset_id']) && $genAssetId) {
            $rec['asset_id'] = $genAssetId($row, $rec);
        }
        if (empty($rec['asset_id'])) continue; // ยังไม่มี asset_id -> ข้ามทิ้ง (แถวว่าง/แถวทดสอบ)

        if ($extraLabels) {
            $extraLines = [];
            foreach ($extraLabels as $idx => $label) {
                $v = cellStr($row[$idx] ?? null);
                if ($v !== null) $extraLines[] = "{$label}: {$v}";
            }
            if ($extraLines) {
                $rec['specifications'] = ($rec['specifications'] ?? '') . implode(' | ', $extraLines);
            }
        }

        $maxLens = $kind === 'hw' ? HW_FIELD_MAXLEN : MOBILE_FIELD_MAXLEN;
        applyMaxLen($rec, $maxLens, $kind === 'hw' ? 'specifications' : 'notes');

        // mobile_assets.status เป็น NOT NULL (default 'Active') แต่ hardware_assets.status รับ NULL ได้
        // ถ้าช่อง "สถานะ" ว่างในไฟล์ ให้ fallback เป็น 'Active' เฉพาะฝั่ง mobile ไม่งั้นชน constraint ตอน insert
        if ($kind === 'mobile' && empty($rec['status'])) {
            $rec['status'] = 'Active';
        }

        // ---- normalize + validate enum fields ก่อน insert ----
        // ค่าที่ไม่ตรง enum จะถูกทำเครื่องหมาย _error แทนการปล่อยให้ INSERT ล้มทั้ง transaction
        $rowErrors = [];
        if ($kind === 'hw') {
            if (isset($rec['category'], HW_CATEGORY_NORMALIZE[$rec['category']])) {
                $rec['category'] = HW_CATEGORY_NORMALIZE[$rec['category']];
            }
            foreach ([['category', HW_CATEGORY_ENUM], ['status', HW_STATUS_ENUM], ['ip_assignment', HW_IP_ASSIGN_ENUM]] as [$f, $enum]) {
                if ($err = checkEnum($rec[$f] ?? null, $enum, $f)) $rowErrors[] = $err;
            }
        } else {
            foreach ([['device_type', MOBILE_DEVICE_TYPE_ENUM], ['status', MOBILE_STATUS_ENUM], ['sim_owner', MOBILE_SIM_OWNER_ENUM]] as [$f, $enum]) {
                if ($err = checkEnum($rec[$f] ?? null, $enum, $f)) $rowErrors[] = $err;
            }
        }
        $rec['_error'] = $rowErrors ? implode('; ', $rowErrors) : null;

        $out[] = $rec;
    }
    return $out;
}

// -----------------------------------------------------------------------------
//  Validate against DB: duplicate asset_id check
// -----------------------------------------------------------------------------
function checkDuplicates(PDO $pdo, string $table, array $rows): array {
    if (!$rows) return [];
    $ids = array_column($rows, 'asset_id');
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare("SELECT asset_id FROM {$table} WHERE asset_id IN ({$in})");
    $stmt->execute($ids);
    return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
}

// ธงซ้ำ "ภายในไฟล์เดียวกัน" — asset_id ซ้ำกันเองในไฟล์ (ไม่ใช่ซ้ำกับ DB)
// ต้องเช็คแยก เพราะ checkDuplicates() ข้างบนเช็คกับ DB เท่านั้น ถ้าไม่กันจุดนี้
// แถวแรกจะ insert ผ่านแต่แถวที่ 2 ของ asset_id เดิมจะชน unique key ระหว่าง commit
function flagInBatchDuplicates(array &$rows): void {
    $count = [];
    foreach ($rows as $r) $count[$r['asset_id']] = ($count[$r['asset_id']] ?? 0) + 1;
    foreach ($rows as &$r) {
        if ($count[$r['asset_id']] > 1) $r['_dup'] = true;
    }
    unset($r);
}

$errors  = [];
$notice  = null;
$preview = null;
$step    = $_POST['step'] ?? ($_SESSION['import_preview'] ?? null ? 'preview' : 'upload');

// -----------------------------------------------------------------------------
//  STEP: upload + parse -> build preview
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'parse') {
    csrf_verify();

    if (empty($_FILES['xlsx']) || $_FILES['xlsx']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'กรุณาเลือกไฟล์ .xlsx ให้ถูกต้อง';
    } else {
        try {
            $spreadsheet = IOFactory::load($_FILES['xlsx']['tmp_name']);

            $hwRows = $mobileRows = [];

            if ($spreadsheet->sheetNameExists('PC-Notebook-Server')) {
                $rows = $spreadsheet->getSheetByName('PC-Notebook-Server')->toArray(null, true, true, false);
                $hwRows = parseSheetRows($rows, HW_MAP, HW_DATE_FIELDS, HW_INT_FIELDS, HW_EXTRA_LABELS, 'hw');
            }
            if ($spreadsheet->sheetNameExists('Handheld-Tablet')) {
                $rows = $spreadsheet->getSheetByName('Handheld-Tablet')->toArray(null, true, true, false);
                $mobileRows = array_merge($mobileRows, parseSheetRows($rows, HH_MAP, MOBILE_DATE_FIELDS, [], [], 'mobile'));
            }
            if ($spreadsheet->sheetNameExists('Smartphone')) {
                $rows = $spreadsheet->getSheetByName('Smartphone')->toArray(null, true, true, false);

                // สร้าง asset_id ให้อัตโนมัติสำหรับแถวที่ "รหัสอุปกรณ์" ว่าง (พบว่า sheet นี้ไม่มีการกรอกไว้เลย)
                // format: PCS-{MODEL}-{seq เรียงตามรุ่น} เช่น "Samsung Galaxy A34" -> PCS-GALAXYA34-001
                $modelSeq = [];
                $genSmartphoneId = function (array $row) use (&$modelSeq): string {
                    $brandRaw = trim((string)($row[7] ?? '')); // col 7 = "Brand / รุ่น"
                    $words = array_values(array_filter(preg_split('/\s+/', $brandRaw)));
                    if (count($words) > 1) array_shift($words); // ตัดคำแรกทิ้ง (สมมติเป็นชื่อยี่ห้อ เช่น Samsung)
                    $modelPart = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', implode('', $words)));
                    if ($modelPart === '') $modelPart = 'UNKNOWN';
                    $modelSeq[$modelPart] = ($modelSeq[$modelPart] ?? 0) + 1;
                    return sprintf('PCS-%s-%03d', $modelPart, $modelSeq[$modelPart]);
                };

                $spRows = parseSheetRows($rows, SP_MAP, MOBILE_DATE_FIELDS, [], [], 'mobile', $genSmartphoneId);
                foreach ($spRows as &$r) { $r['device_type'] = 'Smartphone'; $r['serial_number'] = $r['imei'] ?? null; }
                unset($r);
                $mobileRows = array_merge($mobileRows, $spRows);
            }

            $hwDupes     = checkDuplicates($pdo, 'hardware_assets', $hwRows);
            $mobileDupes = checkDuplicates($pdo, 'mobile_assets', $mobileRows);

            foreach ($hwRows as &$r)     { $r['_dup'] = isset($hwDupes[$r['asset_id']]); }
            foreach ($mobileRows as &$r) { $r['_dup'] = isset($mobileDupes[$r['asset_id']]); }
            unset($r);

            flagInBatchDuplicates($hwRows);
            flagInBatchDuplicates($mobileRows);

            $_SESSION['import_preview'] = ['hw' => $hwRows, 'mobile' => $mobileRows];
            $step = 'preview';
        } catch (\Throwable $e) {
            error_log('[IMPORT PARSE FAIL] ' . $e->getMessage());
            $errors[] = 'อ่านไฟล์ไม่สำเร็จ: ' . $e->getMessage();
            $step = 'upload';
        }
    }
}

// -----------------------------------------------------------------------------
//  STEP: commit -> insert into DB
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'commit') {
    csrf_verify();
    $data = $_SESSION['import_preview'] ?? null;
    if (!$data) {
        $errors[] = 'ไม่พบข้อมูลที่ preview ไว้ กรุณาอัปโหลดใหม่';
        $step = 'upload';
    } else {
        $insertedHw = $insertedMobile = $skipped = $skippedInvalid = 0;
        try {
            $pdo->beginTransaction();

            $hwCols = array_unique(array_merge(array_values(HW_MAP), ['specifications']));
            $hwSql  = "INSERT INTO hardware_assets (" . implode(',', $hwCols) . ")
                       VALUES (" . implode(',', array_map(fn($c) => ":$c", $hwCols)) . ")";
            $hwStmt = $pdo->prepare($hwSql);

            $seenHw = [];
            foreach ($data['hw'] as $r) {
                if (!empty($r['_error'])) { $skippedInvalid++; continue; } // enum ไม่ตรง -> ข้ามเสมอ ไม่ว่าจะติ๊กหรือไม่
                if (!empty($r['_dup']) || empty($_POST['include_hw'][$r['asset_id']])) { $skipped++; continue; }
                if (isset($seenHw[$r['asset_id']])) { $skipped++; continue; } // กันซ้ำสุดท้ายเผื่อติ๊กทั้งคู่
                $seenHw[$r['asset_id']] = true;
                $bind = [];
                foreach ($hwCols as $c) $bind[":$c"] = $r[$c] ?? null;
                $hwStmt->execute($bind);
                $insertedHw++;
            }

            $mobileFields = ['asset_id','device_type','department','company','user_name','location_detail',
                'register_date','brand','model','serial_number','imei','ram','storage','mac_wifi','anydesk_id',
                'os_version','main_app','other_apps','sim_provider','phone_number','sim_owner','org_email',
                'org_password_hint','sn_charger','sn_cradle_case','accessories_note','status','notes'];
            $mobileSql  = "INSERT INTO mobile_assets (" . implode(',', $mobileFields) . ")
                           VALUES (" . implode(',', array_map(fn($c) => ":$c", $mobileFields)) . ")";
            $mobileStmt = $pdo->prepare($mobileSql);

            $seenMobile = [];
            foreach ($data['mobile'] as $r) {
                if (!empty($r['_error'])) { $skippedInvalid++; continue; }
                if (!empty($r['_dup']) || empty($_POST['include_mobile'][$r['asset_id']])) { $skipped++; continue; }
                if (isset($seenMobile[$r['asset_id']])) { $skipped++; continue; }
                $seenMobile[$r['asset_id']] = true;
                $bind = [];
                foreach ($mobileFields as $c) $bind[":$c"] = $r[$c] ?? null;
                $mobileStmt->execute($bind);
                $insertedMobile++;
            }

            $pdo->commit();
            unset($_SESSION['import_preview']);
            $notice = "นำเข้าสำเร็จ: hardware_assets {$insertedHw} รายการ, mobile_assets {$insertedMobile} รายการ "
                    . "(ข้าม {$skipped} รายการ, ข้อมูลไม่ตรง enum {$skippedInvalid} รายการ)";
            $step = 'upload';
        } catch (\Throwable $e) {
            $pdo->rollBack();
            error_log('[IMPORT COMMIT FAIL] ' . $e->getMessage());
            $errors[] = 'นำเข้าไม่สำเร็จ: ' . $e->getMessage();
            $step = 'preview';
        }
    }
}

if ($step === 'preview' && isset($_SESSION['import_preview'])) {
    $preview = $_SESSION['import_preview'];
}

$page_title  = 'Import Assets';
$active_menu = 'hardware';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/assets/index.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> กลับไปหน้ารายการ</a>
    <h2 class="h5 mb-0 mt-1">Import Assets จากไฟล์ Excel</h2>
</div>

<?php foreach ($errors as $err): ?>
    <div class="alert alert-danger"><?= e($err) ?></div>
<?php endforeach; ?>
<?php if ($notice): ?>
    <div class="alert alert-success"><?= e($notice) ?></div>
<?php endif; ?>

<?php if ($step === 'upload'): ?>
    <div class="card" style="max-width:600px;">
        <div class="card-body">
            <p class="text-muted small">
                รองรับไฟล์ 3 sheet: <code>PC-Notebook-Server</code>, <code>Handheld-Tablet</code>, <code>Smartphone</code>
                (ตาม format IT_Asset_Inventory_PCS.xlsx)
            </p>
            <form method="post" enctype="multipart/form-data">
                <?php csrf_field(); ?>
                <input type="hidden" name="step" value="parse">
                <input type="file" name="xlsx" accept=".xlsx" class="form-control mb-3" required>
                <button class="btn btn-primary"><i class="bi bi-upload"></i> อัปโหลด &amp; Preview</button>
            </form>
        </div>
    </div>

<?php elseif ($step === 'preview' && $preview): ?>
    <?php $hwCount = count($preview['hw']); $mobileCount = count($preview['mobile']); ?>
    <p class="text-muted">
        พบ <strong><?= $hwCount ?></strong> รายการ hardware_assets และ
        <strong><?= $mobileCount ?></strong> รายการ mobile_assets —
        แถวที่มี <span class="badge text-bg-warning">asset_id ซ้ำ</span> จะถูกข้ามอัตโนมัติ (uncheck ได้) —
        แถวที่มี <span class="badge text-bg-danger">ค่าไม่ตรง enum</span> จะถูกข้ามเสมอ ต้องแก้ค่าในไฟล์ Excel แล้วอัปโหลดใหม่
    </p>
    <form method="post">
        <?php csrf_field(); ?>
        <input type="hidden" name="step" value="commit">

        <h5 class="mt-4">Hardware Assets (<?= $hwCount ?>)</h5>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-hover">
                <thead class="table-light"><tr>
                    <th></th><th>Asset ID</th><th>Category</th><th>Dept</th><th>Brand/Model</th><th>Status</th><th>หมายเหตุ</th>
                </tr></thead>
                <tbody>
                <?php foreach ($preview['hw'] as $r): $hasErr = !empty($r['_error']); ?>
                    <tr class="<?= $hasErr ? 'table-danger' : ($r['_dup'] ? 'table-warning' : '') ?>">
                        <td>
                            <input type="checkbox" name="include_hw[<?= e($r['asset_id']) ?>]" value="1"
                                   <?= $hasErr ? 'disabled' : ($r['_dup'] ? '' : 'checked') ?>>
                        </td>
                        <td><?= e($r['asset_id']) ?>
                            <?= $r['_dup'] ? '<span class="badge text-bg-warning">ซ้ำ</span>' : '' ?>
                            <?= $hasErr ? '<span class="badge text-bg-danger">ค่าไม่ตรง enum</span>' : '' ?>
                        </td>
                        <td><?= e($r['category']) ?></td>
                        <td><?= e($r['department']) ?></td>
                        <td><?= e($r['brand']) ?> <?= e($r['model']) ?></td>
                        <td><?= e($r['status']) ?></td>
                        <td class="text-danger small"><?= e($r['_error'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <h5>Mobile Assets (<?= $mobileCount ?>)</h5>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-hover">
                <thead class="table-light"><tr>
                    <th></th><th>Asset ID</th><th>Type</th><th>Dept</th><th>Brand/Model</th><th>Status</th><th>หมายเหตุ</th>
                </tr></thead>
                <tbody>
                <?php foreach ($preview['mobile'] as $r): $hasErr = !empty($r['_error']); ?>
                    <tr class="<?= $hasErr ? 'table-danger' : ($r['_dup'] ? 'table-warning' : '') ?>">
                        <td>
                            <input type="checkbox" name="include_mobile[<?= e($r['asset_id']) ?>]" value="1"
                                   <?= $hasErr ? 'disabled' : ($r['_dup'] ? '' : 'checked') ?>>
                        </td>
                        <td><?= e($r['asset_id']) ?>
                            <?= $r['_dup'] ? '<span class="badge text-bg-warning">ซ้ำ</span>' : '' ?>
                            <?= $hasErr ? '<span class="badge text-bg-danger">ค่าไม่ตรง enum</span>' : '' ?>
                        </td>
                        <td><?= e($r['device_type']) ?></td>
                        <td><?= e($r['department']) ?></td>
                        <td><?= e($r['brand']) ?> <?= e($r['model']) ?></td>
                        <td><?= e($r['status']) ?></td>
                        <td class="text-danger small"><?= e($r['_error'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex gap-2 mb-5">
            <a href="/it-asset-manager/assets/import.php?reset=1" class="btn btn-outline-secondary">ยกเลิก / อัปโหลดใหม่</a>
            <button class="btn btn-primary"><i class="bi bi-check-lg"></i> ยืนยัน Import</button>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
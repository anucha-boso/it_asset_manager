<?php
/**
 * Loan — New Request Form
 * /var/www/lab/it-asset-manager/public/loans/form.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff','it_viewer','it_borrower']);
require_module_access('LOANS');



require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/require_employee_link.php';
require_once __DIR__ . '/../../includes/employee_log.php';
$pdo = db();

$employeeId = require_employee_link(); // เด้งไป link-me.php อัตโนมัติถ้ายังไม่ผูก employee

// ------ ดึงข้อมูลพนักงานเจ้าของ session (ใช้เป็นค่า default ของ "ตัวเอง") ------
$empStmt = employee_db()->prepare("
    SELECT title, first_name, last_name, department, site_id, person_code
    FROM employees WHERE id = :id
");
$empStmt->execute([':id' => $employeeId]);
$employee = $empStmt->fetch();
if (!$employee) {
    http_response_code(500);
    exit('ไม่พบข้อมูลพนักงาน กรุณาติดต่อ IT Admin');
}
$requestorName = trim($employee['title'] . ' ' . $employee['first_name'] . ' ' . $employee['last_name']);
$borrowerType  = $_POST['borrower_type'] ?? 'self';

$errors = [];

/* ── Loanable assets ─────────────────────────────────────────── */
$hwAssets = $pdo->query("
    SELECT h.id, h.asset_id, h.brand, h.model, h.category, h.department,
           h.loan_pool_name, s.site_name
    FROM hardware_assets h
    LEFT JOIN sites s ON s.id = h.site_id
    WHERE h.is_loanable = 1
      AND h.status IN ('Active','In Stock')
      AND h.id NOT IN (
          SELECT asset_id FROM asset_loans
          WHERE status IN ('OnLoan','Approved','Overdue')
            AND asset_id IS NOT NULL
      )
    ORDER BY h.category, h.asset_id
")->fetchAll();

$mobAssets = $pdo->query("
    SELECT m.id, m.asset_id, m.brand, m.model, m.device_type, m.department,
           s.site_name
    FROM mobile_assets m
    LEFT JOIN sites s ON s.id = m.site_id
    WHERE m.is_loanable = 1
      AND m.status IN ('Active','In Stock')
      AND m.id NOT IN (
          SELECT mobile_id FROM asset_loans
          WHERE status IN ('OnLoan','Approved','Overdue')
            AND mobile_id IS NOT NULL
      )
    ORDER BY m.device_type, m.asset_id
")->fetchAll();

$netAssets = $pdo->query("
    SELECT n.id, n.asset_id, n.brand, n.model, n.device_type, n.location,
           n.loan_pool_name
    FROM network_assets n
    WHERE n.is_loanable = 1
      AND n.status IN ('Active')
      AND n.id NOT IN (
          SELECT network_asset_id FROM asset_loans
          WHERE status IN ('OnLoan','Approved','Overdue')
            AND network_asset_id IS NOT NULL
      )
    ORDER BY n.device_type, n.asset_id
")->fetchAll();

$sites       = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();
$departments = ['IT','HR','MK','WH','CS','EN','AC','PLP','QA','PC','ส่วนกลาง'];
$purposeTypes= ['Business Trip','Replacement','Project','Training','Other'];

$row = [
    'asset_type'      => 'hw',
    'asset_id'        => '',
    'mobile_id'       => '',
    'network_id'      => '',
    'borrower_ad'     => '',
    'borrower_name'   => '',
    'borrower_dept'   => '',
    'borrower_site_id'=> '',
    'borrower_phone'  => '',
    'purpose'         => '',
    'purpose_type'    => 'Other',
    'loan_date'       => date('Y-m-d'),
    'expected_return' => date('Y-m-d', strtotime('+7 days')),
    'notes'           => '',
];

/* ── Prefill จากการสแกน QR (assets/scan.php หรือ loans/scan.php) ────
   ?asset_type=hw|mob|net&id=<pk> — ตรวจสอบว่า asset นั้นยังอยู่ใน pool
   ที่ยืมได้จริงก่อนเลือกให้ ไม่งั้นแค่โชว์ข้อความแจ้งเตือน ให้เลือกเองแทน
   (asset อาจถูกยืมไปแล้วระหว่างที่กำลังเดินมาสแกน หรือไม่เปิดให้ยืม)
   ต้องอยู่ "หลัง" $row = [...] ด้านบน ไม่งั้นค่า default จะเขียนทับ prefill
   ที่เพิ่งตั้งไว้ ───────────────────────────────────────────────────── */
$prefillNotice  = null;
$prefillSuccess = null;
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $preType = $_GET['asset_type'] ?? '';
    if (in_array($preType, ['hw', 'mob', 'net'], true)) {
        $preId = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
        if ($preId > 0) {
            $pool    = $preType === 'hw' ? $hwAssets : ($preType === 'mob' ? $mobAssets : $netAssets);
            $idField = $preType === 'hw' ? 'asset_id' : ($preType === 'mob' ? 'mobile_id' : 'network_id');
            $found = false;
            foreach ($pool as $p) {
                if ((int)$p['id'] === $preId) { $found = true; $foundAsset = $p; break; }
            }
            if ($found) {
                $row['asset_type'] = $preType;
                $row[$idField]     = (string)$preId;
                $prefillSuccess    = trim($foundAsset['asset_id'] . ' · ' . $foundAsset['brand'] . ' ' . $foundAsset['model']);
            } else {
                $prefillNotice = 'อุปกรณ์ที่สแกนไม่พร้อมให้ยืมตอนนี้ (อาจถูกยืมไปแล้ว หรือไม่เปิดให้ยืม) กรุณาเลือกอุปกรณ์อื่นจากรายการด้านล่าง';
            }
        }
    }
}

/* ── POST ────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    foreach (array_keys($row) as $k) {
        $row[$k] = trim((string)($_POST[$k] ?? ''));
    }

    $isHW    = $row['asset_type'] === 'hw';
    $isNet   = $row['asset_type'] === 'net';
    $assetId  = $isHW  ? (int)$row['asset_id']  : null;
    $mobileId = (!$isHW && !$isNet) ? (int)$row['mobile_id'] : null;
    $networkId= $isNet ? (int)$row['network_id'] : null;

    /* ── Resolve ผู้ยืม (self / other employee / external) ──────────────
       pattern เดียวกับ access-requests/form.php — ยืนยัน employee จาก HR
       เสมอ ไม่เชื่อค่าที่ client ส่งมาตรงๆ (กัน tamper ผ่าน DevTools) */
    $borrowerEmployeeId = null;
    if ($borrowerType === 'self') {
        $borrowerEmployeeId    = $employeeId;
        $row['borrower_ad']    = $employee['person_code'];
        $row['borrower_name']  = $requestorName;
        $row['borrower_dept']  = $employee['department'] ?? '';
        $row['borrower_site_id'] = $employee['site_id'] ?? '';
    } elseif ($borrowerType === 'other') {
        $bfId = isset($_POST['borrower_employee_id']) && ctype_digit((string)$_POST['borrower_employee_id'])
            ? (int)$_POST['borrower_employee_id'] : 0;
        if ($bfId <= 0) {
            $errors['borrower'] = 'กรุณาเลือกพนักงานที่ต้องการยืมให้';
        } else {
            $bfStmt = employee_db()->prepare("
                SELECT title, first_name, last_name, department, site_id, person_code
                FROM employees WHERE id = :id AND resign_status = 'Active'
            ");
            $bfStmt->execute([':id' => $bfId]);
            $bf = $bfStmt->fetch();
            if (!$bf) {
                $errors['borrower'] = 'ไม่พบพนักงานที่เลือก หรือพนักงานไม่ active แล้ว';
            } else {
                $borrowerEmployeeId      = $bfId;
                $row['borrower_ad']      = $bf['person_code'];
                $row['borrower_name']    = trim($bf['title'] . ' ' . $bf['first_name'] . ' ' . $bf['last_name']);
                $row['borrower_dept']    = $bf['department'] ?? '';
                $row['borrower_site_id'] = $bf['site_id'] ?? '';
            }
        }
    }
    // else 'external' — ปล่อยให้ $row['borrower_ad']/['borrower_name']/['borrower_dept']
    // เป็นค่าที่พิมพ์เองจาก $_POST ตามปกติ (ผ่านมาแล้วจาก loop ด้านบน),
    // $borrowerEmployeeId เป็น null ต่อไป

    /* validation */
    if ($isHW  && (int)$row['asset_id']  <= 0)
        $errors['asset_id']  = 'กรุณาเลือก Hardware Asset';
    if (!$isHW && !$isNet && (int)$row['mobile_id'] <= 0)
        $errors['mobile_id'] = 'กรุณาเลือก Mobile Asset';
    if ($isNet && (int)$row['network_id'] <= 0)
        $errors['network_id']= 'กรุณาเลือก Network Device';
    if ($borrowerType === 'external' && $row['borrower_ad'] === '')
        $errors['borrower_ad'] = 'จำเป็นต้องระบุ AD Username';
    if ($borrowerType === 'external' && $row['borrower_name'] === '')
        $errors['borrower_name'] = 'จำเป็นต้องระบุชื่อผู้ยืม';
    if ($row['purpose'] === '')
        $errors['purpose'] = 'จำเป็นต้องระบุวัตถุประสงค์';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['loan_date']))
        $errors['loan_date'] = 'รูปแบบวันที่ไม่ถูกต้อง';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['expected_return']))
        $errors['expected_return'] = 'รูปแบบวันที่ไม่ถูกต้อง';
    if (!$errors && $row['expected_return'] < $row['loan_date'])
        $errors['expected_return'] = 'กำหนดคืนต้องไม่ก่อนวันที่ยืม';
    if (!$errors) {
        $loanDays = (strtotime($row['expected_return']) - strtotime($row['loan_date'])) / 86400;
        if ($loanDays > 90) {
            $errors['expected_return'] = 'ระยะเวลายืมต้องไม่เกิน 90 วัน';
        }
    }

    /* เช็ค overdue ของผู้ยืม — ใช้ borrower_employee_id เป็นหลักถ้ามี (แม่นกว่า
       เทียบ string) fallback ไปที่ borrower_ad เฉพาะกรณีบุคคลภายนอก */
    if (!$errors) {
        if ($borrowerEmployeeId !== null) {
            $overdueChk = $pdo->prepare("
                SELECT COUNT(*) FROM asset_loans
                WHERE borrower_employee_id = :eid AND status = 'Overdue'
            ");
            $overdueChk->execute([':eid' => $borrowerEmployeeId]);
        } else {
            $overdueChk = $pdo->prepare("
                SELECT COUNT(*) FROM asset_loans
                WHERE borrower_ad = :ad AND status = 'Overdue'
            ");
            $overdueChk->execute([':ad' => $row['borrower_ad']]);
        }
        if ($overdueChk->fetchColumn() > 0)
            $errors['borrower_ad'] = 'ผู้ยืมมีรายการเลยกำหนดคืนอยู่ — ไม่อนุญาตยืมเพิ่ม';
    }

    /* เช็ค asset ถูกยืมซ้ำ */
    if (!$errors && $isHW) {
        $dupChk = $pdo->prepare("
            SELECT loan_code FROM asset_loans
            WHERE asset_id = :aid AND status IN ('OnLoan','Approved','Overdue')
        ");
        $dupChk->execute([':aid' => $assetId]);
        if ($dup = $dupChk->fetch())
            $errors['asset_id'] = 'อุปกรณ์นี้ถูกยืมอยู่แล้ว (' . $dup['loan_code'] . ')';
    }
    /* เช็คซ้ำสำหรับ Mobile — เดิมมีแค่ Hardware เท่านั้น (dropdown กรองออกอยู่แล้ว
       ในสถานการณ์ปกติ แต่ต้องมีชั้นป้องกันสุดท้ายฝั่งเซิร์ฟเวอร์กันค่า mobile_id
       ที่ถูกแก้ผ่าน DevTools ให้ชี้ไปที่ asset ที่ถูกยืมอยู่แล้ว) */
    if (!$errors && !$isHW && !$isNet && $mobileId) {
        $dupChk = $pdo->prepare("
            SELECT loan_code FROM asset_loans
            WHERE mobile_id = :mid AND status IN ('OnLoan','Approved','Overdue')
        ");
        $dupChk->execute([':mid' => $mobileId]);
        if ($dup = $dupChk->fetch())
            $errors['mobile_id'] = 'อุปกรณ์นี้ถูกยืมอยู่แล้ว (' . $dup['loan_code'] . ')';
    }
    /* เช็คซ้ำสำหรับ Network — pattern เดียวกัน */
    if (!$errors && $isNet && $networkId) {
        $dupChk = $pdo->prepare("
            SELECT loan_code FROM asset_loans
            WHERE network_asset_id = :nid AND status IN ('OnLoan','Approved','Overdue')
        ");
        $dupChk->execute([':nid' => $networkId]);
        if ($dup = $dupChk->fetch())
            $errors['network_id'] = 'อุปกรณ์นี้ถูกยืมอยู่แล้ว (' . $dup['loan_code'] . ')';
    }

    if (!$errors) {
        /* Generate loan_code LN-YYYY-NNNN */
        $lastCode = $pdo->query("
            SELECT loan_code FROM asset_loans
            WHERE loan_code LIKE 'LN-" . date('Y') . "-%'
            ORDER BY id DESC LIMIT 1
        ")->fetchColumn();
        $seq = $lastCode
            ? (int)substr($lastCode, -4) + 1
            : 1;
        $loanCode = 'LN-' . date('Y') . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);

        try {
            $pdo->prepare("
                INSERT INTO asset_loans
                    (loan_code, asset_id, mobile_id, network_asset_id,
                     borrower_ad, borrower_employee_id, borrower_name, borrower_dept,
                     borrower_site_id, borrower_phone,
                     purpose, purpose_type,
                     loan_date, expected_return,
                     status, notes)
                VALUES
                    (:loan_code, :asset_id, :mobile_id, :network_asset_id,
                     :borrower_ad, :borrower_employee_id, :borrower_name, :borrower_dept,
                     :borrower_site_id, :borrower_phone,
                     :purpose, :purpose_type,
                     :loan_date, :expected_return,
                     'Pending', :notes)
            ")->execute([
                ':loan_code'            => $loanCode,
                ':asset_id'             => $assetId,
                ':mobile_id'            => $mobileId,
                ':network_asset_id'     => $networkId,
                ':borrower_ad'          => $row['borrower_ad'],
                ':borrower_employee_id' => $borrowerEmployeeId,
                ':borrower_name'        => $row['borrower_name'],
                ':borrower_dept'        => $row['borrower_dept'] ?: null,
                ':borrower_site_id'     => $row['borrower_site_id'] ?: null,
                ':borrower_phone'       => $row['borrower_phone'] ?: null,
                ':purpose'              => $row['purpose'],
                ':purpose_type'         => $row['purpose_type'],
                ':loan_date'            => $row['loan_date'],
                ':expected_return'      => $row['expected_return'],
                ':notes'                => $row['notes'] ?: null,
            ]);

            $_SESSION['flash'] = [
                'type'    => 'success',
                'message' => "สร้าง Loan Request {$loanCode} เรียบร้อย — รอการอนุมัติ",
            ];
            header('Location: /it-asset-manager/loans/index.php?tab=pending');
            exit;

        } catch (PDOException $e) {
            error_log('[LOAN CREATE] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

$page_title  = 'New Loan Request';
$active_menu = 'loans';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/loans/index.php"
       class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับรายการ Loan
    </a>
    <h2 class="h5 mb-0 mt-1">New Loan Request</h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>
<?php if ($prefillNotice): ?>
    <div class="alert alert-warning small"><i class="bi bi-exclamation-triangle me-1"></i><?= e($prefillNotice) ?></div>
<?php endif; ?>
<?php if ($prefillSuccess): ?>
    <div class="alert alert-success small"><i class="bi bi-qr-code me-1"></i>เติมข้อมูลจากการสแกนแล้ว: <strong><?= e($prefillSuccess) ?></strong></div>
<?php endif; ?>

<form method="post" novalidate style="max-width:860px;">
<?php csrf_field(); ?>

<!-- ── เลือกอุปกรณ์ ───────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-primary rounded-pill">1</span>
        <strong>เลือกอุปกรณ์ที่ต้องการยืม</strong>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <div class="btn-group" role="group">
                <input type="radio" class="btn-check" name="asset_type"
                       id="type_hw" value="hw"
                       <?= $row['asset_type']==='hw'?'checked':'' ?>>
                <label class="btn btn-outline-primary" for="type_hw">
                    <i class="bi bi-pc-display"></i> Hardware Asset
                </label>
                <input type="radio" class="btn-check" name="asset_type"
                       id="type_mob" value="mob"
                       <?= $row['asset_type']==='mob'?'checked':'' ?>>
                <label class="btn btn-outline-primary" for="type_mob">
                    <i class="bi bi-phone"></i> Mobile / Handheld
                </label>
                <input type="radio" class="btn-check" name="asset_type"
                       id="type_net" value="net"
                       <?= $row['asset_type']==='net'?'checked':'' ?>>
                <label class="btn btn-outline-primary" for="type_net">
                    <i class="bi bi-hdd-network"></i> Network Device
                </label>
            </div>
        </div>

        <!-- HW selector -->
        <div id="hw-selector" <?= $row['asset_type']!=='hw'?'style="display:none"':'' ?>>
            <label class="form-label">
                Hardware Asset (Pool ว่าง <?= count($hwAssets) ?> เครื่อง)
                <span class="text-danger">*</span>
            </label>
            <select name="asset_id"
                    class="form-select <?= isset($errors['asset_id'])?'is-invalid':'' ?>">
                <option value="">— เลือก Asset —</option>
                <?php foreach ($hwAssets as $a): ?>
                    <option value="<?= e($a['id']) ?>"
                        <?= (string)$row['asset_id']===(string)$a['id']?'selected':'' ?>>
                        <?= e($a['asset_id']) ?> ·
                        <?= e($a['brand']) ?> <?= e($a['model']) ?> ·
                        <?= e($a['category']) ?>
                        <?= $a['department'] ? '[' . e($a['department']) . ']' : '' ?>
                        <?= $a['loan_pool_name'] ? '— ' . e($a['loan_pool_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
                <?php if (!$hwAssets): ?>
                    <option disabled>— ไม่มีอุปกรณ์ว่างในขณะนี้ —</option>
                <?php endif; ?>
            </select>
            <?php if (isset($errors['asset_id'])): ?>
                <div class="invalid-feedback"><?= e($errors['asset_id']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Network selector -->
        <div id="net-selector" <?= $row['asset_type']!=='net'?'style="display:none"':'' ?>>
            <label class="form-label">
                Network Device (Pool ว่าง <?= count($netAssets) ?> เครื่อง)
                <span class="text-danger">*</span>
            </label>
            <select name="network_id"
                    class="form-select <?= isset($errors['network_id'])?'is-invalid':'' ?>">
                <option value="">— เลือก Network Device —</option>
                <?php foreach ($netAssets as $n): ?>
                    <option value="<?= e($n['id']) ?>"
                        <?= (string)$row['network_id']===(string)$n['id']?'selected':'' ?>>
                        <?= e($n['asset_id']) ?> ·
                        <?= e($n['brand']) ?> <?= e($n['model']) ?> ·
                        <?= e($n['device_type']) ?>
                        <?= $n['loan_pool_name'] ? '— '.e($n['loan_pool_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
                <?php if (!$netAssets): ?>
                    <option disabled>— ไม่มีอุปกรณ์ว่างในขณะนี้ —</option>
                <?php endif; ?>
            </select>
            <?php if (isset($errors['network_id'])): ?>
                <div class="invalid-feedback"><?= e($errors['network_id']) ?></div>
            <?php endif; ?>
        </div>

        <!-- Mobile selector -->
        <div id="mob-selector" <?= $row['asset_type']!=='mob'?'style="display:none"':'' ?>>
            <label class="form-label">
                Mobile / Handheld (Pool ว่าง <?= count($mobAssets) ?> เครื่อง)
                <span class="text-danger">*</span>
            </label>
            <select name="mobile_id"
                    class="form-select <?= isset($errors['mobile_id'])?'is-invalid':'' ?>">
                <option value="">— เลือก Mobile Asset —</option>
                <?php foreach ($mobAssets as $m): ?>
                    <option value="<?= e($m['id']) ?>"
                        <?= (string)$row['mobile_id']===(string)$m['id']?'selected':'' ?>>
                        <?= e($m['asset_id']) ?> ·
                        <?= e($m['brand']) ?> <?= e($m['model']) ?> ·
                        <?= e($m['device_type']) ?>
                    </option>
                <?php endforeach; ?>
                <?php if (!$mobAssets): ?>
                    <option disabled>— ไม่มีอุปกรณ์ว่างในขณะนี้ —</option>
                <?php endif; ?>
            </select>
            <?php if (isset($errors['mobile_id'])): ?>
                <div class="invalid-feedback"><?= e($errors['mobile_id']) ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── ข้อมูลผู้ยืม ───────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-secondary rounded-pill">2</span>
        <strong>ข้อมูลผู้ยืม</strong>
    </div>
    <div class="card-body">
        <div class="mb-3">
            <label class="form-label">ยืมให้ <span class="text-danger">*</span></label>
            <div class="d-flex gap-3 flex-wrap">
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="borrower_type" value="self"
                           id="brSelf" <?= $borrowerType === 'self' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="brSelf">ตัวเอง (<?= e($requestorName) ?>)</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="borrower_type" value="other"
                           id="brOther" <?= $borrowerType === 'other' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="brOther">พนักงานคนอื่น</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="borrower_type" value="external"
                           id="brExternal" <?= $borrowerType === 'external' ? 'checked' : '' ?>>
                    <label class="form-check-label" for="brExternal">บุคคลภายนอก</label>
                </div>
            </div>
            <?php if (isset($errors['borrower'])): ?>
                <div class="text-danger small mt-1"><?= e($errors['borrower']) ?></div>
            <?php endif; ?>
        </div>

        <!-- ── other: ค้นหาพนักงาน ── -->
        <div class="mb-3" id="borrowerSearchGroup"
             style="<?= $borrowerType === 'other' ? '' : 'display:none;' ?>">
            <label class="form-label">ค้นหาพนักงาน</label>
            <input type="text" id="borrowerSearchInput" class="form-control"
                   placeholder="พิมพ์ชื่อ นามสกุล หรือรหัสพนักงาน" autocomplete="off">
            <div id="borrowerResults" class="list-group mt-2"></div>
            <input type="hidden" name="borrower_employee_id" id="borrowerEmployeeId"
                   value="<?= e($_POST['borrower_employee_id'] ?? '') ?>">
            <div id="borrowerSelectedLabel" class="mt-2 fw-semibold text-success"></div>
        </div>

        <!-- ── external: กรอกเอง ── -->
        <div class="row g-3" id="borrowerExternalGroup"
             style="<?= $borrowerType === 'external' ? '' : 'display:none;' ?>">
            <div class="col-md-4">
                <label class="form-label">AD Username <span class="text-danger">*</span></label>
                <input type="text" name="borrower_ad" maxlength="100"
                       value="<?= $borrowerType === 'external' ? e($row['borrower_ad']) : '' ?>"
                       class="form-control <?= isset($errors['borrower_ad'])?'is-invalid':'' ?>"
                       placeholder="somchai.p">
                <?php if (isset($errors['borrower_ad'])): ?>
                    <div class="invalid-feedback"><?= e($errors['borrower_ad']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">ชื่อผู้ยืม <span class="text-danger">*</span></label>
                <input type="text" name="borrower_name" maxlength="150"
                       value="<?= $borrowerType === 'external' ? e($row['borrower_name']) : '' ?>"
                       class="form-control <?= isset($errors['borrower_name'])?'is-invalid':'' ?>"
                       placeholder="ชื่อ-นามสกุล">
                <?php if (isset($errors['borrower_name'])): ?>
                    <div class="invalid-feedback"><?= e($errors['borrower_name']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">หน่วยงาน/บริษัทต้นสังกัด</label>
                <select name="borrower_dept" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= e($d) ?>"
                            <?= ($borrowerType === 'external' && $row['borrower_dept']===$d)?'selected':'' ?>><?= e($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Site ที่ใช้งาน</label>
                <select name="borrower_site_id" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= e($s['id']) ?>"
                            <?= ($borrowerType === 'external' && (string)$row['borrower_site_id']===(string)$s['id'])?'selected':'' ?>>
                            <?= e($s['site_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-md-4">
                <label class="form-label">เบอร์โทร</label>
                <input type="text" name="borrower_phone" maxlength="30"
                       value="<?= e($row['borrower_phone']) ?>"
                       class="form-control" placeholder="086-xxx-xxxx">
            </div>
        </div>
    </div>
</div>

<!-- ── วัตถุประสงค์และกำหนดการ ───────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="badge text-bg-success rounded-pill">3</span>
        <strong>วัตถุประสงค์และกำหนดการ</strong>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">ประเภทการยืม</label>
                <select name="purpose_type" class="form-select">
                    <?php foreach ($purposeTypes as $pt): ?>
                        <option value="<?= e($pt) ?>"
                            <?= $row['purpose_type']===$pt?'selected':'' ?>><?= e($pt) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8">
                <label class="form-label">วัตถุประสงค์ <span class="text-danger">*</span></label>
                <input type="text" name="purpose" maxlength="255"
                       value="<?= e($row['purpose']) ?>"
                       class="form-control <?= isset($errors['purpose'])?'is-invalid':'' ?>"
                       placeholder="Present ลูกค้า / ทดแทนเครื่องส่งซ่อม / งาน Inventory WH-A">
                <?php if (isset($errors['purpose'])): ?>
                    <div class="invalid-feedback"><?= e($errors['purpose']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">วันที่ยืม <span class="text-danger">*</span></label>
                <input type="date" name="loan_date"
                       value="<?= e($row['loan_date']) ?>"
                       class="form-control <?= isset($errors['loan_date'])?'is-invalid':'' ?>">
                <?php if (isset($errors['loan_date'])): ?>
                    <div class="invalid-feedback"><?= e($errors['loan_date']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">กำหนดคืน <span class="text-danger">*</span></label>
                <input type="date" name="expected_return"
                       value="<?= e($row['expected_return']) ?>"
                       class="form-control <?= isset($errors['expected_return'])?'is-invalid':'' ?>">
                <?php if (isset($errors['expected_return'])): ?>
                    <div class="invalid-feedback"><?= e($errors['expected_return']) ?></div>
                <?php endif; ?>
                <div class="form-text">ระยะเวลาไม่เกิน 90 วัน</div>
            </div>
            <div class="col-12">
                <label class="form-label">หมายเหตุ</label>
                <textarea name="notes" rows="2" class="form-control"
                          placeholder="ข้อมูลเพิ่มเติม"><?= e($row['notes']) ?></textarea>
            </div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end gap-2 mb-5">
    <a href="/it-asset-manager/loans/index.php" class="btn btn-outline-secondary">
        <i class="bi bi-x-lg"></i> ยกเลิก
    </a>
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-plus-lg"></i> สร้าง Loan Request
    </button>
</div>

</form>

<script>
document.querySelectorAll('input[name="asset_type"]').forEach(r => {
    r.addEventListener('change', function() {
        document.getElementById('hw-selector').style.display  = this.value==='hw'  ? '' : 'none';
        document.getElementById('mob-selector').style.display = this.value==='mob' ? '' : 'none';
        document.getElementById('net-selector').style.display = this.value==='net' ? '' : 'none';
    });
});

// ------ Borrower: toggle self/other/external ------
const brSelf     = document.getElementById('brSelf');
const brOther    = document.getElementById('brOther');
const brExternal = document.getElementById('brExternal');
const brSearchGroup   = document.getElementById('borrowerSearchGroup');
const brExternalGroup = document.getElementById('borrowerExternalGroup');

function toggleBorrower() {
    brSearchGroup.style.display   = brOther.checked    ? '' : 'none';
    brExternalGroup.style.display = brExternal.checked ? '' : 'none';
}
brSelf.addEventListener('change', toggleBorrower);
brOther.addEventListener('change', toggleBorrower);
brExternal.addEventListener('change', toggleBorrower);

// ------ Borrower: live search พนักงาน (ใช้ endpoint เดียวกับ access-requests
//        เพราะ query/role เหมือนกันเป๊ะ ไม่ต้องสร้างไฟล์ซ้ำ) ------
const brInput  = document.getElementById('borrowerSearchInput');
const brResult = document.getElementById('borrowerResults');
const brHidden = document.getElementById('borrowerEmployeeId');
const brLabel  = document.getElementById('borrowerSelectedLabel');

let brTimer = null;
brInput?.addEventListener('input', () => {
    clearTimeout(brTimer);
    const q = brInput.value.trim();
    brResult.innerHTML = '';
    if (q.length < 2) return;
    brTimer = setTimeout(() => {
        fetch('/it-asset-manager/access-requests/employee_search_ajax.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(list => {
                brResult.innerHTML = '';
                list.forEach(item => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action';
                    btn.innerHTML = '<div class="fw-semibold">' + item.label + '</div>'
                        + '<div class="text-muted small">' + item.sub + '</div>';
                    btn.addEventListener('click', () => {
                        brHidden.value = item.id;
                        brLabel.textContent = 'เลือกแล้ว: ' + item.label;
                        brResult.innerHTML = '';
                        brInput.value = item.label;
                    });
                    brResult.appendChild(btn);
                });
            });
    }, 300);
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
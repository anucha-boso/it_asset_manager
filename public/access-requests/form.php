<?php
/**
 * คำขอบริการ IT — สร้างคำขอใหม่
 * public/access-requests/form.php
 *
 * เข้าได้ทุก role ที่ login (self-service) — ต้องผูก employee ก่อน (require_employee_link)
 *
 * 2026-10 redesign:
 *  - ประเภทคำขอ: SYSTEM (ขอสิทธิ์ใช้งานระบบ) / EQUIPMENT (ขออุปกรณ์ IT) อ่านจาก access_applications.category
 *    ** ต้องรัน migration_20261010_access_memo.sql และ migration_20261010_access_category.sql ก่อน **
 *  - Layout 2 คอลัมน์ + แผงสรุปแสดงลำดับผู้อนุมัติของรายการที่เลือก
 *  - เลขที่ e-memo (memo_no): ตัวพิมพ์ใหญ่, ตรวจรูปแบบ, บังคับเฉพาะรายการที่ requires_memo = 1
 *  - EQUIPMENT: ต้องเลือกรุ่น (ถ้ารายการมีรุ่นให้เลือก), ช่อง access_level_text ใช้เป็น "สเปกเพิ่มเติม"
 *  - Security: ผลค้นหาพนักงานใช้ textContent แทน innerHTML (กัน XSS)
 *  - ปีของเลข request ใช้ YEAR(NOW()) จาก MySQL แทน date('Y') (timezone PHP-FPM ไม่ตรงกับ DB)
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/require_employee_link.php';
require_once __DIR__ . '/../../includes/employee_log.php';
require_once __DIR__ . '/../../config/notification.php';
require_once __DIR__ . '/../../includes/module_access.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);
require_module_access('ACCESS_REQUESTS');

$pdo        = db();
$employeeId = require_employee_link(); // เด้งไป link-me.php อัตโนมัติถ้ายังไม่ผูก

// รูปแบบเลข e-memo เช่น PACS-WH-GN-202610-0013 (Site-แผนก-ประเภท-ปีเดือน-ลำดับ)
const MEMO_PATTERN_PHP = '/^[A-Z]{2,5}-[A-Z]{2,4}-[A-Z]{2,4}-\d{6}-\d{4}$/';
const MEMO_PATTERN_JS  = '^[A-Z]{2,5}-[A-Z]{2,4}-[A-Z]{2,4}-\d{6}-\d{4}$';
const MEMO_EXAMPLE     = 'PACS-WH-GN-202610-0013';

// ข้อความที่เปลี่ยนตามประเภทคำขอ (ใช้ทั้ง PHP และ JS)
$categoryText = [
    'SYSTEM' => [
        'choice'      => 'สิทธิ์ใช้งานระบบ',
        'choice_sub'  => 'โปรแกรม, Email, VPN, กล้อง ฯลฯ',
        'item'        => 'ระบบ',
        'item_pick'   => '— เลือกระบบ —',
        'level'       => 'Access Level / Role',
        'level_text'  => 'ระบุ Access Level',
        'reason_ph'   => 'เช่น พนักงานใหม่ประจำคลัง PCS ต้องใช้ทำรับ-จ่ายสินค้า เริ่มงาน 15 ต.ค.',
    ],
    'EQUIPMENT' => [
        'choice'      => 'อุปกรณ์ IT',
        'choice_sub'  => 'คอมพิวเตอร์ และอุปกรณ์อื่น ๆ',
        'item'        => 'อุปกรณ์',
        'item_pick'   => '— เลือกอุปกรณ์ —',
        'level'       => 'รุ่น / ประเภท',
        'level_text'  => 'สเปกหรือความต้องการเพิ่มเติม (ถ้ามี)',
        'reason_ph'   => 'เช่น พนักงานใหม่ตำแหน่ง Planner เริ่มงาน 15 ต.ค. / เครื่องเดิมเสียซ่อมไม่คุ้ม',
    ],
];

// ------ ดึงข้อมูลพนักงานเจ้าของคำขอ (snapshot ตอนสร้าง) ------
$empStmt = employee_db()->prepare("
    SELECT title, first_name, last_name, department, site_id
    FROM employees WHERE id = :id
");
$empStmt->execute([':id' => $employeeId]);
$employee = $empStmt->fetch();
if (!$employee) {
    http_response_code(500);
    exit('ไม่พบข้อมูลพนักงาน กรุณาติดต่อ IT Admin');
}
$requestorName = trim($employee['title'] . ' ' . $employee['first_name'] . ' ' . $employee['last_name']);

// ------ ค่า default ของ beneficiary (ตอนเปิดฟอร์มครั้งแรก = ตัวเอง) ------
$beneficiaryType = (string)($_POST['beneficiary_type'] ?? 'self');
if (!in_array($beneficiaryType, ['self', 'other', 'external'], true)) {
    $beneficiaryType = 'self';
}

// ------ Master data สำหรับฟอร์ม ------
$applications = $pdo->query("
    SELECT id, app_code, app_name, category, requires_approver2, requires_memo,
           default_approver1_employee_id, default_approver2_employee_id
    FROM access_applications
    WHERE is_active = 1
    ORDER BY app_name
")->fetchAll();

$levels = $pdo->query("
    SELECT id, application_id, level_name
    FROM access_application_levels
    WHERE is_active = 1
    ORDER BY level_name
")->fetchAll();

// จัดกลุ่ม levels ตาม application_id ไว้ให้ JS ใช้ (dependent dropdown)
$levelsByApp = [];
foreach ($levels as $lv) {
    $levelsByApp[(int)$lv['application_id']][] = ['id' => (int)$lv['id'], 'level_name' => $lv['level_name']];
}

// ------ ชื่อผู้อนุมัติ default ของทุกรายการ (query เดียว) — ใช้แสดงลำดับอนุมัติในแผงสรุป ------
$approverIds = [];
foreach ($applications as $a) {
    foreach (['default_approver1_employee_id', 'default_approver2_employee_id'] as $col) {
        if (!empty($a[$col])) $approverIds[(int)$a[$col]] = true;
    }
}
$approverNames = [];
if ($approverIds) {
    $ids = array_keys($approverIds);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $nStmt = employee_db()->prepare("SELECT id, title, first_name, last_name FROM employees WHERE id IN ({$in})");
    $nStmt->execute($ids);
    foreach ($nStmt->fetchAll() as $n) {
        $approverNames[(int)$n['id']] = trim($n['title'] . ' ' . $n['first_name'] . ' ' . $n['last_name']);
    }
}

// รายการต่อประเภท (คงลำดับตามชื่อ) + meta ส่งให้ JS
$appList = ['SYSTEM' => [], 'EQUIPMENT' => []];
$appMeta = [];
foreach ($applications as $a) {
    $cat = isset($appList[$a['category']]) ? $a['category'] : 'SYSTEM';
    $appList[$cat][] = ['id' => (int)$a['id'], 'name' => $a['app_name']];

    $chain = [];
    $a1 = (int)($a['default_approver1_employee_id'] ?? 0);
    $chain[] = ['role' => 'Approver 1', 'name' => $a1 ? ($approverNames[$a1] ?? '—') : 'ยังไม่ได้ตั้งค่า'];
    $a2 = (int)($a['default_approver2_employee_id'] ?? 0);
    if ((int)$a['requires_approver2'] === 1 && $a2) {
        $chain[] = ['role' => 'Approver 2', 'name' => $approverNames[$a2] ?? '—'];
    }
    $appMeta[(int)$a['id']] = [
        'name'          => $a['app_name'],
        'category'      => $cat,
        'requires_memo' => (int)$a['requires_memo'] === 1,
        'chain'         => $chain,
    ];
}
$hasEquipment = count($appList['EQUIPMENT']) > 0;

$sites = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();

$errors           = [];
$benSelectedLabel = '';   // ชื่อพนักงานที่เลือกไว้ (กรณี validate ไม่ผ่าน จะได้ไม่ต้องค้นใหม่)
$selectedSiteId   = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string)($_POST['site_id'] ?? '')
    : (string)($employee['site_id'] ?? '');

// ประเภทคำขอที่เลือก: POST > ?type=equipment (ลิงก์ตรง) > SYSTEM
$requestCategory = strtoupper((string)($_POST['request_category'] ?? ($_GET['type'] ?? 'SYSTEM')));
if (!isset($appList[$requestCategory]) || ($requestCategory === 'EQUIPMENT' && !$hasEquipment)) {
    $requestCategory = 'SYSTEM';
}

// ------------------------------------------------------------------
//  POST: บันทึกคำขอ + สร้าง approval chain
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $appId     = (int)($_POST['application_id'] ?? 0);
    $siteId    = ctype_digit((string)($_POST['site_id'] ?? '')) ? (int)$_POST['site_id'] : null;
    $levelId   = ctype_digit((string)($_POST['access_level_id'] ?? '')) ? (int)$_POST['access_level_id'] : null;
    $levelText = trim((string)($_POST['access_level_text'] ?? ''));
    $reason    = trim((string)($_POST['reason'] ?? ''));
    // memo: ตัดช่องว่างทั้งหมด + ตัวพิมพ์ใหญ่ (ผู้ใช้มัก copy มาพร้อม space)
    $memoNo    = strtoupper((string)preg_replace('/\s+/', '', (string)($_POST['memo_no'] ?? '')));

    if ($appId <= 0) $errors['application_id'] = 'กรุณาเลือก' . $categoryText[$requestCategory]['item'];
    if ($reason === '') $errors['reason'] = 'กรุณาระบุเหตุผล';

    $app = null;
    if ($appId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM access_applications WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $appId]);
        $app = $stmt->fetch();
        if (!$app) $errors['application_id'] = 'ไม่พบรายการที่เลือก หรือถูกปิดใช้งาน';
    }

    if ($app && !$app['default_approver1_employee_id']) {
        $errors['application_id'] = 'รายการนี้ยังไม่ได้ตั้งค่าผู้อนุมัติ กรุณาติดต่อ IT Admin';
    }

    // ------ ถ้าเลือก level จาก dropdown ต้องตรวจว่าเป็นของ application ที่เลือกจริง ------
    if ($levelId && $app) {
        $lvChk = $pdo->prepare("SELECT id FROM access_application_levels WHERE id = :id AND application_id = :app_id");
        $lvChk->execute([':id' => $levelId, ':app_id' => $appId]);
        if (!$lvChk->fetchColumn()) {
            $errors['access_level'] = 'รายการที่เลือกไม่ตรงกัน กรุณาเลือกใหม่';
        }
    }

    // ------ อุปกรณ์ที่มีรุ่นให้เลือก ต้องเลือกรุ่นเสมอ (เช่น Computer → Desktop / Laptop) ------
    if ($app && $app['category'] === 'EQUIPMENT' && !empty($levelsByApp[$appId]) && !$levelId) {
        $errors['access_level'] = 'กรุณาเลือกรุ่น / ประเภทอุปกรณ์';
    }

    // ------ e-memo: บังคับเฉพาะรายการที่ตั้ง requires_memo, ถ้ากรอกต้องถูกรูปแบบเสมอ ------
    if ($app && (int)$app['requires_memo'] === 1 && $memoNo === '') {
        $errors['memo_no'] = 'รายการนี้ต้องแนบเลขที่ e-memo ที่ผู้บริหารอนุมัติแล้ว';
    } elseif ($memoNo !== '' && !preg_match(MEMO_PATTERN_PHP, $memoNo)) {
        $errors['memo_no'] = 'รูปแบบเลขที่ e-memo ไม่ถูกต้อง (ตัวอย่าง ' . MEMO_EXAMPLE . ')';
    }

    // ------ Resolve Beneficiary ------
    $beneficiaryEmployeeId      = null;
    $beneficiaryName            = null;
    $beneficiaryDept            = null;
    $beneficiaryExternalOrg     = null;
    $beneficiaryExternalContact = null;

    if ($beneficiaryType === 'self') {
        $beneficiaryEmployeeId = $employeeId;
        $beneficiaryName       = $requestorName;
        $beneficiaryDept       = $employee['department'];

    } elseif ($beneficiaryType === 'external') {
        $extName    = trim((string)($_POST['beneficiary_external_name'] ?? ''));
        $extOrg     = trim((string)($_POST['beneficiary_external_org'] ?? ''));
        $extContact = trim((string)($_POST['beneficiary_external_contact'] ?? ''));

        if ($extName === '' || $extOrg === '' || $extContact === '') {
            $errors['beneficiary'] = 'กรุณากรอกชื่อ สังกัด และช่องทางติดต่อของบุคคลภายนอกให้ครบ';
        } else {
            $beneficiaryEmployeeId      = null; // ไม่มี employee_id เพราะเป็นบุคคลภายนอก
            $beneficiaryName            = $extName;
            $beneficiaryDept            = null;
            $beneficiaryExternalOrg     = $extOrg;
            $beneficiaryExternalContact = $extContact;
        }

    } else { // 'other' — พนักงานคนอื่นในระบบ
        $bfId = isset($_POST['beneficiary_employee_id']) && ctype_digit((string)$_POST['beneficiary_employee_id'])
            ? (int)$_POST['beneficiary_employee_id'] : 0;

        if ($bfId <= 0) {
            $errors['beneficiary'] = 'กรุณาเลือกพนักงานที่ต้องการขอให้';
        } else {
            $bfStmt = employee_db()->prepare("
                SELECT title, first_name, last_name, department
                FROM employees WHERE id = :id AND resign_status = 'Active'
            ");
            $bfStmt->execute([':id' => $bfId]);
            $bf = $bfStmt->fetch();
            if (!$bf) {
                $errors['beneficiary'] = 'ไม่พบพนักงานที่เลือก';
            } else {
                $beneficiaryEmployeeId = $bfId;
                $beneficiaryName       = trim($bf['title'] . ' ' . $bf['first_name'] . ' ' . $bf['last_name']);
                $beneficiaryDept       = $bf['department'];
                $benSelectedLabel      = $beneficiaryName;
            }
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            // ใช้ปีจาก MySQL ให้ตรงกับ requested_at (PHP-FPM timezone ไม่ตรงกับ DB)
            $year = (string)$pdo->query("SELECT YEAR(NOW())")->fetchColumn();
            $countStmt = $pdo->prepare("SELECT COUNT(*)+1 FROM access_requests WHERE YEAR(requested_at) = :y");
            $countStmt->execute([':y' => $year]);
            $requestNo = 'AR-' . $year . '-' . str_pad((string)$countStmt->fetchColumn(), 6, '0', STR_PAD_LEFT);

            $ins = $pdo->prepare("
                INSERT INTO access_requests
                    (request_no, application_id, requestor_employee_id,
                     beneficiary_employee_id, beneficiary_name, beneficiary_department,
                     beneficiary_external_org, beneficiary_external_contact,
                     requestor_name, department,
                     site_id, access_level_id, access_level_text, reason, memo_no, status)
                VALUES
                    (:no, :app_id, :emp_id,
                     :ben_id, :ben_name, :ben_dept,
                     :ben_org, :ben_contact,
                     :r_name, :dept,
                     :site_id, :level_id, :level_text, :reason, :memo_no, 'Pending Approver1')
            ");
            $ins->execute([
                ':no'          => $requestNo,
                ':app_id'      => $appId,
                ':emp_id'      => $employeeId,
                ':ben_id'      => $beneficiaryEmployeeId,
                ':ben_name'    => $beneficiaryName,
                ':ben_dept'    => $beneficiaryDept,
                ':ben_org'     => $beneficiaryExternalOrg,
                ':ben_contact' => $beneficiaryExternalContact,
                ':r_name'      => $requestorName,
                ':dept'        => $employee['department'],
                ':site_id'     => $siteId,
                ':level_id'    => $levelId,
                ':level_text'  => $levelText !== '' ? $levelText : null,
                ':reason'      => $reason,
                ':memo_no'     => $memoNo !== '' ? $memoNo : null,
            ]);
            $requestId = (int)$pdo->lastInsertId();

            // ------ step 1: Approver1 (บังคับมีเสมอ) ------
            $stepStmt = $pdo->prepare("
                INSERT INTO access_request_steps (request_id, step_order, step_role, assignee_employee_id)
                VALUES (:rid, :ord, :role, :eid)
            ");
            $stepStmt->execute([
                ':rid' => $requestId, ':ord' => 1, ':role' => 'APPROVER1',
                ':eid' => $app['default_approver1_employee_id'],
            ]);
            $step1Id = (int)$pdo->lastInsertId();
            // ------ เตรียม email สำหรับแจ้งเตือน Approver1 (ยิงหลัง commit สำเร็จ) ------
            $approver1Emails = getEmployeeEmails([(int)$app['default_approver1_employee_id']]);

            // ------ step 2: Approver2 (เฉพาะรายการที่ต้องการ) ------
            if ((int)$app['requires_approver2'] === 1 && $app['default_approver2_employee_id']) {
                $stepStmt->execute([
                    ':rid' => $requestId, ':ord' => 2, ':role' => 'APPROVER2',
                    ':eid' => $app['default_approver2_employee_id'],
                ]);
            }

            // ------ step สุดท้าย: IT Action (ไม่ผูกคนตายตัว ใครใน it_admin/it_staff action ได้) ------
            $stepStmt->execute([
                ':rid' => $requestId, ':ord' => 3, ':role' => 'IT_ACTION', ':eid' => null,
            ]);

            $pdo->prepare("UPDATE access_requests SET current_step_id = :sid WHERE id = :rid")
                ->execute([':sid' => $step1Id, ':rid' => $requestId]);

            // ── History log (เฟส 2) — log เข้าประวัติของ "ผู้รับ" ไม่ใช่ผู้ยื่น (beneficiary-only)
            if ($beneficiaryEmployeeId !== null) {
                logEmployeeTransaction(
                    $pdo,
                    $beneficiaryEmployeeId,
                    'Access Request Submitted',
                    'access_requests',
                    $requestId,
                    'Request submitted: ' . $app['app_name'] . ($levelText !== '' ? ' (' . $levelText . ')' : ''),
                    $_SESSION['iam_username'] ?? null
                );
            }

            $pdo->commit();
            // ------ แจ้งเตือน Approver1 (ถ้ามี email เก็บไว้) ------
            if ($approver1Emails) {
                notifyEvent('ACCESS_REQUEST_SUBMITTED', [
                    'asset'     => $app['app_name'],
                    'reporter'  => $requestorName,
                    'issues'    => [$reason],
                    'ticket_no' => $requestNo,
                    'note'      => "ขอให้: {$beneficiaryName}"
                                 . ($levelText !== '' ? " · {$levelText}" : '')
                                 . ($memoNo !== '' ? " · e-memo: {$memoNo}" : ''),
                    'to_email'  => $approver1Emails,
                ]);
            } else {
                error_log("[NOTIFY SKIP] Approver1 (employee_id={$app['default_approver1_employee_id']}) has no email in employee_contacts — request {$requestNo}");
            }

            $_SESSION['flash'] = ['type' => 'success', 'message' => "ส่งคำขอ {$requestNo} เรียบร้อยแล้ว"];
            header('Location: /it-asset-manager/access-requests/detail.php?id=' . $requestId);
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[AR SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ กรุณาลองใหม่';
        }
    }
}

// ค่าที่ต้องคืนกลับเข้าฟอร์มหลัง validate ไม่ผ่าน
$old = fn(string $k): string => (string)($_POST[$k] ?? '');
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$ct = $categoryText[$requestCategory];

$page_title  = 'คำขอบริการ IT';
$active_menu = 'access_requests';
require __DIR__ . '/../../includes/header.php';
?>

<style>
/* ---- IT Request form (scoped ด้วย prefix ar-) ---- */
.ar-section-no { width:26px; height:26px; border-radius:50%; background:#0F5E73; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700; flex:none; }
.ar-choice { position:relative; }
.ar-choice input { position:absolute; opacity:0; }
.ar-choice label { display:block; height:100%; border:1px solid #CBD2D9; border-radius:10px; padding:.75rem .9rem; cursor:pointer; background:#fff; }
.ar-choice label .ar-choice-sub { font-size:.8rem; color:#616E7C; }
.ar-choice input:checked + label { border:2px solid #0F5E73; background:#F2F9FA; padding:calc(.75rem - 1px) calc(.9rem - 1px); }
.ar-choice input:focus-visible + label { outline:2px solid #0F5E73; outline-offset:2px; }
.ar-summary { position:sticky; top:1rem; }
.ar-sum-label { font-size:.75rem; color:#616E7C; }
.ar-chain-dot { width:22px; height:22px; border-radius:50%; background:#EEF1F4; color:#52606D; font-size:.75rem; font-weight:600; display:inline-flex; align-items:center; justify-content:center; flex:none; }
.ar-memo { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; text-transform:uppercase; letter-spacing:.02em; }
</style>

<div class="mb-3">
    <a href="/it-asset-manager/access-requests/index.php" class="text-decoration-none small">
        <i class="bi bi-arrow-left"></i> กลับไปหน้ารายการ
    </a>
    <h2 class="h4 fw-bold mb-0 mt-2">คำขอบริการ IT</h2>
    <div class="text-muted small">ผู้ยื่น: <?= e($requestorName) ?> · <?= e($employee['department'] ?? '—') ?></div>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php elseif ($errors): ?>
    <div class="alert alert-warning">กรุณาตรวจสอบช่องที่มีข้อความสีแดง</div>
<?php endif; ?>

<form method="post" id="arForm" novalidate>
<?php csrf_field(); ?>
<div class="row g-4 align-items-start">

    <!-- ================= ฟอร์ม ================= -->
    <div class="col-lg-8">

        <!-- 1) ต้องการขออะไร -->
        <div class="card mb-3">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="ar-section-no">1</span>
                    <span class="fw-semibold">ต้องการขออะไร</span>
                </div>
                <div class="row g-2">
                    <div class="col-md-6 ar-choice">
                        <input type="radio" name="request_category" value="SYSTEM" id="catSystem" <?= $requestCategory === 'SYSTEM' ? 'checked' : '' ?>>
                        <label for="catSystem">
                            <div class="fw-semibold"><i class="bi bi-key"></i> <?= e($categoryText['SYSTEM']['choice']) ?></div>
                            <div class="ar-choice-sub"><?= e($categoryText['SYSTEM']['choice_sub']) ?></div>
                        </label>
                    </div>
                    <div class="col-md-6 ar-choice">
                        <input type="radio" name="request_category" value="EQUIPMENT" id="catEquipment"
                               <?= $requestCategory === 'EQUIPMENT' ? 'checked' : '' ?> <?= $hasEquipment ? '' : 'disabled' ?>>
                        <label for="catEquipment" <?= $hasEquipment ? '' : 'style="opacity:.55;cursor:not-allowed"' ?>>
                            <div class="fw-semibold"><i class="bi bi-laptop"></i> <?= e($categoryText['EQUIPMENT']['choice']) ?></div>
                            <div class="ar-choice-sub"><?= $hasEquipment ? e($categoryText['EQUIPMENT']['choice_sub']) : 'ยังไม่เปิดให้ขอ' ?></div>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2) ขอให้ใคร -->
        <div class="card mb-3">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="ar-section-no">2</span>
                    <span class="fw-semibold">ขอให้ใคร</span>
                </div>

                <div class="row g-2 mb-3">
                    <div class="col-md-4 ar-choice">
                        <input type="radio" name="beneficiary_type" value="self" id="benSelf" <?= $beneficiaryType === 'self' ? 'checked' : '' ?>>
                        <label for="benSelf">
                            <div class="fw-semibold"><i class="bi bi-person"></i> ตัวเอง</div>
                            <div class="ar-choice-sub"><?= e($requestorName) ?></div>
                        </label>
                    </div>
                    <div class="col-md-4 ar-choice">
                        <input type="radio" name="beneficiary_type" value="other" id="benOther" <?= $beneficiaryType === 'other' ? 'checked' : '' ?>>
                        <label for="benOther">
                            <div class="fw-semibold"><i class="bi bi-people"></i> พนักงานคนอื่น</div>
                            <div class="ar-choice-sub">ค้นหาจากรายชื่อพนักงาน</div>
                        </label>
                    </div>
                    <div class="col-md-4 ar-choice">
                        <input type="radio" name="beneficiary_type" value="external" id="benExternal" <?= $beneficiaryType === 'external' ? 'checked' : '' ?>>
                        <label for="benExternal">
                            <div class="fw-semibold"><i class="bi bi-building"></i> บุคคลภายนอก</div>
                            <div class="ar-choice-sub">ผู้รับเหมา / Vendor</div>
                        </label>
                    </div>
                </div>

                <!-- พนักงานคนอื่น -->
                <div id="beneficiarySearchGroup" class="<?= $beneficiaryType === 'other' ? '' : 'd-none' ?>">
                    <label for="beneficiarySearchInput" class="form-label small fw-semibold">ค้นหาพนักงาน</label>
                    <div class="position-relative">
                        <input type="text" id="beneficiarySearchInput" class="form-control"
                               placeholder="พิมพ์ชื่อ นามสกุล หรือรหัสพนักงาน (อย่างน้อย 2 ตัวอักษร)"
                               value="<?= e($benSelectedLabel) ?>" autocomplete="off">
                        <div id="beneficiaryResults" class="list-group position-absolute w-100 shadow-sm" style="z-index:20"></div>
                    </div>
                    <input type="hidden" name="beneficiary_employee_id" id="beneficiaryEmployeeId"
                           value="<?= e($old('beneficiary_employee_id')) ?>">
                    <div id="beneficiarySelectedLabel" class="mt-2 small fw-semibold text-success">
                        <?php if ($benSelectedLabel !== ''): ?>✓ เลือกแล้ว: <?= e($benSelectedLabel) ?><?php endif; ?>
                    </div>
                </div>

                <!-- บุคคลภายนอก -->
                <div id="beneficiaryExternalGroup" class="<?= $beneficiaryType === 'external' ? '' : 'd-none' ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="extName" class="form-label small fw-semibold">ชื่อ-นามสกุล <span class="text-danger">*</span></label>
                            <input type="text" id="extName" name="beneficiary_external_name" class="form-control"
                                   value="<?= e($old('beneficiary_external_name')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label for="extOrg" class="form-label small fw-semibold">บริษัท / หน่วยงานต้นสังกัด <span class="text-danger">*</span></label>
                            <input type="text" id="extOrg" name="beneficiary_external_org" class="form-control"
                                   value="<?= e($old('beneficiary_external_org')) ?>">
                        </div>
                        <div class="col-12">
                            <label for="extContact" class="form-label small fw-semibold">เบอร์โทร / Email <span class="text-danger">*</span></label>
                            <input type="text" id="extContact" name="beneficiary_external_contact" class="form-control"
                                   value="<?= e($old('beneficiary_external_contact')) ?>">
                        </div>
                    </div>
                </div>

                <?php if (isset($errors['beneficiary'])): ?>
                    <div class="text-danger small mt-2"><?= e($errors['beneficiary']) ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3) รายละเอียด -->
        <div class="card mb-3">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="ar-section-no">3</span>
                    <span class="fw-semibold">รายละเอียด</span>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label for="applicationSelect" class="form-label small fw-semibold">
                            <span data-ct="item"><?= e($ct['item']) ?></span> <span class="text-danger">*</span>
                        </label>
                        <!-- options สร้างด้วย JS ตามประเภทที่เลือก; ค่า fallback ด้านล่างไว้กรณี JS ไม่ทำงาน -->
                        <select name="application_id" id="applicationSelect"
                                class="form-select <?= isset($errors['application_id']) ? 'is-invalid' : '' ?>">
                            <option value=""><?= e($ct['item_pick']) ?></option>
                            <?php foreach ($appList[$requestCategory] as $a): ?>
                                <option value="<?= e((string)$a['id']) ?>" <?= (int)$old('application_id') === $a['id'] ? 'selected' : '' ?>>
                                    <?= e($a['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (isset($errors['application_id'])): ?>
                            <div class="invalid-feedback"><?= e($errors['application_id']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="col-md-6">
                        <label for="siteSelect" class="form-label small fw-semibold">Site ที่ใช้งาน</label>
                        <select name="site_id" id="siteSelect" class="form-select">
                            <option value="">— ไม่ระบุ —</option>
                            <?php foreach ($sites as $s): ?>
                                <option value="<?= e((string)$s['id']) ?>" <?= $selectedSiteId === (string)$s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['site_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-6 d-none" id="levelGroup">
                        <label for="levelSelect" class="form-label small fw-semibold">
                            <span data-ct="level"><?= e($ct['level']) ?></span>
                            <span class="text-danger d-none" id="levelReqMark">*</span>
                        </label>
                        <select name="access_level_id" id="levelSelect"
                                class="form-select <?= isset($errors['access_level']) ? 'is-invalid' : '' ?>"></select>
                        <div class="invalid-feedback" id="levelFeedback"><?= e($errors['access_level'] ?? 'กรุณาเลือกรุ่น / ประเภทอุปกรณ์') ?></div>
                    </div>

                    <div class="col-md-6 d-none" id="levelTextGroup">
                        <label for="levelText" class="form-label small fw-semibold" id="levelTextLabel"><?= e($ct['level_text']) ?></label>
                        <input type="text" name="access_level_text" id="levelText" class="form-control"
                               value="<?= e($old('access_level_text')) ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- 4) เหตุผลและเอกสารอ้างอิง -->
        <div class="card mb-3">
            <div class="card-body p-4">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="ar-section-no">4</span>
                    <span class="fw-semibold">เหตุผลและเอกสารอ้างอิง</span>
                </div>

                <div class="mb-3">
                    <label for="reasonBox" class="form-label small fw-semibold">เหตุผลในการขอ <span class="text-danger">*</span></label>
                    <textarea name="reason" id="reasonBox" rows="3"
                              class="form-control <?= isset($errors['reason']) ? 'is-invalid' : '' ?>"
                              placeholder="<?= e($ct['reason_ph']) ?>"><?= e($old('reason')) ?></textarea>
                    <?php if (isset($errors['reason'])): ?>
                        <div class="invalid-feedback"><?= e($errors['reason']) ?></div>
                    <?php endif; ?>
                </div>

                <div>
                    <label for="memoNo" class="form-label small fw-semibold">
                        เลขที่ e-memo <span id="memoReqMark" class="text-danger d-none">*</span>
                        <span id="memoOptMark" class="text-muted fw-normal">(ถ้ามี)</span>
                    </label>
                    <input type="text" name="memo_no" id="memoNo" maxlength="30"
                           class="form-control ar-memo <?= isset($errors['memo_no']) ? 'is-invalid' : '' ?>"
                           value="<?= e($old('memo_no')) ?>" placeholder="<?= e(MEMO_EXAMPLE) ?>"
                           pattern="<?= e(MEMO_PATTERN_JS) ?>" autocomplete="off" spellcheck="false">
                    <div class="invalid-feedback" id="memoFeedback">
                        <?= e($errors['memo_no'] ?? ('รูปแบบไม่ถูกต้อง (ตัวอย่าง ' . MEMO_EXAMPLE . ')')) ?>
                    </div>
                    <div class="form-text" id="memoHelp">เลขเอกสารที่ผู้บริหารอนุมัติในระบบ e-memo ใช้ค้นย้อนหลัง</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= แผงสรุป ================= -->
    <div class="col-lg-4">
        <div class="ar-summary">
            <div class="card shadow-sm">
                <div class="card-body p-4">
                    <div class="small fw-semibold text-muted mb-3">สรุปคำขอ</div>

                    <div class="mb-2">
                        <div class="ar-sum-label">ประเภท</div>
                        <div class="fw-semibold" id="sumCat"><?= e($ct['choice']) ?></div>
                    </div>
                    <div class="mb-2">
                        <div class="ar-sum-label">ผู้รับ</div>
                        <div class="fw-semibold" id="sumWho">—</div>
                    </div>
                    <div class="mb-2">
                        <div class="ar-sum-label" id="sumAppLabel"><?= e($ct['item']) ?></div>
                        <div class="fw-semibold" id="sumApp">—</div>
                    </div>
                    <div class="mb-3">
                        <div class="ar-sum-label">e-memo</div>
                        <div class="ar-memo small" id="sumMemo">—</div>
                    </div>

                    <div class="border-top pt-3">
                        <div class="ar-sum-label mb-2">ลำดับการอนุมัติ</div>
                        <div id="sumChain" class="d-flex flex-column gap-2 small">
                            <div class="text-muted">เลือกรายการเพื่อดูผู้อนุมัติ</div>
                        </div>
                    </div>

                    <div class="d-grid gap-2 mt-4">
                        <button class="btn btn-primary btn-lg" id="submitBtn" type="submit">
                            <i class="bi bi-send"></i> ส่งคำขอ
                        </button>
                        <a href="/it-asset-manager/access-requests/index.php" class="btn btn-outline-secondary">ยกเลิก</a>
                    </div>
                </div>
            </div>
            <div class="small text-muted mt-3 px-1">ผู้อนุมัติขั้นแรกจะได้รับอีเมลทันทีที่ส่งคำขอ</div>
        </div>
    </div>
</div>
</form>

<script>
(function () {
    // ข้อมูลจาก server (escape ด้วย JSON_HEX_* กันแท็กปิด script หลุดมากับข้อมูล)
    const appList       = <?= json_encode($appList, $jsonFlags) ?>;
    const appMeta       = <?= json_encode($appMeta, $jsonFlags) ?>;
    const levelsByApp   = <?= json_encode($levelsByApp, $jsonFlags) ?>;
    const catText       = <?= json_encode($categoryText, $jsonFlags) ?>;
    const requestorName = <?= json_encode($requestorName, $jsonFlags) ?>;
    const oldAppId      = <?= json_encode($old('application_id'), $jsonFlags) ?>;
    const oldLevelId    = <?= json_encode($old('access_level_id'), $jsonFlags) ?>;
    const memoRe        = new RegExp(<?= json_encode(MEMO_PATTERN_JS, $jsonFlags) ?>);
    const memoExample   = <?= json_encode(MEMO_EXAMPLE, $jsonFlags) ?>;

    const $ = id => document.getElementById(id);
    const form = $('arForm');

    const appSelect   = $('applicationSelect');
    const levelGroup  = $('levelGroup');
    const levelSelect = $('levelSelect');
    const textGroup   = $('levelTextGroup');
    const levelText   = $('levelText');
    const memo        = $('memoNo');

    function category() {
        const r = document.querySelector('input[name="request_category"]:checked');
        return r ? r.value : 'SYSTEM';
    }
    function isEquipment() { return category() === 'EQUIPMENT'; }

    // ------------------------------------------------------------
    //  ประเภทคำขอ: สร้างรายการใน dropdown ใหม่ + เปลี่ยนข้อความบนฟอร์ม
    // ------------------------------------------------------------
    function applyCategory(keepOld) {
        const cat = category();
        const t = catText[cat];

        document.querySelectorAll('[data-ct]').forEach(el => { el.textContent = t[el.dataset.ct]; });
        $('reasonBox').placeholder = t.reason_ph;
        $('sumCat').textContent = t.choice;
        $('sumAppLabel').textContent = t.item;

        const keep = keepOld ? oldAppId : '';
        appSelect.replaceChildren();
        const first = document.createElement('option');
        first.value = '';
        first.textContent = t.item_pick;
        appSelect.appendChild(first);
        (appList[cat] || []).forEach(a => {
            const o = document.createElement('option');
            o.value = String(a.id);
            o.textContent = a.name;
            appSelect.appendChild(o);
        });
        appSelect.value = (appList[cat] || []).some(a => String(a.id) === keep) ? keep : '';

        if (!keepOld) levelText.value = '';
        renderLevels(keepOld);
        syncMemoRequirement();
        updateSummary();
    }
    document.querySelectorAll('input[name="request_category"]').forEach(r =>
        r.addEventListener('change', () => applyCategory(false)));

    // ------------------------------------------------------------
    //  ผู้รับ: สลับกลุ่มฟิลด์ตาม radio
    // ------------------------------------------------------------
    const benHidden = $('beneficiaryEmployeeId');
    const benInput  = $('beneficiarySearchInput');
    const benResult = $('beneficiaryResults');
    const benLabel  = $('beneficiarySelectedLabel');
    let benPickedName = benInput.value; // ชื่อที่เลือกไว้ (คืนค่าหลัง validate ไม่ผ่าน)

    function benType() {
        const r = document.querySelector('input[name="beneficiary_type"]:checked');
        return r ? r.value : 'self';
    }
    function toggleBeneficiary() {
        const t = benType();
        $('beneficiarySearchGroup').classList.toggle('d-none', t !== 'other');
        $('beneficiaryExternalGroup').classList.toggle('d-none', t !== 'external');
        updateSummary();
    }
    document.querySelectorAll('input[name="beneficiary_type"]').forEach(r =>
        r.addEventListener('change', toggleBeneficiary));

    // ------------------------------------------------------------
    //  ค้นหาพนักงาน (debounce 300ms) — ใช้ textContent เท่านั้น ห้าม innerHTML กับข้อมูลจาก DB
    // ------------------------------------------------------------
    let benTimer = null;
    benInput.addEventListener('input', () => {
        clearTimeout(benTimer);
        benHidden.value = '';          // พิมพ์ใหม่ = ยกเลิกคนที่เลือกไว้
        benPickedName = '';
        benLabel.textContent = '';
        benResult.replaceChildren();
        updateSummary();
        const q = benInput.value.trim();
        if (q.length < 2) return;
        benTimer = setTimeout(() => {
            fetch('/it-asset-manager/access-requests/employee_search_ajax.php?q=' + encodeURIComponent(q))
                .then(r => r.ok ? r.json() : [])
                .then(list => {
                    benResult.replaceChildren();
                    if (!list.length) {
                        const none = document.createElement('div');
                        none.className = 'list-group-item small text-muted';
                        none.textContent = 'ไม่พบพนักงาน';
                        benResult.appendChild(none);
                        return;
                    }
                    list.forEach(item => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'list-group-item list-group-item-action';
                        const name = document.createElement('div');
                        name.className = 'fw-semibold';
                        name.textContent = item.label;
                        const sub = document.createElement('div');
                        sub.className = 'small text-muted';
                        sub.textContent = item.sub;
                        btn.append(name, sub);
                        btn.addEventListener('click', () => {
                            benHidden.value = item.id;
                            benPickedName = item.label;
                            benInput.value = item.label;
                            benLabel.textContent = '✓ เลือกแล้ว: ' + item.label;
                            benResult.replaceChildren();
                            updateSummary();
                        });
                        benResult.appendChild(btn);
                    });
                })
                .catch(() => benResult.replaceChildren());
        }, 300);
    });
    document.addEventListener('click', e => {
        if (!e.target.closest('#beneficiarySearchGroup')) benResult.replaceChildren();
    });

    // ------------------------------------------------------------
    //  รายการ → Level / รุ่น
    //  SYSTEM   : level ไม่บังคับ, มี "อื่น ๆ (ระบุเอง)" → โชว์ช่องพิมพ์
    //  EQUIPMENT: ต้องเลือกรุ่นถ้ามี, ช่องพิมพ์ = สเปกเพิ่มเติม (แสดงตลอด)
    // ------------------------------------------------------------
    function addOpt(value, text) {
        const o = document.createElement('option');
        o.value = value;
        o.textContent = text;
        levelSelect.appendChild(o);
    }
    function renderLevels(keepOld) {
        levelSelect.replaceChildren();
        levelSelect.classList.remove('is-invalid');
        const levels = levelsByApp[appSelect.value] || [];
        const eq = isEquipment();
        $('levelReqMark').classList.toggle('d-none', !(eq && levels.length));

        if (!appSelect.value) {
            levelGroup.classList.add('d-none');
            textGroup.classList.add('d-none');
            return;
        }
        if (levels.length === 0) {
            levelGroup.classList.add('d-none');
            textGroup.classList.remove('d-none');
            $('levelTextLabel').textContent = eq ? catText.EQUIPMENT.level_text : 'Access Level (ถ้ามี)';
            return;
        }

        addOpt('', eq ? '— เลือกรุ่น —' : '— ไม่ระบุ —');
        levels.forEach(lv => addOpt(String(lv.id), lv.level_name));
        if (!eq) addOpt('other', 'อื่น ๆ (ระบุเอง)');
        levelGroup.classList.remove('d-none');

        if (keepOld && oldLevelId && levels.some(lv => String(lv.id) === oldLevelId)) {
            levelSelect.value = oldLevelId;
        } else if (keepOld && !eq && levelText.value.trim() !== '') {
            levelSelect.value = 'other';
        }
        <?php if (isset($errors['access_level'])): ?>
        if (keepOld) levelSelect.classList.add('is-invalid');
        <?php endif; ?>
        toggleLevelText();
    }
    function toggleLevelText() {
        if (isEquipment()) {
            textGroup.classList.remove('d-none');
            $('levelTextLabel').textContent = catText.EQUIPMENT.level_text;
            return;
        }
        const isOther = levelSelect.value === 'other';
        textGroup.classList.toggle('d-none', !isOther);
        $('levelTextLabel').textContent = catText.SYSTEM.level_text;
        if (!isOther) levelText.value = '';
    }
    function validateLevel() {
        const need = isEquipment() && (levelsByApp[appSelect.value] || []).length > 0;
        const bad = need && !levelSelect.value;
        levelSelect.classList.toggle('is-invalid', bad);
        return !bad;
    }

    // ------------------------------------------------------------
    //  e-memo: ตัวพิมพ์ใหญ่อัตโนมัติ + บังคับเฉพาะรายการที่ตั้งไว้
    // ------------------------------------------------------------
    function memoRequired() {
        const m = appMeta[appSelect.value];
        return !!(m && m.requires_memo);
    }
    function syncMemoRequirement() {
        const req = memoRequired();
        memo.required = req;
        $('memoReqMark').classList.toggle('d-none', !req);
        $('memoOptMark').classList.toggle('d-none', req);
        $('memoHelp').textContent = req
            ? 'รายการนี้ต้องผ่านการอนุมัติใน e-memo ก่อน กรุณาระบุเลขที่เอกสาร'
            : 'เลขเอกสารที่ผู้บริหารอนุมัติในระบบ e-memo ใช้ค้นย้อนหลัง';
    }
    function validateMemo() {
        const v = memo.value;
        let msg = '';
        if (v === '' && memoRequired()) msg = 'รายการนี้ต้องแนบเลขที่ e-memo';
        else if (v !== '' && !memoRe.test(v)) msg = 'รูปแบบไม่ถูกต้อง (ตัวอย่าง ' + memoExample + ')';
        memo.classList.toggle('is-invalid', msg !== '');
        if (msg) $('memoFeedback').textContent = msg;
        return msg === '';
    }
    memo.addEventListener('input', () => {
        const pos = memo.selectionStart;
        memo.value = memo.value.toUpperCase().replace(/\s+/g, '');
        memo.setSelectionRange(pos, pos);
        if (memo.classList.contains('is-invalid')) validateMemo();
        updateSummary();
    });
    memo.addEventListener('blur', validateMemo);

    appSelect.addEventListener('change', () => {
        renderLevels(false);
        syncMemoRequirement();
        if (memo.classList.contains('is-invalid')) validateMemo();
        updateSummary();
    });
    levelSelect.addEventListener('change', () => { toggleLevelText(); validateLevel(); updateSummary(); });
    levelText.addEventListener('input', updateSummary);
    $('extName').addEventListener('input', updateSummary);

    // ------------------------------------------------------------
    //  แผงสรุป (สร้างด้วย textContent ทั้งหมด)
    // ------------------------------------------------------------
    function updateSummary() {
        const t = benType();
        let who = '—';
        if (t === 'self') who = requestorName + ' (ตัวเอง)';
        else if (t === 'other') who = benPickedName || '— ยังไม่ได้เลือก —';
        else who = ($('extName').value.trim() || '— ยังไม่ได้กรอก —') + ' (ภายนอก)';
        $('sumWho').textContent = who;

        const meta = appMeta[appSelect.value];
        const parts = [];
        if (levelSelect.value && levelSelect.value !== 'other') {
            parts.push(levelSelect.options[levelSelect.selectedIndex].textContent);
        }
        if (levelText.value.trim() !== '') parts.push(levelText.value.trim());
        $('sumApp').textContent = meta ? meta.name + (parts.length ? ' · ' + parts.join(' · ') : '') : '—';
        $('sumMemo').textContent = memo.value || '—';

        const chain = $('sumChain');
        chain.replaceChildren();
        if (!meta) {
            const d = document.createElement('div');
            d.className = 'text-muted';
            d.textContent = 'เลือกรายการเพื่อดูผู้อนุมัติ';
            chain.appendChild(d);
            return;
        }
        const steps = meta.chain.concat([{ role: 'IT ดำเนินการ', name: 'IT Admin / Staff' }]);
        steps.forEach((s, i) => {
            const row = document.createElement('div');
            row.className = 'd-flex align-items-center gap-2';
            const dot = document.createElement('span');
            dot.className = 'ar-chain-dot';
            dot.textContent = String(i + 1);
            const txt = document.createElement('div');
            const role = document.createElement('div');
            role.className = 'fw-semibold';
            role.textContent = s.role;
            const nm = document.createElement('div');
            nm.className = 'text-muted';
            nm.textContent = s.name;
            txt.append(role, nm);
            row.append(dot, txt);
            chain.appendChild(row);
        });
    }

    // ------------------------------------------------------------
    //  Submit: ตรวจรุ่น + memo ก่อน (server ตรวจซ้ำ) แล้วกันกดซ้ำ
    // ------------------------------------------------------------
    form.addEventListener('submit', e => {
        const okLevel = validateLevel();
        const okMemo  = validateMemo();
        if (!okLevel || !okMemo) {
            e.preventDefault();
            (okLevel ? memo : levelSelect).focus();
            return;
        }
        const btn = $('submitBtn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังส่งคำขอ...';
    });

    // ------ init (รวมกรณี re-render หลัง validate ไม่ผ่าน) ------
    applyCategory(true);
    toggleBeneficiary();
})();
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
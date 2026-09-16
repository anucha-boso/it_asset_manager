<?php
/**
 * Access Request — สร้างคำขอใหม่
 * public/access-requests/form.php
 *
 * เข้าได้ทุก role ที่ login (self-service) — ต้องผูก employee ก่อน (require_employee_link)
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
$beneficiaryType = $_POST['beneficiary_type'] ?? 'self';

// ------ Master data สำหรับฟอร์ม ------
$applications = $pdo->query("
    SELECT id, app_code, app_name, requires_approver2,
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
    $levelsByApp[(int)$lv['application_id']][] = $lv;
}

$sites = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();

$errors = [];

// ------------------------------------------------------------------
//  POST: บันทึกคำขอ + สร้าง approval chain
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $appId       = (int)($_POST['application_id'] ?? 0);
    $siteId      = ctype_digit((string)($_POST['site_id'] ?? '')) ? (int)$_POST['site_id'] : null;
    $levelId     = ctype_digit((string)($_POST['access_level_id'] ?? '')) ? (int)$_POST['access_level_id'] : null;
    $levelText   = trim((string)($_POST['access_level_text'] ?? ''));
    $reason      = trim((string)($_POST['reason'] ?? ''));

    if ($appId <= 0) $errors['application_id'] = 'กรุณาเลือกระบบที่ต้องการขอสิทธิ์';
    if ($reason === '') $errors['reason'] = 'กรุณาระบุเหตุผล';

    $app = null;
    if ($appId > 0) {
        $stmt = $pdo->prepare("SELECT * FROM access_applications WHERE id = :id AND is_active = 1");
        $stmt->execute([':id' => $appId]);
        $app = $stmt->fetch();
        if (!$app) $errors['application_id'] = 'ไม่พบระบบที่เลือก หรือระบบถูกปิดใช้งาน';
    }

    if ($app && !$app['default_approver1_employee_id']) {
        $errors['application_id'] = 'ระบบนี้ยังไม่ได้ตั้งค่าผู้อนุมัติ กรุณาติดต่อ IT Admin';
    }

    // ------ ถ้าเลือก level จาก dropdown ต้องตรวจว่าเป็นของ application ที่เลือกจริง ------
    if ($levelId && $app) {
        $lvChk = $pdo->prepare("SELECT id FROM access_application_levels WHERE id = :id AND application_id = :app_id");
        $lvChk->execute([':id' => $levelId, ':app_id' => $appId]);
        if (!$lvChk->fetchColumn()) {
            $errors['access_level'] = 'Access Level ไม่ตรงกับระบบที่เลือก';
        }
    }

        // ------ Resolve Beneficiary ------
    $beneficiaryEmployeeId = null;
    $beneficiaryName       = null;
    $beneficiaryDept       = null;

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
            $errors['beneficiary'] = 'กรุณาเลือกพนักงานที่ต้องการขอสิทธิ์ให้';
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
            }
        }
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $year = date('Y');
            $countStmt = $pdo->prepare("SELECT COUNT(*)+1 FROM access_requests WHERE YEAR(requested_at) = :y");
            $countStmt->execute([':y' => $year]);
            $requestNo = 'AR-' . $year . '-' . str_pad((string)$countStmt->fetchColumn(), 6, '0', STR_PAD_LEFT);

                        $ins = $pdo->prepare("
                INSERT INTO access_requests
                    (request_no, application_id, requestor_employee_id,
                     beneficiary_employee_id, beneficiary_name, beneficiary_department,
                     beneficiary_external_org, beneficiary_external_contact,
                     requestor_name, department,
                     site_id, access_level_id, access_level_text, reason, status)
                VALUES
                    (:no, :app_id, :emp_id,
                     :ben_id, :ben_name, :ben_dept,
                     :ben_org, :ben_contact,
                     :r_name, :dept,
                     :site_id, :level_id, :level_text, :reason, 'Pending Approver1')
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

            // ------ step 2: Approver2 (เฉพาะ application ที่ต้องการ) ------
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

            // ── History log (เฟส 2) — log ที่ beneficiary ไม่ใช่ requestor เสมอไป
            //    (เช่น IT ยื่นขอแทนคนอื่น) ดังนั้น log เข้าประวัติของ "ผู้ได้รับสิทธิ์"
            //    ไม่ใช่ผู้ยื่น ให้สอดคล้องกับกติกาที่ตกลงกันไว้ (beneficiary-only)
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
                    'note'      => "ขอสิทธิ์ให้: {$beneficiaryName}" . ($levelText !== '' ? " · Level: {$levelText}" : ''),
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

$page_title  = 'ขอสิทธิ์เข้าใช้งานโปรแกรม';
$active_menu = 'access_requests';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <h2 class="h5 mb-0">ขอสิทธิ์เข้าใช้งานโปรแกรม</h2>
    <div class="text-muted small">ผู้ขอ: <?= e($requestorName) ?> · <?= e($employee['department'] ?? '—') ?></div>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<form method="post" style="max-width:680px;">
    <?php csrf_field(); ?>
    <div class="card mb-3">
        <div class="card-body">             
             <div class="mb-3">
                <label class="form-label">ขอสิทธิ์ให้ <span class="text-danger">*</span></label>
                <div class="d-flex gap-3 flex-wrap">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="beneficiary_type" value="self"
                               id="benSelf" <?= $beneficiaryType === 'self' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="benSelf">ตัวเอง (<?= e($requestorName) ?>)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="beneficiary_type" value="other"
                               id="benOther" <?= $beneficiaryType === 'other' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="benOther">พนักงานคนอื่น</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="beneficiary_type" value="external"
                               id="benExternal" <?= $beneficiaryType === 'external' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="benExternal">บุคคลภายนอก</label>
                    </div>
                </div>
            </div>

                        <div class="mb-3" id="beneficiarySearchGroup"
                 style="<?= $beneficiaryType === 'other' ? '' : 'display:none;' ?>">
                <label class="form-label">ค้นหาพนักงาน</label>
                <input type="text" id="beneficiarySearchInput" class="form-control"
                       placeholder="พิมพ์ชื่อ นามสกุล หรือรหัสพนักงาน" autocomplete="off">
                <div id="beneficiaryResults" class="list-group mt-2"></div>
                <input type="hidden" name="beneficiary_employee_id" id="beneficiaryEmployeeId"
                       value="<?= e($_POST['beneficiary_employee_id'] ?? '') ?>">
                <div id="beneficiarySelectedLabel" class="mt-2 fw-semibold text-success"></div>
                <?php if (isset($errors['beneficiary'])): ?>
                    <div class="text-danger small mt-1"><?= e($errors['beneficiary']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3" id="beneficiaryExternalGroup"
                 style="<?= $beneficiaryType === 'external' ? '' : 'display:none;' ?>">
                <label class="form-label">ชื่อบุคคลภายนอก <span class="text-danger">*</span></label>
                <input type="text" name="beneficiary_external_name" class="form-control"
                       value="<?= e($_POST['beneficiary_external_name'] ?? '') ?>"
                       placeholder="ชื่อ-นามสกุล">

                <label class="form-label mt-2">สังกัด/บริษัท <span class="text-danger">*</span></label>
                <input type="text" name="beneficiary_external_org" class="form-control"
                       value="<?= e($_POST['beneficiary_external_org'] ?? '') ?>"
                       placeholder="ชื่อบริษัท / หน่วยงานต้นสังกัด">

                <label class="form-label mt-2">เบอร์โทร / Email ติดต่อ <span class="text-danger">*</span></label>
                <input type="text" name="beneficiary_external_contact" class="form-control"
                       value="<?= e($_POST['beneficiary_external_contact'] ?? '') ?>"
                       placeholder="เบอร์โทรศัพท์ หรือ อีเมล">
                <?php if (isset($errors['beneficiary'])): ?>
                    <div class="text-danger small mt-1"><?= e($errors['beneficiary']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label class="form-label">ระบบที่ต้องการขอสิทธิ์ <span class="text-danger">*</span></label>
                <select name="application_id" id="applicationSelect"
                        class="form-select <?= isset($errors['application_id']) ? 'is-invalid' : '' ?>">
                    <option value="">— เลือกระบบ —</option>
                    <?php foreach ($applications as $a): ?>
                        <option value="<?= e($a['id']) ?>"
                            <?= (int)($_POST['application_id'] ?? 0) === (int)$a['id'] ? 'selected' : '' ?>>
                            <?= e($a['app_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['application_id'])): ?>
                    <div class="invalid-feedback"><?= e($errors['application_id']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3" id="levelGroup" style="display:none;">
                <label class="form-label">Access Level / Role</label>
                <select name="access_level_id" id="levelSelect" class="form-select">
                    <option value="">— ไม่มี level เฉพาะ —</option>
                </select>
                <?php if (isset($errors['access_level'])): ?>
                    <div class="text-danger small mt-1"><?= e($errors['access_level']) ?></div>
                <?php endif; ?>
            </div>

            <div class="mb-3">
                <label class="form-label">ระบุ Access Level เพิ่มเติม (ถ้าไม่มีในตัวเลือกด้านบน)</label>
                <input type="text" name="access_level_text" class="form-control"
                       value="<?= e($_POST['access_level_text'] ?? '') ?>" placeholder="ระบุเอง">
            </div>

            <div class="mb-3">
                <label class="form-label">Site</label>
                <select name="site_id" class="form-select">
                    <option value="">— ไม่ระบุ —</option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= e($s['id']) ?>"><?= e($s['site_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="mb-0">
                <label class="form-label">เหตุผล <span class="text-danger">*</span></label>
                <textarea name="reason" rows="3"
                          class="form-control <?= isset($errors['reason']) ? 'is-invalid' : '' ?>"><?= e($_POST['reason'] ?? '') ?></textarea>
                <?php if (isset($errors['reason'])): ?>
                    <div class="invalid-feedback"><?= e($errors['reason']) ?></div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-5">
        <a href="/it-asset-manager/access-requests/index.php" class="btn btn-outline-secondary">ยกเลิก</a>
        <button class="btn btn-primary" id="submitBtn" type="submit"><i class="bi bi-send"></i> ส่งคำขอ</button>
    </div>
</form>

<script>
// dependent dropdown: application -> access level (ไม่ใช้ AJAX เพราะข้อมูล levels มีน้อย ส่งมาพร้อมหน้าเลย)
const levelsByApp = <?= json_encode($levelsByApp, JSON_UNESCAPED_UNICODE) ?>;

const appSelect   = document.getElementById('applicationSelect');
const levelGroup  = document.getElementById('levelGroup');
const levelSelect = document.getElementById('levelSelect');

function renderLevels(appId) {
    levelSelect.innerHTML = '<option value="">— ไม่มี level เฉพาะ —</option>';
    const levels = levelsByApp[appId] || [];
    if (levels.length === 0) {
        levelGroup.style.display = 'none';
        return;
    }
    levels.forEach(lv => {
        const opt = document.createElement('option');
        opt.value = lv.id;
        opt.textContent = lv.level_name;
        levelSelect.appendChild(opt);
    });
    levelGroup.style.display = '';
}

appSelect.addEventListener('change', () => renderLevels(appSelect.value));
if (appSelect.value) renderLevels(appSelect.value); // กรณี re-render หลัง validation error

// ------ Beneficiary: toggle self/other + live search ------
const benSelf   = document.getElementById('benSelf');
const benOther  = document.getElementById('benOther');
const benGroup  = document.getElementById('beneficiarySearchGroup');
const benInput  = document.getElementById('beneficiarySearchInput');
const benResult = document.getElementById('beneficiaryResults');
const benHidden = document.getElementById('beneficiaryEmployeeId');
const benLabel  = document.getElementById('beneficiarySelectedLabel');

const benExternal = document.getElementById('benExternal');
const benExtGroup  = document.getElementById('beneficiaryExternalGroup');

function toggleBeneficiary() {
    benGroup.style.display    = benOther.checked ? '' : 'none';
    benExtGroup.style.display = benExternal.checked ? '' : 'none';
    if (benSelf.checked) {
        benHidden.value = '';
        benLabel.textContent = '';
    }
}
benSelf.addEventListener('change', toggleBeneficiary);
benOther.addEventListener('change', toggleBeneficiary);
benExternal.addEventListener('change', toggleBeneficiary);

let benTimer = null;
benInput?.addEventListener('input', () => {
    clearTimeout(benTimer);
    const q = benInput.value.trim();
    benResult.innerHTML = '';
    if (q.length < 2) return;
    benTimer = setTimeout(() => {
        fetch('/it-asset-manager/access-requests/employee_search_ajax.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(list => {
                benResult.innerHTML = '';
                list.forEach(item => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'list-group-item list-group-item-action';
                    btn.innerHTML = '<div class="fw-semibold">' + item.label + '</div>'
                        + '<div class="text-muted small">' + item.sub + '</div>';
                    btn.addEventListener('click', () => {
                        benHidden.value = item.id;
                        benLabel.textContent = 'เลือกแล้ว: ' + item.label;
                        benResult.innerHTML = '';
                        benInput.value = item.label;
                    });
                    benResult.appendChild(btn);
                });
            });
    }, 300); // debounce กันยิง request ถี่เกินไป
});
document.querySelector('form').addEventListener('submit', function () {
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังส่งคำขอ...';
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
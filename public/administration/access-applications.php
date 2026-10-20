<?php
/**
 * ตั้งค่ารายการคำขอบริการ IT — ผู้อนุมัติ / ประเภท / e-memo ต่อรายการ
 * public/administration/access-applications.php
 * เฉพาะ it_admin
 *
 * 2026-10:
 *  - เพิ่ม category (SYSTEM / EQUIPMENT) และ requires_memo (ต้องรัน migration 20261010 ทั้ง 2 ไฟล์ก่อน)
 *  - รายการด้านซ้ายแยกกลุ่ม "สิทธิ์ใช้งานระบบ" / "อุปกรณ์ IT"
 *  - แก้บั๊ก: POST บันทึกกับ POST สร้างใหม่ทำงานซ้อนกัน (แยกด้วย action แล้ว)
 *  - แก้บั๊ก: ชื่อว่างยังบันทึกได้, ปุ่ม "ต้องมีผู้อนุมัติ 2 คน" JS error (ไม่มี #groupA2)
 *  - Security: ผลค้นหาพนักงานใช้ textContent แทน innerHTML, ตรวจรูปแบบ email ก่อนบันทึก
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_role(['it_admin']);

$pdo = db();

$categories = [
    'SYSTEM'    => 'สิทธิ์ใช้งานระบบ',
    'EQUIPMENT' => 'อุปกรณ์ IT',
];

$selectedAppId = isset($_GET['app_id']) && ctype_digit((string)$_GET['app_id']) ? (int)$_GET['app_id'] : 0;
$isCreatingNew = isset($_GET['new']);
$errors        = [];
$createErrors  = [];
$action        = (string)($_POST['action'] ?? 'save_app');

// ------------------------------------------------------------------
//  POST: สร้างรายการใหม่
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_app') {
    csrf_verify();
    $isCreatingNew = true;
    $newCode = strtoupper(trim((string)($_POST['app_code'] ?? '')));
    $newName = trim((string)($_POST['app_name'] ?? ''));
    $newDesc = trim((string)($_POST['description'] ?? ''));
    $newCat  = (string)($_POST['category'] ?? 'SYSTEM');

    if ($newCode === '' || !preg_match('/^[A-Z0-9_]+$/', $newCode)) {
        $createErrors['app_code'] = 'App Code ต้องเป็นตัวอักษรอังกฤษพิมพ์ใหญ่/ตัวเลข/underscore เท่านั้น';
    }
    if ($newName === '') {
        $createErrors['app_name'] = 'กรุณาระบุชื่อ';
    }
    if (!isset($categories[$newCat])) {
        $createErrors['category'] = 'ประเภทไม่ถูกต้อง';
    }

    if (!$createErrors) {
        $dupCheck = $pdo->prepare("SELECT id FROM access_applications WHERE app_code = :code");
        $dupCheck->execute([':code' => $newCode]);
        if ($dupCheck->fetchColumn()) {
            $createErrors['app_code'] = 'App Code นี้มีอยู่แล้ว';
        }
    }

    if (!$createErrors) {
        try {
            // สร้างแบบปิดใช้งานไว้ก่อน (ยังไม่มีผู้อนุมัติ) → ตั้งค่าเสร็จค่อยเปิด
            $ins = $pdo->prepare("
                INSERT INTO access_applications (app_code, app_name, category, description, requires_approver2, requires_memo, is_active)
                VALUES (:code, :name, :cat, :desc, 0, 0, 0)
            ");
            $ins->execute([
                ':code' => $newCode,
                ':name' => $newName,
                ':cat'  => $newCat,
                ':desc' => $newDesc !== '' ? $newDesc : null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $_SESSION['flash'] = ['type' => 'success', 'message' => "เพิ่ม \"{$newName}\" เรียบร้อยแล้ว กรุณาตั้งค่าผู้อนุมัติแล้วเปิดใช้งาน"];
            header('Location: /it-asset-manager/administration/access-applications.php?app_id=' . $newId);
            exit;
        } catch (PDOException $e) {
            error_log('[ACCESS APP CREATE FAIL] ' . $e->getMessage());
            $createErrors['_general'] = 'บันทึกไม่สำเร็จ';
        }
    }
}

// ------------------------------------------------------------------
//  POST: บันทึกการตั้งค่ารายการเดิม
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'save_app') {
    csrf_verify();
    $appId = isset($_POST['app_id']) && ctype_digit((string)$_POST['app_id']) ? (int)$_POST['app_id'] : 0;
    $selectedAppId = $appId;

    $approver1Id       = isset($_POST['approver1_id']) && ctype_digit((string)$_POST['approver1_id']) ? (int)$_POST['approver1_id'] : null;
    $approver2Id       = isset($_POST['approver2_id']) && ctype_digit((string)$_POST['approver2_id']) ? (int)$_POST['approver2_id'] : null;
    $requiresApprover2 = isset($_POST['requires_approver2']) ? 1 : 0;
    $requiresMemo      = isset($_POST['requires_memo']) ? 1 : 0;
    $itActionNote      = trim((string)($_POST['it_action_note'] ?? ''));
    $isActive          = isset($_POST['is_active']) ? 1 : 0;
    $appName           = trim((string)($_POST['app_name'] ?? ''));
    $category          = (string)($_POST['category'] ?? 'SYSTEM');
    $approver1Email    = trim((string)($_POST['approver1_email'] ?? ''));
    $approver2Email    = trim((string)($_POST['approver2_email'] ?? ''));

    // ตรวจทีละเงื่อนไข หยุดที่ข้อแรกที่ผิด (เดิมชื่อว่างแล้วยังบันทึกได้)
    if ($appId <= 0) {
        $errors['_general'] = 'ไม่พบรายการที่เลือก';
    } elseif ($appName === '') {
        $errors['_general'] = 'กรุณาระบุชื่อ';
    } elseif (!isset($categories[$category])) {
        $errors['_general'] = 'ประเภทไม่ถูกต้อง';
    } elseif (!$approver1Id) {
        $errors['_general'] = 'ต้องเลือก Approver1 เสมอ';
    } elseif ($requiresApprover2 && !$approver2Id) {
        $errors['_general'] = 'เปิดใช้ Approver2 แล้ว ต้องเลือกคนด้วย';
    } elseif ($requiresApprover2 && $approver1Id === $approver2Id) {
        $errors['_general'] = 'Approver1 และ Approver2 ต้องเป็นคนละคน';
    } elseif ($approver1Email !== '' && !filter_var($approver1Email, FILTER_VALIDATE_EMAIL)) {
        $errors['_general'] = 'รูปแบบ Email ของ Approver1 ไม่ถูกต้อง';
    } elseif ($requiresApprover2 && $approver2Email !== '' && !filter_var($approver2Email, FILTER_VALIDATE_EMAIL)) {
        $errors['_general'] = 'รูปแบบ Email ของ Approver2 ไม่ถูกต้อง';
    } else {
        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("
                UPDATE access_applications
                SET app_name = :name,
                    category = :cat,
                    default_approver1_employee_id = :a1,
                    default_approver2_employee_id = :a2,
                    requires_approver2 = :req2,
                    requires_memo = :memo,
                    it_action_note = :note,
                    is_active = :active
                WHERE id = :id
            ");
            $upd->execute([
                ':name'   => $appName,
                ':cat'    => $category,
                ':a1'     => $approver1Id,
                ':a2'     => $requiresApprover2 ? $approver2Id : null,
                ':req2'   => $requiresApprover2,
                ':memo'   => $requiresMemo,
                ':note'   => $itActionNote !== '' ? $itActionNote : null,
                ':active' => $isActive,
                ':id'     => $appId,
            ]);

            // ------ บันทึก/อัปเดต email ของ approver1/2 เข้า employee_contacts ------
            $contacts = [[$approver1Id, $approver1Email]];
            if ($requiresApprover2) $contacts[] = [$approver2Id, $approver2Email];
            $cStmt = $pdo->prepare("
                INSERT INTO employee_contacts (employee_id, email, updated_by_ad)
                VALUES (:eid, :email, :ad)
                ON DUPLICATE KEY UPDATE email = :email2, updated_by_ad = :ad2
            ");
            foreach ($contacts as [$empId, $email]) {
                if (!$empId || $email === '') continue;
                $cStmt->execute([
                    ':eid' => $empId, ':email' => $email, ':ad' => $_SESSION['iam_username'] ?? null,
                    ':email2' => $email, ':ad2' => $_SESSION['iam_username'] ?? null,
                ]);
            }

            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'บันทึกเรียบร้อยแล้ว'];
            header('Location: /it-asset-manager/administration/access-applications.php?app_id=' . $appId);
            exit;

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[ACCESS APP SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ';
        }
    }
}

// ------------------------------------------------------------------
//  ดึงรายการทั้งหมด พร้อมจำนวนคำขอ (หลัง POST เพื่อให้เห็นค่าล่าสุด)
// ------------------------------------------------------------------
$apps = $pdo->query("
    SELECT a.id, a.app_code, a.app_name, a.category, a.description,
           a.default_approver1_employee_id, a.default_approver2_employee_id,
           a.requires_approver2, a.requires_memo, a.it_action_note, a.is_active,
           COUNT(r.id) AS total_requests,
           SUM(CASE WHEN r.status NOT IN ('Completed','Rejected','Cancelled') THEN 1 ELSE 0 END) AS pending_requests
    FROM access_applications a
    LEFT JOIN access_requests r ON r.application_id = a.id
    GROUP BY a.id, a.app_code, a.app_name, a.category, a.description,
             a.default_approver1_employee_id, a.default_approver2_employee_id,
             a.requires_approver2, a.requires_memo, a.it_action_note, a.is_active
    ORDER BY a.category, a.app_name
")->fetchAll();

// รวบรวม employee_id ทั้งหมดที่ต้องเอาชื่อมาโชว์ (approver1 + approver2 ของทุกรายการ)
$empIds = [];
foreach ($apps as $a) {
    if ($a['default_approver1_employee_id']) $empIds[] = (int)$a['default_approver1_employee_id'];
    if ($a['default_approver2_employee_id']) $empIds[] = (int)$a['default_approver2_employee_id'];
}
$empIds = array_values(array_unique($empIds));

$empNames = [];
if ($empIds) {
    $in = implode(',', array_fill(0, count($empIds), '?'));
    $stmt = employee_db()->prepare("SELECT id, title, first_name, last_name FROM employees WHERE id IN ({$in})");
    $stmt->execute($empIds);
    foreach ($stmt->fetchAll() as $e) {
        $empNames[(int)$e['id']] = trim($e['title'] . ' ' . $e['first_name'] . ' ' . $e['last_name']);
    }
}

// จัดกลุ่มตามประเภทสำหรับรายการด้านซ้าย
$appsByCat = ['SYSTEM' => [], 'EQUIPMENT' => []];
$selectedApp = null;
foreach ($apps as $a) {
    $cat = isset($appsByCat[$a['category']]) ? $a['category'] : 'SYSTEM';
    $appsByCat[$cat][] = $a;
    if ((int)$a['id'] === $selectedAppId) $selectedApp = $a;
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title  = 'ตั้งค่ารายการคำขอบริการ IT';
$active_menu = 'access_applications';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<div class="row g-3 align-items-stretch">
    <!-- ================= รายการ ================= -->
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>รายการที่ขอได้</strong>
                <a href="?new=1" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg"></i> เพิ่มรายการ
                </a>
            </div>

            <?php foreach ($categories as $catKey => $catLabel): ?>
                <div class="px-3 py-2 small fw-semibold text-muted bg-light border-bottom">
                    <i class="bi <?= $catKey === 'EQUIPMENT' ? 'bi-laptop' : 'bi-key' ?>"></i>
                    <?= e($catLabel) ?> (<?= count($appsByCat[$catKey]) ?>)
                </div>
                <div class="list-group list-group-flush">
                    <?php if (!$appsByCat[$catKey]): ?>
                        <div class="list-group-item small text-muted">ยังไม่มีรายการ</div>
                    <?php endif; ?>
                    <?php foreach ($appsByCat[$catKey] as $a):
                        $a1Name = $a['default_approver1_employee_id'] ? ($empNames[(int)$a['default_approver1_employee_id']] ?? '—') : null;
                        $a2Name = $a['default_approver2_employee_id'] ? ($empNames[(int)$a['default_approver2_employee_id']] ?? '—') : null;
                        $isSel  = $selectedAppId === (int)$a['id'];
                    ?>
                        <a href="?app_id=<?= e((string)$a['id']) ?>"
                           class="list-group-item list-group-item-action <?= $isSel ? 'active' : '' ?>">
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <div>
                                    <div class="fw-semibold"><?= e($a['app_name']) ?></div>
                                    <div class="small <?= $isSel ? '' : 'text-muted' ?>">
                                        Approver1: <?= e($a1Name ?: 'ยังไม่ตั้ง') ?>
                                        <?php if ($a['requires_approver2']): ?>
                                            <br>Approver2: <?= e($a2Name ?: 'ยังไม่ตั้ง') ?>
                                        <?php endif; ?>
                                    </div>
                                    <div class="small mt-1">
                                        <span class="badge <?= $isSel ? 'text-bg-light' : 'text-bg-primary' ?> rounded-pill">
                                            <?= (int)$a['total_requests'] ?> คำขอทั้งหมด
                                        </span>
                                        <?php if ((int)$a['pending_requests'] > 0): ?>
                                            <span class="badge <?= $isSel ? 'text-bg-light' : 'text-bg-warning' ?> rounded-pill">
                                                <?= (int)$a['pending_requests'] ?> รอดำเนินการ
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($a['requires_memo']): ?>
                                            <span class="badge <?= $isSel ? 'text-bg-light' : 'text-bg-info' ?> rounded-pill">ต้องมี e-memo</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!$a['is_active']): ?>
                                    <span class="badge text-bg-secondary">ปิดใช้งาน</span>
                                <?php elseif (!$a1Name): ?>
                                    <span class="badge <?= $isSel ? 'text-bg-light' : 'text-bg-warning' ?>">ยังไม่ตั้ง</span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ================= ฟอร์ม ================= -->
    <div class="col-md-7">
        <div class="card h-100">
            <?php if ($isCreatingNew): ?>
                <div class="card-header bg-white"><strong>เพิ่มรายการใหม่</strong></div>
                <div class="card-body">
                    <?php if (!empty($createErrors['_general'])): ?>
                        <div class="alert alert-danger"><?= e($createErrors['_general']) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="create_app">

                        <div class="mb-3">
                            <label class="form-label">ประเภท <span class="text-danger">*</span></label>
                            <div class="d-flex gap-3">
                                <?php foreach ($categories as $catKey => $catLabel): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="category" value="<?= e($catKey) ?>"
                                               id="newCat<?= e($catKey) ?>" <?= (($_POST['category'] ?? 'SYSTEM') === $catKey) ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="newCat<?= e($catKey) ?>"><?= e($catLabel) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (isset($createErrors['category'])): ?>
                                <div class="text-danger small"><?= e($createErrors['category']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="newCode" class="form-label">App Code <span class="text-danger">*</span></label>
                            <input type="text" id="newCode" name="app_code" class="form-control <?= isset($createErrors['app_code']) ? 'is-invalid' : '' ?>"
                                   value="<?= e((string)($_POST['app_code'] ?? '')) ?>" placeholder="เช่น WMS, SAP_HR, MONITOR"
                                   style="text-transform:uppercase;">
                            <div class="form-text">ตัวอักษรอังกฤษพิมพ์ใหญ่/ตัวเลข/underscore เท่านั้น ใช้อ้างอิงในระบบ แก้ทีหลังไม่ได้</div>
                            <?php if (isset($createErrors['app_code'])): ?>
                                <div class="invalid-feedback"><?= e($createErrors['app_code']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="newName" class="form-label">ชื่อ <span class="text-danger">*</span></label>
                            <input type="text" id="newName" name="app_name" class="form-control <?= isset($createErrors['app_name']) ? 'is-invalid' : '' ?>"
                                   value="<?= e((string)($_POST['app_name'] ?? '')) ?>" placeholder="เช่น WMS (Warehouse Management System), Monitor">
                            <?php if (isset($createErrors['app_name'])): ?>
                                <div class="invalid-feedback"><?= e($createErrors['app_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label for="newDesc" class="form-label">คำอธิบาย (ถ้ามี)</label>
                            <textarea id="newDesc" name="description" class="form-control" rows="2"><?= e((string)($_POST['description'] ?? '')) ?></textarea>
                        </div>

                        <div class="alert alert-light border small">
                            รายการใหม่จะถูกสร้างแบบ <strong>ปิดใช้งาน</strong> ไว้ก่อน ตั้งค่าผู้อนุมัติเสร็จแล้วค่อยเปิดใช้งาน
                        </div>

                        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> สร้างรายการ</button>
                        <a href="?" class="btn btn-outline-secondary">ยกเลิก</a>
                    </form>
                </div>

            <?php elseif ($selectedApp): ?>
                <?php
                $a1Id   = $selectedApp['default_approver1_employee_id'];
                $a2Id   = $selectedApp['default_approver2_employee_id'];
                $a1Name = $a1Id ? ($empNames[(int)$a1Id] ?? '') : '';
                $a2Name = $a2Id ? ($empNames[(int)$a2Id] ?? '') : '';

                // email ของ approver ทั้ง 2 คนในคำสั่งเดียว
                $emails = [];
                $ids = array_values(array_filter([(int)$a1Id, (int)$a2Id]));
                if ($ids) {
                    $in = implode(',', array_fill(0, count($ids), '?'));
                    $c = $pdo->prepare("SELECT employee_id, email FROM employee_contacts WHERE employee_id IN ({$in})");
                    $c->execute($ids);
                    foreach ($c->fetchAll() as $row) $emails[(int)$row['employee_id']] = $row['email'];
                }
                $a1Email = $a1Id ? ($emails[(int)$a1Id] ?? '') : '';
                $a2Email = $a2Id ? ($emails[(int)$a2Id] ?? '') : '';

                $pendingCount = (int)$selectedApp['pending_requests'];
                $isEquip      = $selectedApp['category'] === 'EQUIPMENT';
                ?>
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><?= e($selectedApp['app_name']) ?></strong>
                    <span class="text-muted small">Code: <?= e($selectedApp['app_code']) ?></span>
                </div>
                <div class="card-body">
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="save_app">
                        <input type="hidden" name="app_id" value="<?= e((string)$selectedApp['id']) ?>">

                        <div class="row g-3 mb-3">
                            <div class="col-md-7">
                                <label for="appName" class="form-label">ชื่อ <span class="text-danger">*</span></label>
                                <input type="text" id="appName" name="app_name" class="form-control"
                                       value="<?= e($selectedApp['app_name']) ?>">
                            </div>
                            <div class="col-md-5">
                                <label for="appCat" class="form-label">ประเภท</label>
                                <select id="appCat" name="category" class="form-select">
                                    <?php foreach ($categories as $catKey => $catLabel): ?>
                                        <option value="<?= e($catKey) ?>" <?= $selectedApp['category'] === $catKey ? 'selected' : '' ?>><?= e($catLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="search_a1" class="form-label">Approver1 <span class="text-danger">*</span></label>
                            <div class="position-relative">
                                <input type="text" id="search_a1" class="form-control"
                                       placeholder="พิมพ์ชื่อหรือรหัสพนักงาน" autocomplete="off"
                                       value="<?= e($a1Name) ?>">
                                <div id="results_a1" class="list-group position-absolute w-100 shadow-sm" style="z-index:20"></div>
                            </div>
                            <input type="hidden" name="approver1_id" id="hidden_a1" value="<?= e((string)($a1Id ?? '')) ?>">

                            <label for="a1Email" class="form-label small text-muted mt-2">Email สำหรับแจ้งเตือนผู้อนุมัติ</label>
                            <input type="email" id="a1Email" name="approver1_email" class="form-control form-control-sm"
                                   value="<?= e($a1Email) ?>" placeholder="name@pacificcoldchain.com">
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="requires_approver2"
                                   id="chkReq2" <?= $selectedApp['requires_approver2'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="chkReq2">ต้องมีผู้อนุมัติ 2 คน</label>
                        </div>

                        <div class="mb-3 ps-4 border-start" id="groupA2" style="<?= $selectedApp['requires_approver2'] ? '' : 'display:none;' ?>">
                            <label for="search_a2" class="form-label">Approver2 <span class="text-danger">*</span></label>
                            <div class="position-relative">
                                <input type="text" id="search_a2" class="form-control"
                                       placeholder="พิมพ์ชื่อหรือรหัสพนักงาน" autocomplete="off"
                                       value="<?= e($a2Name) ?>">
                                <div id="results_a2" class="list-group position-absolute w-100 shadow-sm" style="z-index:20"></div>
                            </div>
                            <input type="hidden" name="approver2_id" id="hidden_a2" value="<?= e((string)($a2Id ?? '')) ?>">

                            <label for="a2Email" class="form-label small text-muted mt-2">Email สำหรับแจ้งเตือนผู้อนุมัติ</label>
                            <input type="email" id="a2Email" name="approver2_email" class="form-control form-control-sm"
                                   value="<?= e($a2Email) ?>" placeholder="name@pacificcoldchain.com">
                        </div>

                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="requires_memo"
                                   id="chkMemo" <?= $selectedApp['requires_memo'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="chkMemo">
                                ต้องแนบเลขที่ e-memo
                                <span class="text-muted small">(ผู้ขอต้องผ่านการอนุมัติใน e-memo ก่อน)</span>
                            </label>
                        </div>

                        <div class="mb-3">
                            <label for="itNote" class="form-label">หมายเหตุงาน IT (แสดงในหน้า IT Action)</label>
                            <textarea id="itNote" name="it_action_note" class="form-control" rows="2"
                                      placeholder="<?= $isEquip ? 'เช่น ติดตั้ง image มาตรฐาน / join domain / บันทึกใน Hardware Assets' : 'เช่น สร้าง user ใน CCMS' ?>"><?= e($selectedApp['it_action_note'] ?? '') ?></textarea>
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_active"
                                   id="chkActive" data-pending-count="<?= $pendingCount ?>"
                                   <?= $selectedApp['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="chkActive">เปิดใช้งาน (ให้เลือกได้ในฟอร์มคำขอบริการ IT)</label>
                        </div>

                        <?php if ($pendingCount > 0): ?>
                            <div class="alert alert-warning py-2 small mb-3">
                                <i class="bi bi-exclamation-triangle"></i>
                                มีคำขอที่ยังไม่เสร็จสิ้น <strong><?= $pendingCount ?> ใบ</strong> ในรายการนี้
                                (ปิดใช้งานจะไม่กระทบคำขอเดิมที่ค้างอยู่ แต่จะสร้างคำขอใหม่ไม่ได้)
                            </div>
                        <?php endif; ?>

                        <button type="submit" id="saveAppBtn" class="btn btn-primary"><i class="bi bi-check-lg"></i> บันทึก</button>
                    </form>
                </div>

            <?php else: ?>
                <div class="card-body d-flex align-items-center justify-content-center text-muted" style="min-height:300px;">
                    <div class="text-center">
                        <i class="bi bi-arrow-left-circle" style="font-size:32px;"></i>
                        <div class="mt-2">เลือกรายการทางซ้ายเพื่อตั้งค่า</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ------ Reusable employee search สำหรับ Approver1/Approver2 (textContent เท่านั้น กัน XSS) ------
function setupEmployeeSearch(inputId, resultsId, hiddenId) {
    const input   = document.getElementById(inputId);
    const results = document.getElementById(resultsId);
    const hidden  = document.getElementById(hiddenId);
    if (!input) return;

    let timer = null;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        results.replaceChildren();
        hidden.value = ''; // เคลียร์ค่าเดิมเมื่อพิมพ์ใหม่ ป้องกันบันทึกผิดคนถ้าไม่เลือกซ้ำ
        if (q.length < 2) return;
        timer = setTimeout(() => {
            fetch('/it-asset-manager/access-requests/employee_search_ajax.php?q=' + encodeURIComponent(q))
                .then(r => r.ok ? r.json() : [])
                .then(list => {
                    results.replaceChildren();
                    list.forEach(item => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'list-group-item list-group-item-action';
                        const name = document.createElement('div');
                        name.className = 'fw-semibold';
                        name.textContent = item.label;
                        const sub = document.createElement('div');
                        sub.className = 'text-muted small';
                        sub.textContent = item.sub;
                        btn.append(name, sub);
                        btn.addEventListener('click', () => {
                            hidden.value = item.id;
                            input.value = item.label;
                            results.replaceChildren();
                        });
                        results.appendChild(btn);
                    });
                })
                .catch(() => results.replaceChildren());
        }, 300);
    });
    document.addEventListener('click', e => {
        if (!results.contains(e.target) && e.target !== input) results.replaceChildren();
    });
}
setupEmployeeSearch('search_a1', 'results_a1', 'hidden_a1');
setupEmployeeSearch('search_a2', 'results_a2', 'hidden_a2');

document.getElementById('chkReq2')?.addEventListener('change', function () {
    document.getElementById('groupA2').style.display = this.checked ? '' : 'none';
});

// ------ Confirm ก่อนปิดใช้งานถ้ามีคำขอ Pending ค้างอยู่ ------
document.getElementById('saveAppBtn')?.addEventListener('click', function (e) {
    const chk = document.getElementById('chkActive');
    const pendingCount = parseInt(chk.dataset.pendingCount || '0', 10);
    const wasChecked = chk.defaultChecked; // สถานะตอนโหลดหน้า (ก่อนแก้)

    // เตือนเฉพาะกรณี "เคยเปิดอยู่ แล้วตอนนี้ปิด"
    if (wasChecked && !chk.checked && pendingCount > 0) {
        const ok = confirm(
            `รายการนี้มีคำขอค้างอยู่ ${pendingCount} ใบ\n\n` +
            `การปิดใช้งานจะไม่ลบหรือกระทบคำขอเดิม แต่จะไม่มีใครเลือกรายการนี้สร้างคำขอใหม่ได้อีก\n\n` +
            `ยืนยันปิดใช้งานหรือไม่?`
        );
        if (!ok) e.preventDefault();
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
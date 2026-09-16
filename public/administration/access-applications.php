<?php
/**
 * Access Applications — จัดการ Approver1/Approver2 ต่อระบบ
 * public/administration/access-applications.php
 * เฉพาะ it_admin
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_role(['it_admin']);

$pdo = db();

// ------ ดึงรายการ application พร้อมชื่อ approver ปัจจุบัน (join employee_db) ------
$apps = $pdo->query("
    SELECT a.id, a.app_code, a.app_name, a.description,
           a.default_approver1_employee_id, a.default_approver2_employee_id,
           a.requires_approver2, a.it_action_note, a.is_active,
           COUNT(r.id) AS total_requests,
           SUM(CASE WHEN r.status NOT IN ('Completed','Rejected','Cancelled') THEN 1 ELSE 0 END) AS pending_requests
    FROM access_applications a
    LEFT JOIN access_requests r ON r.application_id = a.id
    GROUP BY a.id, a.app_code, a.app_name, a.description,
             a.default_approver1_employee_id, a.default_approver2_employee_id,
             a.requires_approver2, a.it_action_note, a.is_active
    ORDER BY a.app_name
")->fetchAll();

// รวบรวม employee_id ทั้งหมดที่ต้องเอาชื่อมาโชว์ (approver1 + approver2 ของทุกแอป)
$empIds = [];
foreach ($apps as $a) {
    if ($a['default_approver1_employee_id']) $empIds[] = (int)$a['default_approver1_employee_id'];
    if ($a['default_approver2_employee_id']) $empIds[] = (int)$a['default_approver2_employee_id'];
}
$empIds = array_unique($empIds);

$empNames = [];
if ($empIds) {
    $in = implode(',', array_fill(0, count($empIds), '?'));
    $stmt = employee_db()->prepare("SELECT id, title, first_name, last_name FROM employees WHERE id IN ({$in})");
    $stmt->execute(array_values($empIds));
    foreach ($stmt->fetchAll() as $e) {
        $empNames[(int)$e['id']] = trim($e['title'] . ' ' . $e['first_name'] . ' ' . $e['last_name']);
    }
}

$selectedAppId = isset($_GET['app_id']) && ctype_digit((string)$_GET['app_id']) ? (int)$_GET['app_id'] : 0;
$isCreatingNew = isset($_GET['new']);
$errors = [];
$createErrors = [];

// ------------------------------------------------------------------
//  POST: บันทึกการตั้งค่า
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $appId = isset($_POST['app_id']) && ctype_digit((string)$_POST['app_id']) ? (int)$_POST['app_id'] : 0;

    $approver1Id = isset($_POST['approver1_id']) && ctype_digit((string)$_POST['approver1_id']) ? (int)$_POST['approver1_id'] : null;
    $approver2Id = isset($_POST['approver2_id']) && ctype_digit((string)$_POST['approver2_id']) ? (int)$_POST['approver2_id'] : null;
    $requiresApprover2 = isset($_POST['requires_approver2']) ? 1 : 0;
    $itActionNote = trim((string)($_POST['it_action_note'] ?? ''));
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $appName = trim((string)($_POST['app_name'] ?? ''));

    if ($appName === '') {
        $errors['_general'] = 'กรุณาระบุชื่อระบบ';
    }

    if ($appId <= 0) {
        $errors['_general'] = 'ไม่พบระบบที่เลือก';
    } elseif (!$approver1Id) {
        $errors['_general'] = 'ต้องเลือก Approver1 เสมอ';
    } elseif ($requiresApprover2 && !$approver2Id) {
        $errors['_general'] = 'เปิดใช้ Approver2 แล้ว ต้องเลือกคนด้วย';
    } else {
        try {
            $upd = $pdo->prepare("
                UPDATE access_applications
                SET app_name = :name,
                    default_approver1_employee_id = :a1,
                    default_approver2_employee_id = :a2,
                    requires_approver2 = :req2,
                    it_action_note = :note,
                    is_active = :active
                WHERE id = :id
            ");
            $upd->execute([
                ':name'   => $appName,
                ':a1'     => $approver1Id,
                ':a2'     => $requiresApprover2 ? $approver2Id : null,
                ':req2'   => $requiresApprover2,
                ':note'   => $itActionNote !== '' ? $itActionNote : null,
                ':active' => $isActive,
                ':id'     => $appId,
            ]);
           
            // ------ บันทึก/อัปเดต email ของ approver1/2 เข้า employee_contacts ------
            $approver1Email = trim((string)($_POST['approver1_email'] ?? ''));
            $approver2Email = trim((string)($_POST['approver2_email'] ?? ''));

            foreach ([$approver1Id => $approver1Email, $approver2Id => $approver2Email] as $empId => $email) {
                if (!$empId || $email === '') continue;
                $pdo->prepare("
                    INSERT INTO employee_contacts (employee_id, email, updated_by_ad)
                    VALUES (:eid, :email, :ad)
                    ON DUPLICATE KEY UPDATE email = :email2, updated_by_ad = :ad2
                ")->execute([
                    ':eid' => $empId, ':email' => $email, ':ad' => $_SESSION['iam_username'] ?? null,
                    ':email2' => $email, ':ad2' => $_SESSION['iam_username'] ?? null,
                ]);
            }
             $_SESSION['flash'] = ['type' => 'success', 'message' => 'บันทึกเรียบร้อยแล้ว'];
            header('Location: /it-asset-manager/administration/access-applications.php?app_id=' . $appId);
            exit;

        } catch (PDOException $e) {
            error_log('[ACCESS APP SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ';
        }
    }
}
// ------------------------------------------------------------------
//  POST: สร้างระบบใหม่ (แยก action จากการตั้ง Approver)
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_app') {
    csrf_verify();
    $newCode = strtoupper(trim((string)($_POST['app_code'] ?? '')));
    $newName = trim((string)($_POST['app_name'] ?? ''));
    $newDesc = trim((string)($_POST['description'] ?? ''));

    if ($newCode === '' || !preg_match('/^[A-Z0-9_]+$/', $newCode)) {
        $createErrors['app_code'] = 'App Code ต้องเป็นตัวอักษรอังกฤษพิมพ์ใหญ่/ตัวเลข/underscore เท่านั้น';
    }
    if ($newName === '') {
        $createErrors['app_name'] = 'กรุณาระบุชื่อระบบ';
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
            $ins = $pdo->prepare("
                INSERT INTO access_applications (app_code, app_name, description, requires_approver2, is_active)
                VALUES (:code, :name, :desc, 0, 1)
            ");
            $ins->execute([
                ':code' => $newCode,
                ':name' => $newName,
                ':desc' => $newDesc !== '' ? $newDesc : null,
            ]);
            $newId = (int)$pdo->lastInsertId();
            $_SESSION['flash'] = ['type' => 'success', 'message' => "เพิ่มระบบ \"{$newName}\" เรียบร้อยแล้ว กรุณาตั้งค่า Approver ต่อ"];
            header('Location: /it-asset-manager/administration/access-applications.php?app_id=' . $newId);
            exit;
        } catch (PDOException $e) {
            error_log('[ACCESS APP CREATE FAIL] ' . $e->getMessage());
            $createErrors['_general'] = 'บันทึกไม่สำเร็จ';
        }
    }
}

$selectedApp = null;
foreach ($apps as $a) {
    if ((int)$a['id'] === $selectedAppId) { $selectedApp = $a; break; }
}

$selectedApp = null;
foreach ($apps as $a) {
    if ((int)$a['id'] === $selectedAppId) { $selectedApp = $a; break; }
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title  = 'Access Applications — Approver Setup';
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
    <div class="col-md-5">
        <div class="card h-100">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <strong>เลือกระบบ</strong>
                <a href="?new=1" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg"></i> เพิ่มระบบใหม่
                </a>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($apps as $a):
                    $a1Name = $a['default_approver1_employee_id'] ? ($empNames[(int)$a['default_approver1_employee_id']] ?? '—') : null;
                    $a2Name = $a['default_approver2_employee_id'] ? ($empNames[(int)$a['default_approver2_employee_id']] ?? '—') : null;
                    $isSel  = $selectedAppId === (int)$a['id'];
                ?>
                    <a href="?app_id=<?= e($a['id']) ?>"
                       class="list-group-item list-group-item-action <?= $isSel ? 'active' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start">
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
        </div>
    </div>

    <div class="col-md-7">
        <div class="card h-100">
            <?php if ($isCreatingNew): ?>
                <div class="card-header bg-white"><strong>เพิ่มระบบใหม่</strong></div>
                <div class="card-body">
                    <?php if (!empty($createErrors['_general'])): ?>
                        <div class="alert alert-danger"><?= e($createErrors['_general']) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="action" value="create_app">

                        <div class="mb-3">
                            <label class="form-label">App Code <span class="text-danger">*</span></label>
                            <input type="text" name="app_code" class="form-control <?= isset($createErrors['app_code']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($_POST['app_code'] ?? '') ?>" placeholder="เช่น WMS, SAP_HR"
                                   style="text-transform:uppercase;">
                            <div class="form-text">ตัวอักษรอังกฤษพิมพ์ใหญ่/ตัวเลข/underscore เท่านั้น ใช้อ้างอิงในระบบ แก้ทีหลังไม่ได้</div>
                            <?php if (isset($createErrors['app_code'])): ?>
                                <div class="invalid-feedback"><?= e($createErrors['app_code']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">ชื่อระบบ <span class="text-danger">*</span></label>
                            <input type="text" name="app_name" class="form-control <?= isset($createErrors['app_name']) ? 'is-invalid' : '' ?>"
                                   value="<?= e($_POST['app_name'] ?? '') ?>" placeholder="เช่น WMS (Warehouse Management System)">
                            <?php if (isset($createErrors['app_name'])): ?>
                                <div class="invalid-feedback"><?= e($createErrors['app_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">คำอธิบาย (ถ้ามี)</label>
                            <textarea name="description" class="form-control" rows="2"><?= e($_POST['description'] ?? '') ?></textarea>
                        </div>

                        <button class="btn btn-primary"><i class="bi bi-check-lg"></i> สร้างระบบ</button>
                        <a href="?" class="btn btn-outline-secondary">ยกเลิก</a>
                    </form>
                </div>
            <?php elseif ($selectedApp): ?>

                <?php
                $a1Id   = $selectedApp['default_approver1_employee_id'];
                $a2Id   = $selectedApp['default_approver2_employee_id'];
                $a1Name = $a1Id ? ($empNames[(int)$a1Id] ?? '') : '';
                $a2Name = $a2Id ? ($empNames[(int)$a2Id] ?? '') : '';

                                $a1Email = null; $a2Email = null;
                if ($a1Id) {
                    $c = $pdo->prepare("SELECT email FROM employee_contacts WHERE employee_id = :id");
                    $c->execute([':id' => $a1Id]);
                    $a1Email = $c->fetchColumn() ?: null;
                }
                if ($a2Id) {
                    $c = $pdo->prepare("SELECT email FROM employee_contacts WHERE employee_id = :id");
                    $c->execute([':id' => $a2Id]);
                    $a2Email = $c->fetchColumn() ?: null;
                }

                // ------ นับคำขอที่ยังค้างอยู่ (ไม่นับ Completed/Rejected/Cancelled) ------
                $pendingCountStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM access_requests
                    WHERE application_id = :app_id
                      AND status NOT IN ('Completed','Rejected','Cancelled')
                ");
                $pendingCountStmt->execute([':app_id' => $selectedApp['id']]);
                $pendingCount = (int)$pendingCountStmt->fetchColumn();

                ?>
                <div class="card-header bg-white d-flex justify-content-between align-items-center">
                    <strong><?= e($selectedApp['app_name']) ?></strong>
                    <span class="text-muted small">Code: <?= e($selectedApp['app_code']) ?></span>
                </div>
                <div class="card-body">
                    <?php if (!empty($errors['_general'])): ?>
                        <div class="alert alert-danger py-2 small"><?= e($errors['_general']) ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="app_id" value="<?= e($selectedApp['id']) ?>">

                        <div class="mb-3">
                            <label class="form-label">ชื่อระบบ <span class="text-danger">*</span></label>
                            <input type="text" name="app_name" class="form-control"
                                   value="<?= e($selectedApp['app_name']) ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Approver1 <span class="text-danger">*</span></label>

                            <input type="text" id="search_a1" class="form-control"
                                placeholder="พิมพ์ชื่อหรือรหัสพนักงาน" autocomplete="off"
                                value="<?= e($a1Name) ?>">
                            <div id="results_a1" class="list-group mt-1"></div>
                            <input type="hidden" name="approver1_id" id="hidden_a1" value="<?= e($a1Id) ?>">

                            <label class="form-label small text-muted mt-2">Email สำหรับแจ้งเตือน ผู้อนุมัติ</label>
                            <input type="email" name="approver1_email" class="form-control form-control-sm"
                                value="<?= e($a1Email ?? '') ?>" placeholder="name@pacificcoldchain.com">
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="requires_approver2"
                                   id="chkReq2" <?= $selectedApp['requires_approver2'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="chkReq2">ต้องมี ผู้อนุมัติ 2 คน</label>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">อนุมัติคนที่ 2 <span class="text-danger">*</span></label>
                            <input type="text" id="search_a2" class="form-control"
                                placeholder="พิมพ์ชื่อหรือรหัสพนักงาน" autocomplete="off"
                                value="<?= e($a2Name) ?>">
                            <div id="results_a2" class="list-group mt-1"></div>
                            <input type="hidden" name="approver2_id" id="hidden_a2" value="<?= e($a2Id) ?>">

                            <label class="form-label small text-muted mt-2">Email สำหรับแจ้งเตือน ผู้อนุมัติ</label>
                            <input type="email" name="approver2_email" class="form-control form-control-sm"
                                value="<?= e($a2Email ?? '') ?>" placeholder="name@pacificcoldchain.com">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">หมายเหตุงาน IT (แสดงในหน้า IT Action)</label>
                            <input type="text" name="it_action_note" class="form-control"
                                   value="<?= e($selectedApp['it_action_note'] ?? '') ?>"
                                   placeholder="เช่น สร้าง user ใน CCMS">
                        </div>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="is_active"
                                   id="chkActive" data-pending-count="<?= $pendingCount ?>"
                                   <?= $selectedApp['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="chkActive">เปิดใช้งาน (ให้เลือกได้ในฟอร์มขอสิทธิ์)</label>
                        </div>

                        <?php if ($pendingCount > 0): ?>
                            <div class="alert alert-warning py-2 small mb-3">
                                <i class="bi bi-exclamation-triangle"></i>
                                มีคำขอที่ยังไม่เสร็จสิ้น <strong><?= $pendingCount ?> ใบ</strong> อยู่ในระบบนี้
                                (ปิดใช้งานจะไม่กระทบคำขอเดิมที่ค้างอยู่ แต่จะเลือกระบบนี้สร้างคำขอใหม่ไม่ได้)
                            </div>
                        <?php endif; ?>

                        <button type="submit" id="saveAppBtn" class="btn btn-primary"><i class="bi bi-check-lg"></i> บันทึก</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="card-body d-flex align-items-center justify-content-center text-muted" style="min-height:300px;">
                    <div class="text-center">
                        <i class="bi bi-arrow-left-circle" style="font-size:32px;"></i>
                        <div class="mt-2">เลือกระบบทางซ้ายเพื่อตั้งค่า Approver</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// ------ Reusable employee search สำหรับ Approver1/Approver2 ------
function setupEmployeeSearch(inputId, resultsId, hiddenId) {
    const input   = document.getElementById(inputId);
    const results = document.getElementById(resultsId);
    const hidden  = document.getElementById(hiddenId);
    if (!input) return;

    let timer = null;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        const q = input.value.trim();
        results.innerHTML = '';
        hidden.value = ''; // เคลียร์ค่าเดิมเมื่อพิมพ์ใหม่ ป้องกันบันทึกผิดคนถ้าไม่เลือกซ้ำ
        if (q.length < 2) return;
        timer = setTimeout(() => {
            fetch('/it-asset-manager/access-requests/employee_search_ajax.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(list => {
                    results.innerHTML = '';
                    list.forEach(item => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.className = 'list-group-item list-group-item-action';
                        btn.innerHTML = '<div class="fw-semibold">' + item.label + '</div>'
                            + '<div class="text-muted small">' + item.sub + '</div>';
                        btn.addEventListener('click', () => {
                            hidden.value = item.id;
                            input.value = item.label;
                            results.innerHTML = '';
                        });
                        results.appendChild(btn);
                    });
                });
        }, 300);
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

    // เตือนเฉพาะกรณี "เคยเปิดอยู่ แล้วตอนนี้ปิด" (ไม่ใช่ตอนเปิดหรือกดบันทึกโดยไม่แตะ checkbox)
    if (wasChecked && !chk.checked && pendingCount > 0) {
        const ok = confirm(
            `ระบบนี้มีคำขอค้างอยู่ ${pendingCount} ใบ\n\n` +
            `การปิดใช้งานจะไม่ลบหรือกระทบคำขอเดิม แต่จะไม่มีใครเลือกระบบนี้สร้างคำขอใหม่ได้อีก\n\n` +
            `ยืนยันปิดใช้งานหรือไม่?`
        );
        if (!ok) {
            e.preventDefault();
        }
    }
});
</script>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
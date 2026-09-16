<?php
/**
 * Self-Link — ให้ user ผูกบัญชี login เข้ากับ employee record ของตัวเอง
 * public/employees/link-me.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/employee_db.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_role(['it_admin', 'it_staff', 'it_viewer', 'it_borrower']);

$pdo  = db();
$user = iam_user();
$back = $_GET['back'] ?? '/it-asset-manager/index.php';
$back = $_POST['back'] ?? ($_GET['back'] ?? '/it-asset-manager/index.php');

// ------ ถ้าผูกไว้แล้ว ไม่ต้องมาหน้านี้ซ้ำ ------
$chk = $pdo->prepare("SELECT employee_id FROM user_employee_map WHERE auth_user_id = :uid");
$chk->execute([':uid' => $user['user_id']]);
if ($chk->fetch()) {
    header('Location: ' . $back);
    exit;
}

$errors = [];
$search = trim((string)($_GET['q'] ?? ''));
$results = [];

if ($search !== '') {
    $stmt = employee_db()->prepare("
    SELECT id, person_code, title, first_name, last_name, department, position
    FROM employees
    WHERE resign_status = 'Active'
      AND (first_name LIKE :q1 OR last_name LIKE :q2 OR person_code LIKE :q3)
    ORDER BY first_name
    LIMIT 20
");
$stmt->execute([
    ':q1' => '%' . $search . '%',
    ':q2' => '%' . $search . '%',
    ':q3' => '%' . $search . '%',
]);
    $results = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $employeeId = isset($_POST['employee_id']) && ctype_digit((string)$_POST['employee_id'])
        ? (int)$_POST['employee_id'] : 0;

    if ($employeeId <= 0) {
        $errors['employee_id'] = 'กรุณาเลือกพนักงาน';
    } else {
        // ------ ตรวจว่า employee นี้ยังไม่ถูกคนอื่นผูกไปแล้ว ------
        $dup = $pdo->prepare("SELECT id FROM user_employee_map WHERE employee_id = :eid");
        $dup->execute([':eid' => $employeeId]);
        if ($dup->fetchColumn()) {
            $errors['employee_id'] = 'พนักงานคนนี้ถูกผูกกับบัญชีอื่นไปแล้ว หากผิดพลาดกรุณาติดต่อ IT Admin';
        }
    }

        $email = trim((string)($_POST['email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'รูปแบบอีเมลไม่ถูกต้อง';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("
                INSERT INTO user_employee_map (auth_user_id, employee_id, link_method)
                VALUES (:uid, :eid, 'Self-Linked')
            ");
            $ins->execute([':uid' => $user['user_id'], ':eid' => $employeeId]);

            // ------ บันทึก email เข้า employee_contacts ไปพร้อมกัน (ถ้ากรอกมา) ------
            if ($email !== '') {
                $pdo->prepare("
                    INSERT INTO employee_contacts (employee_id, email, updated_by_ad)
                    VALUES (:eid, :email, :ad)
                    ON DUPLICATE KEY UPDATE email = :email2, updated_by_ad = :ad2
                ")->execute([
                    ':eid' => $employeeId, ':email' => $email, ':ad' => $user['username'],
                    ':email2' => $email, ':ad2' => $user['username'],
                ]);
            }

            $pdo->commit();
            header('Location: ' . $back);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[LINK-ME FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ กรุณาลองใหม่';
        }
    }
}

$page_title  = 'ยืนยันตัวตนพนักงาน';
$active_menu = '';
require __DIR__ . '/../../includes/header.php';
?>

<div class="alert alert-info">
    <i class="bi bi-info-circle"></i>
    ก่อนใช้งานฟีเจอร์นี้ กรุณายืนยันว่าคุณคือพนักงานคนไหนในระบบ HR (ทำครั้งเดียว)
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>
<?php if (!empty($errors['employee_id'])): ?>
    <div class="alert alert-warning"><?= e($errors['employee_id']) ?></div>
<?php endif; ?>

<form method="get" class="card mb-3">
    <div class="card-body">
        <label class="form-label">ค้นหาชื่อตัวเอง</label>
        <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
               placeholder="พิมพ์ชื่อ, นามสกุล หรือรหัสพนักงาน">
    </div>
</form>

<?php if ($search !== ''): ?>
<form method="post">
    <?php csrf_field(); ?>
    <input type="hidden" name="back" value="<?= e($back) ?>">
    <div class="card">
        <div class="list-group list-group-flush">
            <?php if (!$results): ?>
                <div class="p-4 text-center text-muted">ไม่พบพนักงานที่ตรงกับคำค้นหา</div>
            <?php else: foreach ($results as $r): ?>
                <label class="list-group-item d-flex align-items-center gap-3">
                    <input type="radio" name="employee_id" value="<?= e($r['id']) ?>" required class="form-check-input">
                    <div>
                        <div class="fw-semibold"><?= e($r['title'] . ' ' . $r['first_name'] . ' ' . $r['last_name']) ?></div>
                        <div class="text-muted small">
                            รหัส <?= e($r['person_code']) ?> · <?= e($r['department']) ?> · <?= e($r['position']) ?>
                        </div>
                    </div>
                </label>
            <?php endforeach; endif; ?>
        </div>
    </div>
        <?php if ($results): ?>
        <div class="mt-3">
            <label class="form-label">Email สำหรับรับการแจ้งเตือน (ถ้ามี)</label>
            <input type="email" name="email" class="form-control <?= isset($errors['email']) ? 'is-invalid' : '' ?>"
                   placeholder="name@company.com" value="<?= e($_POST['email'] ?? '') ?>">
            <?php if (isset($errors['email'])): ?>
                <div class="invalid-feedback"><?= e($errors['email']) ?></div>
            <?php endif; ?>
        </div>
        <button class="btn btn-primary mt-3"><i class="bi bi-check-lg"></i> ยืนยันว่านี่คือฉัน</button>
    <?php endif; ?>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
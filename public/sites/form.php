<?php
/** Site — Add / Edit */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_role(['it_admin','it_staff']);
require_module_access('SITES');

$pdo = db();

$id     = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$errors = [];

$row = [
    'site_name'      => '',
    'site_type'      => 'Warehouse',
    'address'        => '',
    'contact_person' => '',
    'contact_phone'  => '',
];
$allowedType = ['Warehouse','Office','Data Center','Logistics Hub'];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM sites WHERE id = :id");
    $stmt->execute([':id'=>$id]);
    $existing = $stmt->fetch();
    if (!$existing) { http_response_code(404); exit('Site not found'); }
    $row = array_merge($row, $existing);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (array_keys($row) as $k) {
        $row[$k] = trim((string)($_POST[$k] ?? ''));
    }
    if ($row['site_name'] === '') $errors['site_name'] = 'จำเป็นต้องระบุชื่อสาขา';
    if (!in_array($row['site_type'], $allowedType, true)) $errors['site_type'] = 'ประเภทไม่ถูกต้อง';

    // unique site_name
    if (!isset($errors['site_name'])) {
        $sql = "SELECT id FROM sites WHERE site_name = :name" . ($isEdit ? " AND id <> :id" : "");
        $chk = $pdo->prepare($sql);
        $chk->bindValue(':name', $row['site_name']);
        if ($isEdit) $chk->bindValue(':id', $id, PDO::PARAM_INT);
        $chk->execute();
        if ($chk->fetchColumn()) $errors['site_name'] = 'ชื่อสาขานี้ถูกใช้แล้ว';
    }

    if (!$errors) {
        $bind = [
            ':site_name'      => $row['site_name'],
            ':site_type'      => $row['site_type'],
            ':address'        => $row['address']        !== '' ? $row['address']        : null,
            ':contact_person' => $row['contact_person'] !== '' ? $row['contact_person'] : null,
            ':contact_phone'  => $row['contact_phone']  !== '' ? $row['contact_phone']  : null,
        ];
        if ($isEdit) {
            $sql = "UPDATE sites SET
                        site_name=:site_name, site_type=:site_type, address=:address,
                        contact_person=:contact_person, contact_phone=:contact_phone
                    WHERE id=:id";
            $bind[':id'] = $id;
        } else {
            $sql = "INSERT INTO sites (site_name, site_type, address, contact_person, contact_phone)
                    VALUES (:site_name, :site_type, :address, :contact_person, :contact_phone)";
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            $_SESSION['flash'] = ['type'=>'success','message'=>$isEdit?'บันทึกการแก้ไข':'เพิ่มสาขาเรียบร้อย'];
            header('Location: ../sites/index.php');
            exit;
        } catch (PDOException $e) {
            error_log('[SITE SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ';
        }
    }
}

$page_title  = $isEdit ? ('Edit Site · ' . $row['site_name']) : 'Add Site';
$active_menu = 'sites';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="../sites/index.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับไปหน้ารายการ
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($page_title) ?></h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<form method="post" novalidate style="max-width:680px;">
    <?php csrf_field(); ?>
    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Site Name <span class="text-danger">*</span></label>
                    <input type="text" name="site_name" maxlength="100"
                           value="<?= e($row['site_name']) ?>"
                           class="form-control <?= isset($errors['site_name'])?'is-invalid':'' ?>" required>
                    <?php if (isset($errors['site_name'])): ?>
                        <div class="invalid-feedback"><?= e($errors['site_name']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Type</label>
                    <select name="site_type" class="form-select">
                        <?php foreach ($allowedType as $t): ?>
                            <option value="<?= e($t) ?>" <?= $row['site_type']===$t?'selected':'' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea name="address" rows="2" class="form-control"><?= e($row['address']) ?></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Person</label>
                    <input type="text" name="contact_person" maxlength="100"
                           value="<?= e($row['contact_person']) ?>" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Contact Phone</label>
                    <input type="text" name="contact_phone" maxlength="50"
                           value="<?= e($row['contact_phone']) ?>" class="form-control">
                </div>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-end gap-2 mb-5">
        <a href="../sites/index.php" class="btn btn-outline-secondary">ยกเลิก</a>
        <button class="btn btn-primary">
            <i class="bi bi-check-lg"></i> <?= $isEdit?'บันทึกการแก้ไข':'เพิ่มสาขา' ?>
        </button>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

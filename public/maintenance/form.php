<?php
/**
 * Maintenance Log — Add / Edit
 * public/maintenance/form.php
 * รองรับการเปิดจากหน้า asset โดยส่ง ?asset_id=N (preselect asset)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff']);
require_module_access('MAINTENANCE');



require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

$id     = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$errors = [];

$row = [
    'asset_id'     => isset($_GET['asset_id']) && ctype_digit((string)$_GET['asset_id']) ? (int)$_GET['asset_id'] : '',
    'pm_date'      => date('Y-m-d'),
    'type'         => 'PM',
    'description'  => '',
    'performed_by' => '',
    'cost'         => '0.00',
    'next_pm_date' => '',
];
$allowedType = ['PM','Repair'];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM maintenance_logs WHERE id = :id");
    $stmt->execute([':id'=>$id]);
    $existing = $stmt->fetch();
    if (!$existing) { http_response_code(404); exit('Log not found'); }
    $row = array_merge($row, $existing);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (array_keys($row) as $k) {
        $row[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($row['asset_id'] === '' || !ctype_digit((string)$row['asset_id'])) {
        $errors['asset_id'] = 'จำเป็นต้องเลือก Asset';
    }
    if (!in_array($row['type'], $allowedType, true)) {
        $errors['type'] = 'Type ไม่ถูกต้อง';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['pm_date'])) {
        $errors['pm_date'] = 'รูปแบบวันที่ไม่ถูกต้อง';
    }
    if ($row['next_pm_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row['next_pm_date'])) {
        $errors['next_pm_date'] = 'รูปแบบวันที่ไม่ถูกต้อง';
    }
    if ($row['cost'] !== '' && !is_numeric($row['cost'])) {
        $errors['cost'] = 'ค่าใช้จ่ายต้องเป็นตัวเลข';
    }

    // ตรวจว่า asset มีอยู่จริง
    if (!isset($errors['asset_id'])) {
        $chk = $pdo->prepare("SELECT id FROM hardware_assets WHERE id = :id");
        $chk->execute([':id' => (int)$row['asset_id']]);
        if (!$chk->fetchColumn()) $errors['asset_id'] = 'ไม่พบ Asset นี้';
    }

    if (!$errors) {
        $bind = [
            ':asset_id'     => (int)$row['asset_id'],
            ':pm_date'      => $row['pm_date'],
            ':type'         => $row['type'],
            ':description'  => $row['description'] !== '' ? $row['description'] : null,
            ':performed_by' => $row['performed_by'] !== '' ? $row['performed_by'] : null,
            ':cost'         => $row['cost'] !== '' ? (float)$row['cost'] : 0.0,
            ':next_pm_date' => $row['next_pm_date'] !== '' ? $row['next_pm_date'] : null,
        ];

        if ($isEdit) {
            $sql = "UPDATE maintenance_logs SET
                        asset_id=:asset_id, pm_date=:pm_date, type=:type,
                        description=:description, performed_by=:performed_by,
                        cost=:cost, next_pm_date=:next_pm_date
                    WHERE id=:id";
            $bind[':id'] = $id;
        } else {
            $sql = "INSERT INTO maintenance_logs
                        (asset_id, pm_date, type, description, performed_by, cost, next_pm_date)
                    VALUES
                        (:asset_id, :pm_date, :type, :description, :performed_by, :cost, :next_pm_date)";
        }
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bind);
            $_SESSION['flash'] = ['type'=>'success','message'=>$isEdit?'บันทึกการแก้ไข':'เพิ่มบันทึกใหม่'];
            header('Location: /maintenance/index.php');
            exit;
        } catch (PDOException $e) {
            error_log('[MAINT SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ';
        }
    }
}

$assets = $pdo->query("
    SELECT id, asset_id, brand, model
    FROM hardware_assets
    ORDER BY asset_id
")->fetchAll();

$page_title  = $isEdit ? 'Edit Maintenance Log' : 'Add Maintenance Log';
$active_menu = 'maintenance';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/maintenance/index.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับไปหน้ารายการ
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($page_title) ?></h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<form method="post" novalidate style="max-width:780px;">
    <?php csrf_field(); ?>

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Asset <span class="text-danger">*</span></label>
                    <select name="asset_id"
                            class="form-select <?= isset($errors['asset_id'])?'is-invalid':'' ?>" required>
                        <option value="">— เลือก asset —</option>
                        <?php foreach ($assets as $a): ?>
                            <option value="<?= e($a['id']) ?>"
                                <?= (int)$row['asset_id'] === (int)$a['id'] ? 'selected' : '' ?>>
                                <?= e($a['asset_id']) ?> · <?= e($a['brand']) ?> <?= e($a['model']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (isset($errors['asset_id'])): ?>
                        <div class="invalid-feedback"><?= e($errors['asset_id']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Type <span class="text-danger">*</span></label>
                    <select name="type" class="form-select">
                        <?php foreach ($allowedType as $t): ?>
                            <option value="<?= e($t) ?>" <?= $row['type']===$t?'selected':'' ?>><?= e($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label">Date <span class="text-danger">*</span></label>
                    <input type="date" name="pm_date" value="<?= e($row['pm_date']) ?>"
                           class="form-control <?= isset($errors['pm_date'])?'is-invalid':'' ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Cost (THB)</label>
                    <input type="number" step="0.01" min="0" name="cost"
                           value="<?= e($row['cost']) ?>"
                           class="form-control <?= isset($errors['cost'])?'is-invalid':'' ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Next PM Date</label>
                    <input type="date" name="next_pm_date"
                           value="<?= e($row['next_pm_date']) ?>"
                           class="form-control <?= isset($errors['next_pm_date'])?'is-invalid':'' ?>">
                </div>

                <div class="col-12">
                    <label class="form-label">Performed By</label>
                    <input type="text" name="performed_by" maxlength="100"
                           value="<?= e($row['performed_by']) ?>" class="form-control"
                           placeholder="ชื่อช่าง / vendor">
                </div>

                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" rows="4" class="form-control"
                              placeholder="งานที่ทำ, อาการ, อะไหล่ที่เปลี่ยน ฯลฯ"><?= e($row['description']) ?></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-5">
        <a href="../maintenance/index.php" class="btn btn-outline-secondary">ยกเลิก</a>
        <button class="btn btn-primary">
            <i class="bi bi-check-lg"></i> <?= $isEdit?'บันทึกการแก้ไข':'เพิ่มบันทึก' ?>
        </button>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

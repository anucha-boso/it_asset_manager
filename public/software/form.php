<?php
ini_set("display_errors",1);error_reporting(E_ALL);
/**
 * Software License — Add / Edit form
 * public/software/form.php
 */
//declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff']);
require_module_access('SOFTWARE');

require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

$id     = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$errors = [];

$row = [
    'software_id'           => '',
    'software_name'         => '',
    'publisher'             => '',
    'license_type'          => '',
    'license_key'           => '',
    'total_seats'           => '1',
    'cost'                  => '',
    'purchase_date'         => '',
    'expiry_date'           => '',
    'renewal_reminder_days' => '30',
    'vendor'                => '',
    'notes'                 => '',
     'site_id'              => '',
];

if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM software_licenses WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) { http_response_code(404); exit('License not found'); }
    $row = array_merge($row, $existing);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (array_keys($row) as $k) {
        $row[$k] = trim((string)($_POST[$k] ?? ''));
    }

    if ($row['software_id']   === '') $errors['software_id']   = 'จำเป็นต้องระบุ SW ID';
    if ($row['software_name'] === '') $errors['software_name'] = 'จำเป็นต้องระบุชื่อซอฟต์แวร์';
    if ($row['total_seats']   === '' || !ctype_digit($row['total_seats']) || (int)$row['total_seats'] < 1) {
        $errors['total_seats'] = 'จำนวน seat ต้องเป็นจำนวนเต็มมากกว่า 0';
    }
    if ($row['cost'] !== '' && !is_numeric($row['cost'])) {
        $errors['cost'] = 'ค่าใช้จ่ายต้องเป็นตัวเลข';
    }
    if ($row['renewal_reminder_days'] !== '' && !ctype_digit((string)$row['renewal_reminder_days'])) {
        $errors['renewal_reminder_days'] = 'จำนวนวันแจ้งเตือนต้องเป็นจำนวนเต็ม';
    }
    foreach (['purchase_date','expiry_date'] as $df) {
        if ($row[$df] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $row[$df])) {
            $errors[$df] = 'รูปแบบวันที่ไม่ถูกต้อง';
        }
    }

    // unique sw id
    if (!isset($errors['software_id'])) {
        $sql = "SELECT id FROM software_licenses WHERE software_id = :sid"
             . ($isEdit ? " AND id <> :id" : "");
        $check = $pdo->prepare($sql);
        $check->bindValue(':sid', $row['software_id']);
        if ($isEdit) $check->bindValue(':id', $id, PDO::PARAM_INT);
        $check->execute();
        if ($check->fetchColumn()) $errors['software_id'] = 'SW ID นี้ถูกใช้แล้ว';
    }

    // ห้ามลด total_seats ต่ำกว่า seat ที่ใช้อยู่
    if ($isEdit && !isset($errors['total_seats'])) {
        $usedStmt = $pdo->prepare("SELECT COUNT(*) FROM software_allocation_map WHERE software_id = :id AND status = 'Installed'");
        $usedStmt->execute([':id' => $id]);
        $usedCount = (int)$usedStmt->fetchColumn();
        if ((int)$row['total_seats'] < $usedCount) {
            $errors['total_seats'] = "ไม่สามารถลด seat ต่ำกว่าที่กำลังใช้อยู่ ({$usedCount}) ได้";
        }
    }

    if (!$errors) {
        $bind = $row;
        foreach (['publisher','license_type','license_key','purchase_date','expiry_date','vendor','notes'] as $k) {
            if ($bind[$k] === '') $bind[$k] = null;
        }
        $bind['total_seats']           = (int)$bind['total_seats'];
        $bind['cost']                  = $bind['cost'] !== '' ? (float)$bind['cost'] : null;
        $bind['renewal_reminder_days'] = $bind['renewal_reminder_days'] !== '' ? (int)$bind['renewal_reminder_days'] : 30;
        $bind['site_id'] = $bind['site_id'] !== '' ? (int)$bind['site_id'] : null;

        try {
            if ($isEdit) {
                    $sql = "UPDATE software_licenses SET
                            software_id=:software_id, software_name=:software_name, publisher=:publisher,
                            license_type=:license_type, license_key=:license_key, total_seats=:total_seats,
                            cost=:cost, purchase_date=:purchase_date, expiry_date=:expiry_date,
                            renewal_reminder_days=:renewal_reminder_days, vendor=:vendor, notes=:notes,
                            site_id=:site_id
                        WHERE id=:id";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':software_id'           => $bind['software_id'],
                        ':software_name'         => $bind['software_name'],
                        ':publisher'             => $bind['publisher'],
                        ':license_type'          => $bind['license_type'],
                        ':license_key'           => $bind['license_key'],
                        ':total_seats'           => $bind['total_seats'],
                        ':cost'                  => $bind['cost'],
                        ':purchase_date'         => $bind['purchase_date'],
                        ':expiry_date'           => $bind['expiry_date'],
                        ':renewal_reminder_days' => $bind['renewal_reminder_days'],
                        ':vendor'                => $bind['vendor'],
                        ':notes'                 => $bind['notes'],
                         ':site_id'               => $bind['site_id'],
                        ':id'                    => $id,
                    ]);
                } else {
                $sql = "INSERT INTO software_licenses
                        (software_id, software_name, publisher, license_type, license_key, total_seats,
                         cost, purchase_date, expiry_date, renewal_reminder_days, vendor, notes, site_id)
                    VALUES
                        (:software_id, :software_name, :publisher, :license_type, :license_key, :total_seats,
                         :cost, :purchase_date, :expiry_date, :renewal_reminder_days, :vendor, :notes, :site_id)";

                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':software_id'           => $bind['software_id'],
                    ':software_name'         => $bind['software_name'],
                    ':publisher'             => $bind['publisher'],
                    ':license_type'          => $bind['license_type'],
                    ':license_key'           => $bind['license_key'],
                    ':total_seats'           => $bind['total_seats'],
                    ':cost'                  => $bind['cost'],
                    ':purchase_date'         => $bind['purchase_date'],
                    ':expiry_date'           => $bind['expiry_date'],
                    ':renewal_reminder_days' => $bind['renewal_reminder_days'],
                    ':vendor'                => $bind['vendor'],
                    ':notes'                 => $bind['notes'],
                    ':site_id'               => $bind['site_id'],
                ]);
            }

            $_SESSION['flash'] = ['type'=>'success', 'message'=>$isEdit?'บันทึกการแก้ไขเรียบร้อย':'เพิ่ม license เรียบร้อย'];
            header('Location: /it-asset-manager/software/index.php');
            exit;
        } catch (PDOException $e) {
            error_log('[SW SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}
$sites = $pdo->query("SELECT id, site_name FROM sites ORDER BY site_name")->fetchAll();
$page_title  = $isEdit ? ('Edit License · ' . $row['software_name']) : 'Add License';
$active_menu = 'software';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="/it-asset-manager/software/index.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับไปหน้ารายการ
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($page_title) ?></h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<form method="post" novalidate>
    <?php csrf_field(); ?>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card mb-3">
                <div class="card-header bg-white"><strong>ข้อมูล License</strong></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">SW ID <span class="text-danger">*</span></label>
                            <input type="text" name="software_id" maxlength="50"
                                   value="<?= e($row['software_id']) ?>"
                                   class="form-control <?= isset($errors['software_id'])?'is-invalid':'' ?>"
                                   placeholder="SW-M365-001" required>
                            <?php if (isset($errors['software_id'])): ?>
                                <div class="invalid-feedback"><?= e($errors['software_id']) ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Software Name <span class="text-danger">*</span></label>
                            <input type="text" name="software_name" maxlength="150"
                                   value="<?= e($row['software_name']) ?>"
                                   class="form-control <?= isset($errors['software_name'])?'is-invalid':'' ?>" required>
                            <?php if (isset($errors['software_name'])): ?>
                                <div class="invalid-feedback"><?= e($errors['software_name']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label">Publisher</label>
                            <input type="text" name="publisher" maxlength="100"
                                   value="<?= e($row['publisher']) ?>" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">License Type</label>
                            <input type="text" name="license_type" maxlength="50" list="lic-types"
                                   value="<?= e($row['license_type']) ?>" class="form-control"
                                   placeholder="Subscription / Perpetual / OEM">
                            <datalist id="lic-types">
                                <option value="Subscription">
                                <option value="Perpetual">
                                <option value="OEM">
                                <option value="Volume">
                            </datalist>
                        </div>

                        <div class="col-12">
                            <label class="form-label">License Key</label>
                            <textarea name="license_key" rows="2" class="form-control"
                                      placeholder="เก็บแบบเข้ารหัสในระดับ application หากเป็น production"><?= e($row['license_key']) ?></textarea>
                        </div>

                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <textarea name="notes" rows="3" class="form-control"><?= e($row['notes']) ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card mb-3">
                <div class="card-header bg-white"><strong>Seats / การจัดซื้อ</strong></div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Total Seats <span class="text-danger">*</span></label>
                        <input type="number" name="total_seats" min="1" step="1"
                               value="<?= e($row['total_seats']) ?>"
                               class="form-control <?= isset($errors['total_seats'])?'is-invalid':'' ?>" required>
                        <?php if (isset($errors['total_seats'])): ?>
                            <div class="invalid-feedback"><?= e($errors['total_seats']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Cost (THB)</label>
                        <input type="number" step="0.01" min="0" name="cost"
                               value="<?= e($row['cost']) ?>"
                               class="form-control <?= isset($errors['cost'])?'is-invalid':'' ?>">
                        <?php if (isset($errors['cost'])): ?>
                            <div class="invalid-feedback"><?= e($errors['cost']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Site</label>
                        <select name="site_id" class="form-select">
                            <option value="">— ส่วนกลาง / ไม่ระบุ —</option>
                            <?php foreach ($sites as $s): ?>
                                <option value="<?= e($s['id']) ?>"
                                    <?= (string)$row['site_id'] === (string)$s['id'] ? 'selected' : '' ?>>
                                    <?= e($s['site_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" name="purchase_date"
                               value="<?= e($row['purchase_date']) ?>" class="form-control">
                    </div>
                    <div class="mb-2">
                        <label class="form-label">Expiry Date</label>
                        <input type="date" name="expiry_date"
                               value="<?= e($row['expiry_date']) ?>" class="form-control">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">แจ้งเตือนล่วงหน้า (วัน)</label>
                        <input type="number" min="1" step="1" name="renewal_reminder_days"
                               value="<?= e($row['renewal_reminder_days']) ?>"
                               class="form-control <?= isset($errors['renewal_reminder_days'])?'is-invalid':'' ?>">
                        <?php if (isset($errors['renewal_reminder_days'])): ?>
                            <div class="invalid-feedback"><?= e($errors['renewal_reminder_days']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3 mb-5">
        <a href="/it-asset-manager/software/index.php" class="btn btn-outline-secondary">ยกเลิก</a>
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-check-lg"></i> <?= $isEdit?'บันทึกการแก้ไข':'เพิ่ม License' ?>
        </button>
    </div>
</form>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
<?php
/**
 * PM Template — Add/Edit info + manage checklist items
 * /var/www/lab/it-asset-manager/public/maintenance/pm_template_form.php?id=<template_id>
 *
 * เพิ่ม field interval_days (จำนวนวันระหว่างรอบ PM ถัดไป) ให้แก้ผ่าน UI ได้
 * ต้องรัน migration_pm_rolling_interval.sql มาก่อนแล้ว (เพิ่ม pm_templates.interval_days)
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

$template = ['name' => '', 'applies_to' => '', 'interval_days' => '30', 'is_active' => '1'];
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM pm_templates WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $existing = $stmt->fetch();
    if (!$existing) { http_response_code(404); exit('Template not found'); }
    $template = $existing;
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ── POST actions ──────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    /* --- Save template info (name / applies_to / interval_days / is_active) --- */
    if ($action === 'save_template') {
        $name         = trim((string)($_POST['name'] ?? ''));
        $selected     = $_POST['applies_to'] ?? [];
        $customCat    = trim((string)($_POST['applies_to_custom'] ?? ''));
        $isActive     = isset($_POST['is_active']) ? 1 : 0;
        $intervalDays = isset($_POST['interval_days']) && ctype_digit((string)$_POST['interval_days'])
            ? (int)$_POST['interval_days'] : 0;

        $catList = array_filter(array_map('trim', (array)$selected));
        if ($customCat !== '') $catList[] = $customCat;
        $catList = array_unique($catList);
        $appliesTo = implode(',', $catList);

        if ($name === '') $errors['name'] = 'กรุณาระบุชื่อ Template';
        if (empty($catList)) $errors['applies_to'] = 'กรุณาเลือกหรือระบุ category อย่างน้อย 1 รายการ';
        foreach ($catList as $c) {
            if (strpos($c, ',') !== false) {
                $errors['applies_to'] = 'ชื่อ category ห้ามมีเครื่องหมายจุลภาค (,)';
                break;
            }
        }
        if (mb_strlen($appliesTo) > 50) $errors['applies_to'] = 'รวมความยาว category เกิน 50 ตัวอักษร กรุณาลดจำนวน';
        if ($intervalDays < 1 || $intervalDays > 3650) {
            $errors['interval_days'] = 'รอบ PM ต้องเป็นจำนวนเต็ม 1-3650 วัน';
        }

        if (!$errors) {
            if ($isEdit) {
                $pdo->prepare("
                    UPDATE pm_templates
                    SET name=:name, applies_to=:applies_to, interval_days=:interval, is_active=:active
                    WHERE id=:id
                ")->execute([
                    ':name'=>$name, ':applies_to'=>$appliesTo, ':interval'=>$intervalDays,
                    ':active'=>$isActive, ':id'=>$id,
                ]);
                $_SESSION['flash'] = ['type'=>'success','message'=>'บันทึกข้อมูล Template เรียบร้อย'];
                header("Location: /it-asset-manager/maintenance/pm_template_form.php?id={$id}");
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO pm_templates (name, applies_to, interval_days, is_active)
                    VALUES (:name, :applies_to, :interval, :active)
                ");
                $stmt->execute([
                    ':name'=>$name, ':applies_to'=>$appliesTo, ':interval'=>$intervalDays, ':active'=>$isActive,
                ]);
                $newId = (int)$pdo->lastInsertId();
                $_SESSION['flash'] = ['type'=>'success','message'=>'สร้าง Template เรียบร้อย — เพิ่ม checklist items ได้เลย'];
                header("Location: /it-asset-manager/maintenance/pm_template_form.php?id={$newId}");
            }
            exit;
        }
    }

    /* --- Add checklist item (ต้องมี template id แล้วเท่านั้น) --- */
    if ($action === 'add_item' && $isEdit) {
        $section    = trim((string)($_POST['section'] ?? ''));
        $itemText   = trim((string)($_POST['item_text'] ?? ''));
        $isCritical = isset($_POST['is_critical']) ? 1 : 0;
        $frequency  = trim((string)($_POST['frequency'] ?? '')) ?: 'ทุกรอบ';

        if ($section === '')  $errors['item'] = 'กรุณาระบุ Section';
        if ($itemText === '') $errors['item'] = 'กรุณาระบุรายการตรวจสอบ';

        if (!$errors) {
            $ordStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM pm_template_items WHERE template_id=:tid");
            $ordStmt->execute([':tid'=>$id]);
            $nextOrder = (int)$ordStmt->fetchColumn() + 1;

            $pdo->prepare("
                INSERT INTO pm_template_items (template_id, section, item_text, is_critical, frequency, sort_order)
                VALUES (:tid, :section, :item_text, :critical, :freq, :ord)
            ")->execute([
                ':tid'      => $id,
                ':section'  => $section,
                ':item_text'=> $itemText,
                ':critical' => $isCritical,
                ':freq'     => $frequency,
                ':ord'      => $nextOrder,
            ]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'เพิ่มรายการตรวจสอบเรียบร้อย'];
            header("Location: /it-asset-manager/maintenance/pm_template_form.php?id={$id}");
            exit;
        }
    }

    /* --- Update checklist item --- */
    if ($action === 'update_item' && $isEdit) {
        $itemId     = isset($_POST['item_id']) && ctype_digit((string)$_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        $section    = trim((string)($_POST['section'] ?? ''));
        $itemText   = trim((string)($_POST['item_text'] ?? ''));
        $isCritical = isset($_POST['is_critical']) ? 1 : 0;
        $frequency  = trim((string)($_POST['frequency'] ?? '')) ?: 'ทุกรอบ';

        if ($itemId > 0 && $section !== '' && $itemText !== '') {
            $pdo->prepare("
                UPDATE pm_template_items
                SET section=:section, item_text=:item_text, is_critical=:critical, frequency=:freq
                WHERE id=:id AND template_id=:tid
            ")->execute([
                ':section'  => $section,
                ':item_text'=> $itemText,
                ':critical' => $isCritical,
                ':freq'     => $frequency,
                ':id'       => $itemId,
                ':tid'      => $id,
            ]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'แก้ไขรายการตรวจสอบเรียบร้อย'];
        }
        header("Location: /it-asset-manager/maintenance/pm_template_form.php?id={$id}");
        exit;
    }

    /* --- Delete checklist item (กันลบถ้าเคยใช้ผลลัพธ์แล้ว) --- */
    if ($action === 'delete_item' && $isEdit) {
        $itemId = isset($_POST['item_id']) && ctype_digit((string)$_POST['item_id']) ? (int)$_POST['item_id'] : 0;
        if ($itemId > 0) {
            $usedStmt = $pdo->prepare("SELECT COUNT(*) FROM pm_check_results WHERE template_item_id = :id");
            $usedStmt->execute([':id' => $itemId]);
            if ((int)$usedStmt->fetchColumn() > 0) {
                $_SESSION['flash'] = ['type'=>'danger','message'=>'ลบไม่ได้ — รายการนี้มีผลตรวจ PM ในอดีตอ้างอิงอยู่แล้ว'];
            } else {
                $pdo->prepare("DELETE FROM pm_template_items WHERE id=:id AND template_id=:tid")
                    ->execute([':id'=>$itemId, ':tid'=>$id]);
                $_SESSION['flash'] = ['type'=>'success','message'=>'ลบรายการตรวจสอบเรียบร้อย'];
            }
        }
        header("Location: /it-asset-manager/maintenance/pm_template_form.php?id={$id}");
        exit;
    }
}

/* ── Load data สำหรับแสดงผล ───────────────────────────────────── */
$allCategories = $pdo->query("
    SELECT DISTINCT category FROM hardware_assets
    WHERE category IS NOT NULL AND category <> '' ORDER BY category
")->fetchAll(PDO::FETCH_COLUMN);

$selectedCats = $isEdit ? array_map('trim', explode(',', $template['applies_to'])) : [];

$items = [];
if ($isEdit) {
    $itemStmt = $pdo->prepare("SELECT * FROM pm_template_items WHERE template_id = :tid ORDER BY sort_order ASC");
    $itemStmt->execute([':tid' => $id]);
    $items = $itemStmt->fetchAll();
}
$itemsBySection = [];
foreach ($items as $it) $itemsBySection[$it['section']][] = $it;

$page_title  = $isEdit ? ('Edit Template · ' . $template['name']) : 'สร้าง PM Template';
$active_menu = 'maintenance';
require __DIR__ . '/../../includes/header.php';
?>

<div class="mb-3">
    <a href="pm_templates.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับไปหน้า PM Templates
    </a>
    <h2 class="h5 mb-0 mt-1"><?= e($page_title) ?></h2>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- ========== Template info ========== -->
<div class="card mb-4">
    <div class="card-header bg-white"><strong>ข้อมูล Template</strong></div>
    <div class="card-body">
        <form method="post">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="save_template">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">ชื่อ Template <span class="text-danger">*</span></label>
                    <input type="text" name="name" maxlength="100" value="<?= e($template['name']) ?>"
                           class="form-control <?= isset($errors['name'])?'is-invalid':'' ?>">
                    <?php if (isset($errors['name'])): ?>
                        <div class="invalid-feedback"><?= e($errors['name']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">รอบ PM (วัน) <span class="text-danger">*</span></label>
                    <input type="number" name="interval_days" min="1" max="3650"
                           value="<?= e($template['interval_days'] ?? '30') ?>"
                           class="form-control <?= isset($errors['interval_days'])?'is-invalid':'' ?>">
                    <div class="form-text">
                        นับจากวันที่ทำ PM ล่าสุดจริง เช่น 30, 60, 90
                    </div>
                    <?php if (isset($errors['interval_days'])): ?>
                        <div class="invalid-feedback"><?= e($errors['interval_days']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-md-3">
                    <label class="form-label">สถานะ</label>
                    <div class="form-check form-switch mt-2">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1"
                               <?= ($template['is_active'] ?? 1) ? 'checked' : '' ?>>
                        <label class="form-check-label">Active</label>
                    </div>
                </div>
                <div class="col-12">
                    <label class="form-label">Category ที่ผูก (applies_to) <span class="text-danger">*</span></label>
                    <div class="d-flex flex-wrap gap-3 mb-2">
                        <?php foreach ($allCategories as $cat): ?>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="applies_to[]"
                                       value="<?= e($cat) ?>" id="cat_<?= e(md5($cat)) ?>"
                                       <?= in_array($cat, $selectedCats, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="cat_<?= e(md5($cat)) ?>"><?= e($cat) ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <input type="text" name="applies_to_custom" maxlength="50" class="form-control"
                           placeholder="เพิ่ม category ใหม่ที่ยังไม่มีในระบบ (พิมพ์แล้ว save)">
                    <?php if (isset($errors['applies_to'])): ?>
                        <div class="text-danger small mt-1"><?= e($errors['applies_to']) ?></div>
                    <?php endif; ?>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> <?= $isEdit ? 'บันทึกข้อมูล Template' : 'สร้าง Template' ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php if ($isEdit): ?>
<!-- ========== Add checklist item ========== -->
<div class="card mb-4">
    <div class="card-header bg-white"><strong>เพิ่มรายการตรวจสอบ</strong></div>
    <div class="card-body">
        <?php if (isset($errors['item'])): ?>
            <div class="alert alert-danger py-2 small"><?= e($errors['item']) ?></div>
        <?php endif; ?>
        <form method="post" class="row g-2 align-items-end">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="add_item">
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Section</label>
                <input type="text" name="section" maxlength="50" class="form-control form-control-sm"
                       list="section-list" placeholder="เช่น Hardware, Software, Network">
                <datalist id="section-list">
                    <?php foreach (array_keys($itemsBySection) as $sec): ?>
                        <option value="<?= e($sec) ?>">
                    <?php endforeach; ?>
                </datalist>
            </div>
            <div class="col-md-4">
                <label class="form-label small text-muted mb-1">รายการตรวจสอบ</label>
                <input type="text" name="item_text" maxlength="255" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">ความถี่</label>
                <input type="text" name="frequency" maxlength="30" class="form-control form-control-sm" value="ทุกรอบ">
            </div>
            <div class="col-md-2">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" name="is_critical" value="1" id="new_critical">
                    <label class="form-check-label small" for="new_critical">Critical</label>
                </div>
            </div>
            <div class="col-md-1">
                <button class="btn btn-sm btn-primary w-100"><i class="bi bi-plus-lg"></i></button>
            </div>
        </form>
    </div>
</div>

<!-- ========== Checklist items by section ========== -->
<?php foreach ($itemsBySection as $sectionName => $secItems): ?>
<div class="card mb-3">
    <div class="card-header bg-white"><strong><?= e($sectionName) ?></strong>
        <span class="text-muted small ms-2"><?= count($secItems) ?> รายการ</span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:13px;">
            <thead class="table-light">
                <tr>
                    <th style="width:50px;">#</th>
                    <th>รายการตรวจสอบ</th>
                    <th style="width:100px;">ความถี่</th>
                    <th style="width:80px;">Critical</th>
                    <th class="text-end" style="width:100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($secItems as $idx => $it): ?>
                <tr>
                    <td class="text-muted"><?= $idx + 1 ?></td>
                    <td><?= e($it['item_text']) ?></td>
                    <td class="text-muted small"><?= e($it['frequency']) ?></td>
                    <td><?= $it['is_critical'] ? '<span class="badge text-bg-warning text-dark">⚠</span>' : '—' ?></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-secondary" title="Edit"
                                    onclick="openEditItem(<?= $it['id'] ?>, '<?= e(addslashes($it['section'])) ?>', '<?= e(addslashes($it['item_text'])) ?>', '<?= e(addslashes($it['frequency'])) ?>', <?= $it['is_critical'] ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <form method="post" style="display:contents" data-confirm="ลบรายการนี้?">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete_item">
                                <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                                <button class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<!-- ========== Edit item modal ========== -->
<div class="modal fade" id="editItemModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?php csrf_field(); ?>
            <input type="hidden" name="action" value="update_item">
            <input type="hidden" name="item_id" id="ei_item_id">
            <div class="modal-header">
                <h5 class="modal-title">แก้ไขรายการตรวจสอบ</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Section</label>
                    <input type="text" name="section" id="ei_section" maxlength="50" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">รายการตรวจสอบ</label>
                    <input type="text" name="item_text" id="ei_item_text" maxlength="255" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">ความถี่</label>
                    <input type="text" name="frequency" id="ei_frequency" maxlength="30" class="form-control">
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="is_critical" id="ei_critical" value="1">
                    <label class="form-check-label" for="ei_critical">Critical Item</label>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึก</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditItem(id, section, text, freq, critical) {
    document.getElementById('ei_item_id').value   = id;
    document.getElementById('ei_section').value   = section;
    document.getElementById('ei_item_text').value = text;
    document.getElementById('ei_frequency').value = freq;
    document.getElementById('ei_critical').checked = critical == 1;
    new bootstrap.Modal(document.getElementById('editItemModal')).show();
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
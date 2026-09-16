<?php
/**
 * PM Templates — List
 * /var/www/lab/it-asset-manager/public/maintenance/pm_templates.php
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff']);
require_module_access('MAINTENANCE');

require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ── POST actions: toggle active / delete ─────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $tplId  = isset($_POST['id']) && ctype_digit((string)$_POST['id']) ? (int)$_POST['id'] : 0;

    if ($action === 'toggle_active' && $tplId > 0) {
        $pdo->prepare("UPDATE pm_templates SET is_active = 1 - is_active WHERE id = :id")
            ->execute([':id' => $tplId]);
        $_SESSION['flash'] = ['type'=>'success','message'=>'ปรับสถานะ Template เรียบร้อย'];
        header('Location: /it-asset-manager/maintenance/pm_templates.php');
        exit;
    }

    if ($action === 'delete' && $tplId > 0) {
        if (!can('delete')) {
            $_SESSION['flash'] = ['type'=>'danger','message'=>'ไม่มีสิทธิ์ลบ Template'];
            header('Location: /it-asset-manager/maintenance/pm_templates.php');
            exit;
        }
        $usedStmt = $pdo->prepare("SELECT COUNT(*) FROM pm_schedules WHERE template_id = :id");
        $usedStmt->execute([':id' => $tplId]);
        $usedCount = (int)$usedStmt->fetchColumn();

        if ($usedCount > 0) {
            $_SESSION['flash'] = ['type'=>'danger',
                'message'=>"ลบไม่ได้ — มี PM Schedule ใช้ Template นี้อยู่ {$usedCount} รายการ กรุณาปิดใช้งาน (Inactive) แทนการลบ"];
        } else {
            $pdo->prepare("DELETE FROM pm_templates WHERE id = :id")->execute([':id' => $tplId]);
            $_SESSION['flash'] = ['type'=>'success','message'=>'ลบ Template เรียบร้อย'];
        }
        header('Location: /it-asset-manager/maintenance/pm_templates.php');
        exit;
    }
}

/* ── Read data ─────────────────────────────────────────────────── */
$templates = $pdo->query("
    SELECT t.*,
           (SELECT COUNT(*) FROM pm_template_items i WHERE i.template_id = t.id) AS item_count,
           (SELECT COUNT(*) FROM pm_schedules ps WHERE ps.template_id = t.id) AS schedule_count
    FROM pm_templates t
    ORDER BY t.is_active DESC, t.name ASC
")->fetchAll();

$page_title  = 'PM Templates';
$active_menu = 'maintenance';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
<div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
    <?= e($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="mb-3">
    <a href="pm_schedule.php" class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับ PM Schedule
    </a>
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h5 mb-0">PM Templates</h2>
    <a href="pm_template_form.php" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg"></i> สร้าง Template ใหม่
    </a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Template Name</th>
                    <th>Category ที่ผูก (applies_to)</th>
                    <th class="text-end">รอบ PM (วัน)</th>
                    <th class="text-end">Checklist Items</th>
                    <th class="text-end">Schedule ที่ใช้อยู่</th>
                    <th>สถานะ</th>
                    <th class="text-end" style="width:160px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$templates): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">ยังไม่มี Template — กด "สร้าง Template ใหม่"</td></tr>
            <?php else: foreach ($templates as $t): ?>
                <tr class="<?= !$t['is_active'] ? 'text-muted' : '' ?>">
                    <td class="fw-semibold"><?= e($t['name']) ?></td>
                    <td>
                        <?php foreach (explode(',', $t['applies_to']) as $cat): ?>
                            <span class="badge text-bg-light border me-1"><?= e(trim($cat)) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td class="text-end">
                        <span class="badge text-bg-light border text-dark">
                            ทุก <?= (int)($t['interval_days'] ?? 30) ?> วัน
                        </span>
                    </td>
                    <td class="text-end"><?= (int)$t['item_count'] ?></td>
                    <td class="text-end"><?= (int)$t['schedule_count'] ?></td>
                    <td>
                        <span class="badge <?= $t['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?>">
                            <?= $t['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="pm_template_form.php?id=<?= $t['id'] ?>"
                               class="btn btn-outline-secondary" title="Edit / Manage Items">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="post" style="display:contents">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="toggle_active">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button class="btn btn-outline-warning" title="<?= $t['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                    <i class="bi bi-power"></i>
                                </button>
                            </form>
                            <form method="post" style="display:contents"
                                  data-confirm="ลบ Template '<?= e($t['name']) ?>'? (ลบได้เฉพาะที่ไม่มี schedule ใช้งานอยู่)">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $t['id'] ?>">
                                <button class="btn btn-outline-danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
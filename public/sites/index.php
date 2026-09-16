<?php
/**
 * =============================================================================
 *  Sites — list
 *  public/sites/index.php
 *  - แสดงสาขาทั้งหมดพร้อมจำนวน asset ในแต่ละสาขา
 * =============================================================================
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff','it_viewer','it_borrower']);
require_module_access('SITES');



require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

$sites = $pdo->query("
    SELECT s.*,
           (SELECT COUNT(*) FROM hardware_assets h WHERE h.site_id = s.id) AS asset_count
    FROM sites s
    ORDER BY s.site_name
")->fetchAll();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title = 'Sites';
$active_menu = 'sites';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-muted small">พบ <strong><?= count($sites) ?></strong> สาขา</div>
    <a href="../sites/form.php" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg"></i> Add Site
    </a>
</div>

<div class="row g-3">
    <?php if (!$sites): ?>
        <div class="col-12">
            <div class="card text-center py-5 text-muted">
                <i class="bi bi-geo-alt" style="font-size:36px;"></i>
                <div class="mt-2">ยังไม่มีสาขา</div>
            </div>
        </div>
    <?php else: foreach ($sites as $s):
        $typeBg = match ($s['site_type']) {
            'Warehouse'    => 'text-bg-info',
            'Office'       => 'text-bg-primary',
            'Data Center'  => 'text-bg-dark',
            'Logistics Hub'=> 'text-bg-warning',
            default        => 'text-bg-secondary',
        };
    ?>
        <div class="col-12 col-md-6 col-xl-4">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h3 class="h6 mb-0"><?= e($s['site_name']) ?></h3>
                        <span class="badge <?= $typeBg ?>"><?= e($s['site_type']) ?></span>
                    </div>
                    <?php if ($s['address']): ?>
                        <div class="text-muted small mb-2">
                            <i class="bi bi-geo-alt"></i> <?= e($s['address']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($s['contact_person']): ?>
                        <div class="small">
                            <i class="bi bi-person"></i> <?= e($s['contact_person']) ?>
                            <?php if ($s['contact_phone']): ?>
                                · <span class="text-muted"><?= e($s['contact_phone']) ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer bg-white d-flex justify-content-between align-items-center">
                    <span class="small text-muted">
                        <i class="bi bi-pc-display"></i>
                        <strong><?= number_format((int)$s['asset_count']) ?></strong> assets
                    </span>
                    <div class="btn-group btn-group-sm">
                        <a href="/assets/index.php?site_id=<?= e($s['id']) ?>"
                           class="btn btn-outline-secondary" title="View assets in site">
                            <i class="bi bi-list"></i>
                        </a>
                        <a href="../sites/form.php?id=<?= e($s['id']) ?>"
                           class="btn btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form action="../sites/delete.php" method="post" style="display:contents"
                              data-confirm="ลบสาขา <?= e($s['site_name']) ?>? (อุปกรณ์ที่อยู่ในสาขานี้จะถูกตั้งเป็นไม่ระบุสาขา)">
                            <?php csrf_field(); ?>
                            <input type="hidden" name="id" value="<?= e($s['id']) ?>">
                            <button class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>

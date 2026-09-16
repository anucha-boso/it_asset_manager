<?php
ini_set("display_errors",1);error_reporting(E_ALL);
/**
 * =============================================================================
 *  Maintenance Logs — list
 *  public/maintenance/index.php
 *  - filter: ค้นหา + type + due (overdue / 30d / all)
 * =============================================================================
 */
//declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff','it_viewer','it_borrower']);
require_module_access('MAINTENANCE');



require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

$search = trim((string)($_GET['q']    ?? ''));
$type   = trim((string)($_GET['type'] ?? ''));   // ''|PM|Repair
$due    = trim((string)($_GET['due']  ?? ''));   // ''|overdue|soon

$where  = [];
$params = [];

if ($search !== '') {
    $where[] = '(h.asset_id LIKE :q1 OR h.brand LIKE :q2 OR h.model LIKE :q3 OR m.description LIKE :q4 OR m.performed_by LIKE :q5)';
    $qLike = '%' . $search . '%';
    $params[':q1'] = $qLike;
    $params[':q2'] = $qLike;
    $params[':q3'] = $qLike;
    $params[':q4'] = $qLike;
    $params[':q5'] = $qLike;
}
if ($type === 'PM' || $type === 'Repair') {
    $where[] = 'm.type = :type';
    $params[':type'] = $type;
}
if ($due === 'overdue') {
    $where[] = 'm.next_pm_date IS NOT NULL AND m.next_pm_date < CURRENT_DATE';
} elseif ($due === 'soon') {
    $where[] = 'm.next_pm_date IS NOT NULL AND m.next_pm_date BETWEEN CURRENT_DATE AND (CURRENT_DATE + INTERVAL 30 DAY)';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "
    SELECT m.*,
           COALESCE(h.asset_id, mb.asset_id, n.asset_id) AS asset_code,
           COALESCE(h.brand, mb.brand, n.brand)           AS brand,
           COALESCE(h.model, mb.model, n.model)           AS model,
           s.site_name,
           DATEDIFF(m.next_pm_date, CURRENT_DATE) AS days_left
    FROM maintenance_logs m
    LEFT JOIN hardware_assets h ON h.id = m.asset_id
    LEFT JOIN mobile_assets   mb ON mb.id = m.mobile_id
    LEFT JOIN network_assets  n  ON n.id = m.network_id
    LEFT JOIN sites s            ON s.id = COALESCE(h.site_id, mb.site_id, n.site_id)
    {$whereSql}
    ORDER BY m.pm_date DESC, m.id DESC
    LIMIT 200
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title = 'Maintenance Logs';
$active_menu = 'maintenance';
require __DIR__ . '/../../includes/header.php';
?>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show">
        <?= e($flash['message']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="get" class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small text-muted mb-1">ค้นหา</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search"></i></span>
                    <input type="text" name="q" value="<?= e($search) ?>" class="form-control"
                           placeholder="Asset, รายละเอียด, ผู้ดำเนินการ">
                </div>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <option value="PM"     <?= $type==='PM'?'selected':'' ?>>PM</option>
                    <option value="Repair" <?= $type==='Repair'?'selected':'' ?>>Repair</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small text-muted mb-1">Next PM</label>
                <select name="due" class="form-select form-select-sm">
                    <option value="">— ทั้งหมด —</option>
                    <option value="overdue" <?= $due==='overdue'?'selected':'' ?>>เลยกำหนดแล้ว</option>
                    <option value="soon"    <?= $due==='soon'?'selected':'' ?>>ครบใน 30 วัน</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-sm btn-primary flex-grow-1"><i class="bi bi-funnel"></i> Filter</button>
                <a href="/maintenance/index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-lg"></i></a>
            </div>
        </div>
    </div>
</form>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div class="text-muted small">พบ <strong><?= count($logs) ?></strong> รายการ</div>
    <a href="../maintenance/form.php" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg"></i> Add Log
    </a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Asset</th>
                    <th>Site</th>
                    <th>Type</th>
                    <th>Description</th>
                    <th>By</th>
                    <th class="text-end">Cost</th>
                    <th>Next PM</th>
                    <th class="text-end" style="width:100px;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$logs): ?>
                <tr><td colspan="9" class="text-center text-muted py-5">
                    <i class="bi bi-inbox" style="font-size:32px;"></i><div class="mt-2">ไม่พบข้อมูล</div>
                </td></tr>
            <?php else: foreach ($logs as $r):
                $typeBadge = $r['type'] === 'PM' ? 'text-bg-info' : 'text-bg-secondary';
                $days = $r['days_left'];
                if ($r['next_pm_date'] === null) { $nB='text-bg-light border'; $nL='—'; }
                elseif ((int)$days < 0)         { $nB='text-bg-danger';  $nL=abs((int)$days).' วันที่เลยกำหนด'; }
                elseif ((int)$days <= 30)       { $nB='text-bg-warning'; $nL=e($r['next_pm_date']).' (เหลือ '.$days.' วัน)'; }
                else                            { $nB='text-bg-light border'; $nL=e($r['next_pm_date']); }
            ?>
                <tr>
                    <td><?= e($r['pm_date']) ?></td>
                    <td>
                        <div class="fw-semibold"><?= e($r['asset_code']) ?></div>
                        <div class="text-muted small"><?= e($r['brand']) ?> <?= e($r['model']) ?></div>
                    </td>
                    <td class="text-muted small"><?= e($r['site_name'] ?? '—') ?></td>
                    <td><span class="badge <?= $typeBadge ?>"><?= e($r['type']) ?></span></td>
                    <td><?= e(mb_substr($r['description'] ?? '', 0, 80)) ?></td>
                    <td class="text-muted small"><?= e($r['performed_by'] ?: '—') ?></td>
                    <td class="text-end"><?= e(number_format((float)$r['cost'], 2)) ?></td>
                    <td><span class="badge <?= $nB ?>"><?= $nL ?></span></td>
                    <td class="text-end">
                        <div class="btn-group btn-group-sm">
                            <a href="../maintenance/form.php?id=<?= e($r['id']) ?>"
                               class="btn btn-outline-secondary" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form action="../maintenance/delete.php" method="post" style="display:contents"
                                  data-confirm="ยืนยันการลบบันทึกนี้?">
                                <?php csrf_field(); ?>
                                <input type="hidden" name="id" value="<?= e($r['id']) ?>">
                                <button class="btn btn-outline-danger" title="Delete"><i class="bi bi-trash"></i></button>
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

<?php
/**
 * Module Access Management — เฉพาะ it_admin
 * public/administration/module-access.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../config/modules.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_role(['it_admin']);

$pdo     = db();
$modules = iam_module_list();

// ------ ค้นหา (filter ฝั่ง server ผ่าน GET) ------
$searchQ = trim((string)($_GET['q'] ?? ''));

$sql = "
    SELECT u.user_id, u.username, u.full_name,
           m.employee_id, e.title, e.first_name, e.last_name, e.department, e.person_code
    FROM users u
    LEFT JOIN it_asset_mgmt.user_employee_map m ON m.auth_user_id = u.user_id
    LEFT JOIN cc_central_employee_db.employees e ON e.id = m.employee_id
    WHERE u.is_active = 1
";
$params = [];
if ($searchQ !== '') {
    $sql .= " AND (u.username LIKE :q1 OR u.full_name LIKE :q2
                   OR e.first_name LIKE :q3 OR e.last_name LIKE :q4 OR e.person_code LIKE :q5)";
    $like = '%' . $searchQ . '%';
    $params = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like];
}
$sql .= " ORDER BY (e.first_name IS NULL), e.first_name, u.full_name";

$authPdo = iam_pdo_auth();
$stmt = $authPdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

// จำนวน module ที่ grant ไปแล้วต่อ user
$moduleCounts = $pdo->query("
    SELECT auth_user_id, COUNT(*) AS c FROM user_module_access GROUP BY auth_user_id
")->fetchAll(PDO::FETCH_KEY_PAIR);

$selectedUserId = isset($_GET['user_id']) && ctype_digit((string)$_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$errors = [];

// ------------------------------------------------------------------
//  POST: บันทึกสิทธิ์
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $targetUserId    = isset($_POST['user_id']) && ctype_digit((string)$_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $selectedModules = $_POST['modules'] ?? [];

    if ($targetUserId <= 0) {
        $errors['_general'] = 'กรุณาเลือก user';
    } else {
        $validModules = array_intersect($selectedModules, array_keys($modules));

        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM user_module_access WHERE auth_user_id = :uid")
                ->execute([':uid' => $targetUserId]);

            if ($validModules) {
                $ins = $pdo->prepare("
                    INSERT INTO user_module_access (auth_user_id, module_code, granted_by_ad)
                    VALUES (:uid, :code, :by)
                ");
                foreach ($validModules as $code) {
                    $ins->execute([
                        ':uid'  => $targetUserId,
                        ':code' => $code,
                        ':by'   => $_SESSION['iam_username'] ?? null,
                    ]);
                }
            }
            $pdo->commit();
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'บันทึกสิทธิ์เรียบร้อยแล้ว'];
            $redirectQ = $searchQ !== '' ? '&q=' . urlencode($searchQ) : '';
            header('Location: /it-asset-manager/administration/module-access.php?user_id=' . $targetUserId . $redirectQ);
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[MODULE ACCESS SAVE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ';
        }
    }
}

$grantedModules = [];
if ($selectedUserId > 0) {
    $stmt2 = $pdo->prepare("SELECT module_code FROM user_module_access WHERE auth_user_id = :uid");
    $stmt2->execute([':uid' => $selectedUserId]);
    $grantedModules = array_column($stmt2->fetchAll(), 'module_code');
}

$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
$page_title  = 'Module Access Management';
$active_menu = 'module_access';
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
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-header bg-white">
                <form method="get" class="d-flex gap-2">
                    <input type="text" name="q" value="<?= e($searchQ) ?>" class="form-control form-control-sm"
                           placeholder="ค้นหา username, ชื่อพนักงาน หรือรหัสพนักงาน...">
                    <button class="btn btn-sm btn-outline-secondary" title="ค้นหา">
                        <i class="bi bi-search"></i>
                    </button>
                    <?php if ($searchQ !== ''): ?>
                        <a href="?" class="btn btn-sm btn-outline-secondary" title="ล้างค้นหา">
                            <i class="bi bi-x-lg"></i>
                        </a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="list-group list-group-flush" style="max-height:600px; overflow-y:auto;">
                <?php if (!$users): ?>
                    <div class="p-4 text-center text-muted">ไม่พบ user ที่ตรงกับคำค้นหา</div>
                <?php else: foreach ($users as $u):
                    $isActive    = $selectedUserId === (int)$u['user_id'];
                    $empName     = $u['employee_id'] ? trim($u['title'] . ' ' . $u['first_name'] . ' ' . $u['last_name']) : null;
                    $displayName = $empName ?: $u['full_name'];
                    $count       = (int)($moduleCounts[$u['user_id']] ?? 0);
                    $qParam      = $searchQ !== '' ? '&q=' . urlencode($searchQ) : '';
                ?>
                    <a href="?user_id=<?= e($u['user_id']) ?><?= $qParam ?>"
                       class="list-group-item list-group-item-action d-flex justify-content-between align-items-start <?= $isActive ? 'active' : '' ?>">
                        <div>
                            <div class="fw-semibold"><?= e($displayName) ?></div>
                            <div class="small <?= $isActive ? '' : 'text-muted' ?>">
                                <?= e($u['username']) ?>
                                <?php if ($u['department']): ?> · <?= e($u['department']) ?><?php endif; ?>
                                <?php if (!$empName): ?> · <span class="fst-italic">ยังไม่ผูก employee</span><?php endif; ?>
                            </div>
                        </div>
                        <?php if ($count > 0): ?>
                            <span class="badge <?= $isActive ? 'text-bg-light' : 'text-bg-primary' ?> rounded-pill">
                                <?= $count ?>
                            </span>
                        <?php endif; ?>
                    </a>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card h-100">
            <?php if ($selectedUserId > 0):
                $selectedUser = null;
                foreach ($users as $u) {
                    if ((int)$u['user_id'] === $selectedUserId) { $selectedUser = $u; break; }
                }
                $selEmpName = $selectedUser && $selectedUser['employee_id']
                    ? trim($selectedUser['title'] . ' ' . $selectedUser['first_name'] . ' ' . $selectedUser['last_name'])
                    : null;
                $selDisplayName = $selEmpName ?: ($selectedUser['full_name'] ?? '');
            ?>
                <div class="card-header bg-white">
                    <strong>สิทธิ์เข้าถึง Module</strong>
                    <div class="text-muted small mt-1">
                        กำลังตั้งค่าให้: <span class="fw-semibold text-dark"><?= e($selDisplayName) ?></span>
                        (<?= e($selectedUser['username'] ?? '') ?>)
                        <?php if ($selectedUser && $selectedUser['department']): ?>
                            · <?= e($selectedUser['department']) ?>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card-body">
                    <form method="post">
                        <?php csrf_field(); ?>
                        <input type="hidden" name="user_id" value="<?= e($selectedUserId) ?>">
                        <?php foreach ($modules as $code => $m): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="modules[]"
                                       value="<?= e($code) ?>" id="mod_<?= e($code) ?>"
                                       <?= in_array($code, $grantedModules, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="mod_<?= e($code) ?>">
                                    <?= e($m['label']) ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                        <button class="btn btn-primary mt-3"><i class="bi bi-check-lg"></i> บันทึก</button>
                    </form>
                </div>
            <?php elseif (!$selectedUser ?? false): ?>
                <div class="card-body d-flex align-items-center justify-content-center text-muted" style="min-height:300px;">
                    <div class="text-center">
                        <i class="bi bi-arrow-left-circle" style="font-size:32px;"></i>
                        <div class="mt-2">เลือก user ทางซ้ายเพื่อตั้งค่าสิทธิ์</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
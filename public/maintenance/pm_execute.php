<?php
/**
 * PM Execution — tick checklist รายข้อ
 * /var/www/lab/it-asset-manager/public/maintenance/pm_execute.php
 *
 * รับ ?schedule_id=N
 * - โหลด schedule + asset + template items
 * - แสดง checklist แบบ tick Pass/Fail/NA
 * - Item ที่ frequency ไม่ตรงรอบ (เทียบกับ quarter ของ schedule) → pre-select N/A ให้อัตโนมัติ
 *   (ยังแก้ไขเองได้ ไม่ force-disable) และบันทึก is_auto_na แยกจากที่ช่างเลือกเอง
 * - บันทึกลง maintenance_logs + pm_check_results
 * - อัปเดต pm_schedules.status = Done
 *
 * หมายเหตุ: ไม่เขียนทับ maintenance_logs.next_pm_date อีกต่อไป (deprecated) —
 * รอบ PM ถัดไปคำนวณจาก pm_schedules ตอนกด Generate/Refresh Plan (rolling interval)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../config/db_connect.php';
require_once __DIR__ . '/../../config/auth.php';
require_once __DIR__ . '/../../includes/module_access.php';
require_role(['it_admin','it_staff']);
require_module_access('MAINTENANCE');



require_once __DIR__ . '/../../includes/csrf.php';
$pdo = db();

/* ผู้ใช้ที่ login อยู่ตอนนี้ — ใช้ pre-fill ผู้ปฏิบัติงาน (แก้ไขได้ ไม่ล็อก
 * เผื่อกรณีคนกรอกข้อมูลไม่ใช่คนลงมือทำจริง เช่น หัวหน้ากรอกแทน / vendor ภายนอก) */
$currentUser = iam_user();

/* ── Load schedule ───────────────────────────────────────────── */
$schedId = isset($_GET['schedule_id']) && ctype_digit((string)$_GET['schedule_id'])
    ? (int)$_GET['schedule_id'] : 0;
if ($schedId <= 0) { http_response_code(400); exit('Missing schedule_id'); }

$viewOnly = isset($_GET['view']) && $_GET['view'] === '1';

$sched = $pdo->prepare("
    SELECT
        ps.*,
        COALESCE(h.asset_id, m.asset_id, n.asset_id)       AS asset_code,
        COALESCE(h.brand, m.brand, n.brand)                 AS brand,
        COALESCE(h.model, m.model, n.model)                 AS model,
        COALESCE(h.category, m.device_type, n.device_type)   AS category,
        COALESCE(h.department, m.department)                AS department,
        COALESCE(h.location, m.location_detail, n.location)  AS location,
        COALESCE(h.user_display, m.user_name)               AS user_display,
        COALESCE(h.serial_number, m.serial_number, n.serial_number) AS serial_number,
        n.hostname, n.ip_mgmt,
        CASE WHEN h.id IS NOT NULL THEN 'hardware'
             WHEN m.id IS NOT NULL THEN 'mobile'
             WHEN n.id IS NOT NULL THEN 'network' END AS source_type,
        s.site_name,
        pt.id AS template_id, pt.name AS template_name
    FROM pm_schedules ps
    LEFT  JOIN hardware_assets h ON h.id = ps.asset_id
    LEFT  JOIN mobile_assets   m ON m.id = ps.mobile_id
    LEFT  JOIN network_assets  n ON n.id = ps.network_id
    LEFT  JOIN sites s           ON s.id = COALESCE(h.site_id, m.site_id, n.site_id)
    INNER JOIN pm_templates pt   ON pt.id = ps.template_id
    WHERE ps.id = :id
");
$sched->execute([':id' => $schedId]);
$schedule = $sched->fetch();
if (!$schedule) { http_response_code(404); exit('Schedule not found'); }

/* ── Load template items grouped by section ──────────────────── */
$itemStmt = $pdo->prepare("
    SELECT id, section, item_text, is_critical, frequency, sort_order
    FROM pm_template_items
    WHERE template_id = :tid
    ORDER BY sort_order ASC
");
$itemStmt->execute([':tid' => $schedule['template_id']]);
$allItems = $itemStmt->fetchAll();

/* group by section */
$sections = [];
foreach ($allItems as $item) {
    $sections[$item['section']][] = $item;
}

/* ── Frequency check — item นี้ต้องทำในรอบนี้ไหม ─────────────────
 * "ทุกรอบ"     → ทำทุกครั้งเสมอ
 * "Q เว้น Q"   → ทำเฉพาะไตรมาสคี่ (Q1, Q3) ของ schedule นี้
 * "รายปี"      → ทำเฉพาะ Q1 ของปี ถือเป็นรอบตัวแทนของปีนั้น
 * ค่าอื่นที่ไม่รู้จัก → ถือว่าต้องทำทุกรอบ (ปลอดภัยไว้ก่อน ไม่ auto-skip โดยไม่รู้ที่มา)
 *
 * ข้อจำกัดที่ควรทราบ: กติกานี้อิงจาก ps.quarter (คำนวณจาก planned_date ตอน generate)
 * สำหรับ template ที่ interval_days สั้นกว่า 90 วัน (เช่น Server รอบ 30 วัน) จะมีหลาย
 * schedule อยู่ใน quarter เดียวกัน ซึ่งทุก schedule ในไตรมาสนั้นจะได้ผลลัพธ์ due/not-due
 * เหมือนกันหมด — ถ้าต้องการความละเอียดกว่านี้ (นับตามจำนวนรอบจริงที่ทำไปแล้ว) แจ้งได้
 * จะปรับเป็นนับ occurrence แทนการอิง quarter
 */
function frequencyDueThisRound(array $item, array $schedule): bool
{
    $freq = trim((string)($item['frequency'] ?? 'ทุกรอบ'));
    $qtr  = (int)$schedule['quarter'];
    if ($freq === '' || $freq === 'ทุกรอบ') return true;
    if ($freq === 'Q เว้น Q') return in_array($qtr, [1, 3], true);
    if ($freq === 'รายปี')    return $qtr === 1;
    return true;
}

/* ── Load existing results (view mode หรือ re-edit) ──────────── */
$existingResults = [];
$existingLog = null;
if ($schedule['status'] === 'Done' || $viewOnly) {
    $logStmt = $pdo->prepare("
        SELECT ml.*, ml.id AS log_id
        FROM maintenance_logs ml
        WHERE ml.schedule_id = :sid
        ORDER BY ml.id DESC LIMIT 1
    ");
    $logStmt->execute([':sid' => $schedId]);
    $existingLog = $logStmt->fetch();

    if ($existingLog) {
        $resStmt = $pdo->prepare("
            SELECT template_item_id, result, remark, is_auto_na
            FROM pm_check_results
            WHERE maintenance_log_id = :lid
        ");
        $resStmt->execute([':lid' => $existingLog['log_id']]);
        foreach ($resStmt->fetchAll() as $r) {
            $existingResults[$r['template_item_id']] = $r;
        }
    }
}

$errors = [];
$flash  = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ── POST: Save PM results ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$viewOnly) {
    csrf_verify();

    $pmDate      = trim($_POST['pm_date']      ?? '');
    $performedBy = trim($_POST['performed_by'] ?? '');
    $signedTech  = trim($_POST['signed_by_tech']?? '');
    $signedUser  = trim($_POST['signed_by_user']?? '');
    $signedDate  = trim($_POST['signed_date']  ?? '');
    $cost        = trim($_POST['cost']         ?? '0');
    $description = trim($_POST['description']  ?? '');

    /* validation */
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $pmDate))
        $errors['pm_date'] = 'กรุณาระบุวันที่';
    if ($performedBy === '')
        $errors['performed_by'] = 'กรุณาระบุผู้ปฏิบัติงาน';
    if (!is_numeric($cost))
        $errors['cost'] = 'ค่าใช้จ่ายต้องเป็นตัวเลข';

    /* ตรวจว่า critical items ที่ "ตรงรอบนี้" ทุกตัวได้รับการ tick ผ่านแล้ว
     * (critical item ที่ frequency ไม่ตรงรอบ เช่น รายปี/Q เว้น Q ไม่บังคับ เพราะไม่ต้องทำรอบนี้อยู่แล้ว) */
    $criticalFail = [];
    foreach ($allItems as $item) {
        if ($item['is_critical'] && frequencyDueThisRound($item, $schedule)) {
            $result = $_POST['result_' . $item['id']] ?? '';
            if ($result === '' || $result === 'Fail') {
                $criticalFail[] = $item['item_text'];
            }
        }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            /* 1. สร้าง maintenance_log — ใส่ค่าตาม source_type (hardware/mobile/network) */
            $logStmt = $pdo->prepare("
                INSERT INTO maintenance_logs
                    (asset_id, mobile_id, network_id, schedule_id, pm_date, type,
                     description, performed_by, cost,
                     signed_by_tech, signed_by_user, signed_date)
                VALUES
                    (:asset_id, :mobile_id, :network_id, :schedule_id, :pm_date, 'PM',
                     :description, :performed_by, :cost,
                     :signed_by_tech, :signed_by_user, :signed_date)
            ");
            $logStmt->execute([
                ':asset_id'       => $schedule['source_type'] === 'hardware' ? $schedule['asset_id']   : null,
                ':mobile_id'      => $schedule['source_type'] === 'mobile'   ? $schedule['mobile_id']  : null,
                ':network_id'     => $schedule['source_type'] === 'network'  ? $schedule['network_id'] : null,
                ':schedule_id'    => $schedId,
                ':pm_date'        => $pmDate,
                ':description'    => $description ?: null,
                ':performed_by'   => $performedBy,
                ':cost'           => (float)$cost,
                ':signed_by_tech' => $signedTech ?: null,
                ':signed_by_user' => $signedUser ?: null,
                ':signed_date'    => $signedDate ?: null,
            ]);
            $logId = (int)$pdo->lastInsertId();

            /* 2. บันทึก pm_check_results ทุกข้อ พร้อม is_auto_na */
            $resStmt = $pdo->prepare("
                INSERT INTO pm_check_results
                    (maintenance_log_id, template_item_id, result, remark, is_auto_na)
                VALUES
                    (:log_id, :item_id, :result, :remark, :auto_na)
            ");
            foreach ($allItems as $item) {
                $result = $_POST['result_' . $item['id']] ?? 'NA';
                if (!in_array($result, ['Pass','Fail','NA'], true)) $result = 'NA';
                $remark = trim($_POST['remark_' . $item['id']] ?? '');
                $autoNa = frequencyDueThisRound($item, $schedule) ? 0 : 1;
                $resStmt->execute([
                    ':log_id'  => $logId,
                    ':item_id' => $item['id'],
                    ':result'  => $result,
                    ':remark'  => $remark ?: null,
                    ':auto_na' => $autoNa,
                ]);
            }

            /* 3. อัปเดต schedule status */
            $newStatus = empty($criticalFail) ? 'Done' : 'InProgress';
            $pdo->prepare("
                UPDATE pm_schedules SET status = :status WHERE id = :id
            ")->execute([':status' => $newStatus, ':id' => $schedId]);

            $pdo->commit();

            $msg = $newStatus === 'Done'
                ? "บันทึก PM เรียบร้อย — Schedule Q{$schedule['quarter']}/{$schedule['year']} เสร็จสมบูรณ์ "
                  . "(รอบถัดไปจะคำนวณให้อัตโนมัติตอนกด Generate/Refresh Plan ครั้งถัดไป)"
                : "บันทึก PM แล้ว แต่มี Critical item ที่ยังไม่ผ่าน (" . count($criticalFail) . " รายการ) — Status: InProgress";

            $_SESSION['flash'] = ['type' => $newStatus === 'Done' ? 'success' : 'warning', 'message' => $msg];
            header("Location: /it-asset-manager/maintenance/pm_schedule.php");
            exit;

        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[PM EXECUTE FAIL] ' . $e->getMessage());
            $errors['_general'] = 'บันทึกไม่สำเร็จ: ' . $e->getMessage();
        }
    }
}

/* ── Quarter label ───────────────────────────────────────────── */
$qtrLabels = [1=>'Q1 ม.ค.–มี.ค.', 2=>'Q2 เม.ย.–มิ.ย.',
              3=>'Q3 ก.ค.–ก.ย.',  4=>'Q4 ต.ค.–ธ.ค.'];

$page_title  = ($viewOnly ? 'ผล PM · ' : 'ทำ PM · ') . $schedule['asset_code'];
$active_menu = 'maintenance';
require __DIR__ . '/../../includes/header.php';
?>

<!-- ── Breadcrumb ────────────────────────────────────────────── -->
<div class="mb-3">
    <a href="/it-asset-manager/maintenance/pm_schedule.php"
       class="text-decoration-none small text-muted">
        <i class="bi bi-arrow-left"></i> กลับ PM Schedule
    </a>
    <h2 class="h5 mb-0 mt-1">
        <?= $viewOnly ? 'ผล PM' : 'ทำ PM' ?> —
        <?= e($schedule['asset_code']) ?>
        <span class="badge text-bg-secondary ms-1">
            Q<?= $schedule['quarter'] ?> / <?= $schedule['year'] ?>
        </span>
    </h2>
</div>

<?php if (!empty($errors['_general'])): ?>
    <div class="alert alert-danger"><?= e($errors['_general']) ?></div>
<?php endif; ?>

<!-- ── Asset info card ───────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body py-3">
        <div class="row g-2 small">
            <div class="col-md-3">
                <span class="text-muted">Asset ID:</span>
                <strong class="ms-1"><?= e($schedule['asset_code']) ?></strong>
            </div>
            <div class="col-md-3">
                <span class="text-muted">อุปกรณ์:</span>
                <span class="ms-1"><?= e($schedule['brand']) ?> <?= e($schedule['model']) ?></span>
            </div>
            <div class="col-md-2">
                <span class="text-muted">หน่วยงาน:</span>
                <span class="ms-1"><?= e($schedule['department'] ?? '—') ?></span>
            </div>
            <div class="col-md-2">
                <span class="text-muted">Site:</span>
                <span class="ms-1"><?= e($schedule['site_name'] ?? '—') ?></span>
            </div>
            <?php if ($schedule['source_type'] === 'network'): ?>
            <div class="col-md-3">
                <span class="text-muted">Hostname / IP:</span>
                <span class="ms-1"><?= e($schedule['hostname'] ?? '—') ?> / <?= e($schedule['ip_mgmt'] ?? '—') ?></span>
            </div>
            <?php else: ?>
            <div class="col-md-3">
                <span class="text-muted">ผู้ใช้:</span>
                <span class="ms-1"><?= e($schedule['user_display'] ?? '—') ?></span>
            </div>
            <?php endif; ?>
            <div class="col-md-3">
                <span class="text-muted">Template:</span>
                <span class="ms-1"><?= e($schedule['template_name']) ?></span>
            </div>
            <div class="col-md-3">
                <span class="text-muted">ไตรมาส:</span>
                <span class="ms-1"><?= e($qtrLabels[$schedule['quarter']]) ?></span>
            </div>
            <div class="col-md-3">
                <span class="text-muted">S/N:</span>
                <span class="ms-1 font-monospace"><?= e($schedule['serial_number'] ?? '—') ?></span>
            </div>
            <div class="col-md-3">
                <span class="text-muted">Status:</span>
                <?php
                $stBadge = match($schedule['status']) {
                    'Done'       => 'text-bg-success',
                    'InProgress' => 'text-bg-warning',
                    'Skipped'    => 'text-bg-secondary',
                    default      => 'text-bg-light border',
                };
                ?>
                <span class="badge <?= $stBadge ?> ms-1"><?= e($schedule['status']) ?></span>
            </div>
        </div>
    </div>
</div>

<form method="post" novalidate>
<?php csrf_field(); ?>

<!-- ── PM Meta ───────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white"><strong>ข้อมูลการ PM</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">วันที่ PM <span class="text-danger">*</span></label>
                <input type="date" name="pm_date"
                       value="<?= e($existingLog['pm_date'] ?? date('Y-m-d')) ?>"
                       class="form-control <?= isset($errors['pm_date']) ? 'is-invalid' : '' ?>"
                       <?= $viewOnly ? 'disabled' : 'required' ?>>
                <?php if (isset($errors['pm_date'])): ?>
                    <div class="invalid-feedback"><?= e($errors['pm_date']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <label class="form-label">ผู้ปฏิบัติงาน <span class="text-danger">*</span></label>
                <input type="text" name="performed_by" id="performed_by_input" maxlength="100"
                       value="<?= e($existingLog['performed_by'] ?? $currentUser['username'] ?? '') ?>"
                       class="form-control <?= isset($errors['performed_by']) ? 'is-invalid' : '' ?>"
                       placeholder="ชื่อช่าง IT — จะคัดลอกไปช่องลายเซ็นด้านล่างให้อัตโนมัติ"
                       <?= $viewOnly ? 'disabled' : '' ?>>
                <?php if (isset($errors['performed_by'])): ?>
                    <div class="invalid-feedback"><?= e($errors['performed_by']) ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label">ค่าใช้จ่าย (THB)</label>
                <input type="number" name="cost" min="0" step="0.01"
                       value="<?= e($existingLog['cost'] ?? '0') ?>"
                       class="form-control"
                       <?= $viewOnly ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-4">
                <label class="form-label">หมายเหตุ / สรุปงาน</label>
                <input type="text" name="description" maxlength="500"
                       value="<?= e($existingLog['description'] ?? '') ?>"
                       class="form-control" placeholder="สรุปงานที่ทำ อะไหล่ที่เปลี่ยน"
                       <?= $viewOnly ? 'disabled' : '' ?>>
            </div>
        </div>
    </div>
</div>

<!-- ── Legend ────────────────────────────────────────────────── -->
<?php if (!$viewOnly): ?>
<div class="d-flex gap-3 mb-2 small text-muted align-items-center flex-wrap">
    <span><span class="badge text-bg-success">Pass</span> ผ่าน</span>
    <span><span class="badge text-bg-danger">Fail</span> ไม่ผ่าน</span>
    <span><span class="badge text-bg-secondary">N/A</span> ไม่เกี่ยวข้อง</span>
    <span><span class="badge text-bg-warning text-dark">⚠ Critical</span> ต้องผ่านก่อนปิด PM</span>
    <span><span class="badge text-bg-light border text-muted"><i class="bi bi-skip-forward"></i> ไม่ตรงรอบ</span> ระบบ pre-select N/A ให้ (แก้เองได้)</span>
</div>
<?php endif; ?>

<!-- ── Checklist sections ─────────────────────────────────────── -->
<?php foreach ($sections as $sectionName => $items): ?>
<div class="card mb-3">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <i class="bi bi-clipboard2-check text-primary"></i>
        <strong><?= e($sectionName) ?></strong>
        <span class="ms-auto text-muted small">
            <?php
            $secPass = 0; $secTotal = count($items);
            foreach ($items as $item) {
                $r = $existingResults[$item['id']]['result'] ?? '';
                if ($r === 'Pass') $secPass++;
            }
            echo $viewOnly ? "{$secPass}/{$secTotal} ผ่าน" : "{$secTotal} รายการ";
            ?>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table align-middle mb-0" style="font-size:13px;">
            <thead class="table-light">
                <tr>
                    <th style="width:50px;">#</th>
                    <th>รายการตรวจสอบ</th>
                    <th style="width:90px;">ความถี่</th>
                    <th style="width:220px;">ผล</th>
                    <th style="width:200px;">หมายเหตุ</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($items as $idx => $item):
                $existRes     = $existingResults[$item['id']] ?? [];
                $savedResult  = $existRes['result'] ?? '';
                $savedRemark  = $existRes['remark'] ?? '';
                $savedAutoNa  = (int)($existRes['is_auto_na'] ?? 0);
                $dueThisRound = frequencyDueThisRound($item, $schedule);
                $defaultResult = $dueThisRound ? '' : 'NA';
                $postResult  = $_POST['result_' . $item['id']] ?? ($savedResult !== '' ? $savedResult : $defaultResult);
                $postRemark  = $_POST['remark_'  . $item['id']] ?? $savedRemark;
            ?>
                <tr class="<?= $postResult === 'Fail' ? 'table-danger' : '' ?>">
                    <td class="text-muted"><?= $idx + 1 ?></td>
                    <td>
                        <?= e($item['item_text']) ?>
                        <?php if ($item['is_critical']): ?>
                            <span class="badge text-bg-warning text-dark ms-1" style="font-size:10px;">
                                ⚠ Critical
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge text-bg-light border text-muted" style="font-size:10px;">
                            <?= e($item['frequency']) ?>
                        </span>
                        <?php if (!$dueThisRound): ?>
                            <div class="text-muted mt-1" style="font-size:10px;"
                                 title="ไม่ตรงรอบตามความถี่ที่ตั้งไว้ — pre-select N/A ให้ แก้ไขเองได้ถ้าจำเป็น">
                                <i class="bi bi-skip-forward"></i> ไม่ตรงรอบ
                            </div>
                        <?php elseif ($viewOnly && $savedAutoNa): ?>
                            <div class="text-muted mt-1" style="font-size:10px;">
                                <i class="bi bi-robot"></i> ระบบข้ามให้
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($viewOnly): ?>
                            <?php
                            $rb = match($savedResult) {
                                'Pass' => 'text-bg-success',
                                'Fail' => 'text-bg-danger',
                                'NA'   => 'text-bg-secondary',
                                default => 'text-bg-light border',
                            };
                            ?>
                            <span class="badge <?= $rb ?>"><?= $savedResult ?: '—' ?></span>
                        <?php else: ?>
                            <div class="btn-group btn-group-sm" role="group">
                                <?php foreach (['Pass'=>'success','Fail'=>'danger','NA'=>'secondary'] as $val => $color): ?>
                                    <input type="radio" class="btn-check"
                                           name="result_<?= $item['id'] ?>"
                                           id="r_<?= $item['id'] ?>_<?= $val ?>"
                                           value="<?= $val ?>"
                                           <?= $postResult === $val ? 'checked' : '' ?>>
                                    <label class="btn btn-outline-<?= $color ?>"
                                           for="r_<?= $item['id'] ?>_<?= $val ?>">
                                        <?= $val ?>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($viewOnly): ?>
                            <span class="text-muted small"><?= e($savedRemark ?: '—') ?></span>
                        <?php else: ?>
                            <input type="text" name="remark_<?= $item['id'] ?>"
                                   value="<?= e($postRemark) ?>"
                                   class="form-control form-control-sm"
                                   placeholder="หมายเหตุ">
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<!-- ── Signature ─────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-header bg-white"><strong>ลายเซ็น</strong></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-4">
                <label class="form-label">ผู้ปฏิบัติงาน (IT)</label>
                <input type="text" name="signed_by_tech" id="signed_by_tech_input"
                       value="<?= e($existingLog['signed_by_tech'] ?? '') ?>"
                       class="form-control" placeholder="คัดลอกจากช่อง &quot;ผู้ปฏิบัติงาน&quot; ด้านบนอัตโนมัติ"
                       <?= $viewOnly ? 'disabled' : '' ?>>
                <?php if (!$viewOnly): ?>
                    <div class="form-text">พิมพ์เองที่นี่ได้ถ้าผู้เซ็นเป็นคนละคนกับผู้ปฏิบัติงาน (เช่น หัวหน้างานเซ็นแทน)</div>
                <?php endif; ?>
            </div>
            <div class="col-md-4">
                <label class="form-label">ผู้ใช้งาน</label>
                <input type="text" name="signed_by_user"
                       value="<?= e($existingLog['signed_by_user'] ?? $schedule['user_display'] ?? '') ?>"
                       class="form-control"
                       <?= $viewOnly ? 'disabled' : '' ?>>
            </div>
            <div class="col-md-4">
                <label class="form-label">วันที่เซ็น</label>
                <input type="date" name="signed_date"
                       value="<?= e($existingLog['signed_date'] ?? date('Y-m-d')) ?>"
                       class="form-control"
                       <?= $viewOnly ? 'disabled' : '' ?>>
            </div>
        </div>
    </div>
</div>

<!-- ── Actions ───────────────────────────────────────────────── -->
<div class="d-flex justify-content-between align-items-center mb-5">
    <a href="/it-asset-manager/maintenance/pm_schedule.php"
       class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left"></i> กลับ
    </a>
    <?php if (!$viewOnly): ?>
        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg"></i> บันทึก PM
            </button>
        </div>
    <?php endif; ?>
</div>

</form>

<?php if (!$viewOnly): ?>
<script>
/* Auto-copy "ผู้ปฏิบัติงาน" → "ผู้ปฏิบัติงาน (IT)" ในช่องลายเซ็น
 * เพื่อไม่ให้ต้องพิมพ์ชื่อช่างซ้ำ 2 รอบ — ถ้าช่างพิมพ์เองในช่องหลัง (คนละคนกัน เช่น หัวหน้าเซ็นแทน)
 * ให้หยุด auto-sync ทันที ไม่ทับสิ่งที่พิมพ์เอง */
(function () {
    var src = document.getElementById('performed_by_input');
    var dst = document.getElementById('signed_by_tech_input');
    if (!src || !dst) return;

    var userEditedDst = dst.value.trim() !== '';

    /* sync ครั้งแรกตอนโหลดหน้า เผื่อ performed_by ถูก pre-fill จาก session มาแล้ว */
    if (!userEditedDst && src.value.trim() !== '') {
        dst.value = src.value;
    }

    dst.addEventListener('input', function () {
        userEditedDst = dst.value.trim() !== src.value.trim();
    });

    src.addEventListener('input', function () {
        if (!userEditedDst) dst.value = src.value;
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
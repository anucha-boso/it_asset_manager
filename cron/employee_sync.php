#!/usr/bin/env php
<?php
/**
 * =============================================================================
 *  Employee Sync — ดึงข้อมูลจาก WebTime API (GetEmployeePCS.aspx)
 *  /var/www/lab/it-asset-manager/cron/employee_sync.php
 * -----------------------------------------------------------------------------
 *  Step 4 (upsert): เฉพาะ EmployeeType = 'รายเดือน (Month)' AND ResignStatus = 'Active'
 *  Step 5 (mark resigned): พนักงานที่ "มีอยู่ใน DB แล้ว" แต่ API ส่ง ResignStatus ≠ 'Active'
 *          → UPDATE เฉพาะ resign_status / last_date / synced_at (ไม่ INSERT คนลาออกใหม่)
 *          → ถ้า PersonID เดียวกันมี record ไหนเป็น Active อยู่ (เช่นกลับเข้าทำงานใหม่) จะไม่ mark
 *  Target: cc_central_employee_db.employees (cross-database, same MySQL instance)
 *  Run manual:  php /var/www/lab/it-asset-manager/cron/employee_sync.php
 * =============================================================================
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden: CLI only');
}

require_once __DIR__ . '/../config/db_connect.php';
$pdo = db(); // เชื่อมกับ it_asset_mgmt แต่ query ข้าม database ไปที่ cc_central_employee_db ได้ (same instance)

// -----------------------------------------------------------------------------
// 1) อ่าน credential จาก environment
// -----------------------------------------------------------------------------
$apiUrl  = getenv('WEBTIME_API_URL')  ?: '';
$apiUser = getenv('WEBTIME_API_USER') ?: '';
$apiPass = getenv('WEBTIME_API_PASS') ?: '';

if ($apiUrl === '' || $apiUser === '' || $apiPass === '') {
    fwrite(STDERR, "[FATAL] Missing WEBTIME_API_URL / WEBTIME_API_USER / WEBTIME_API_PASS\n");
    log_sync_result($pdo, 0, 0, 0, 'Failed', 'Missing environment variables');
    exit(1);
}

// -----------------------------------------------------------------------------
// 2) เรียก API ด้วย cURL (Basic Auth) — body ไม่มีผลต่อผลลัพธ์ แต่ endpoint ต้องการ body ไม่ว่าง
// -----------------------------------------------------------------------------
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => '1',
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERPWD        => $apiUser . ':' . $apiPass,
    CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 60,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false || $curlErr !== '') {
    $msg = "cURL error: {$curlErr}";
    fwrite(STDERR, "[FATAL] {$msg}\n");
    log_sync_result($pdo, 0, 0, 0, 'Failed', $msg);
    exit(1);
}
if ($httpCode !== 200) {
    $msg = "HTTP {$httpCode}: " . substr($response, 0, 300);
    fwrite(STDERR, "[FATAL] {$msg}\n");
    log_sync_result($pdo, 0, 0, 0, 'Failed', $msg);
    exit(1);
}

$data = json_decode($response, true);
if (!is_array($data) || ($data['status'] ?? '') !== 'true' || !isset($data['detail']) || !is_array($data['detail'])) {
    $msg = 'Unexpected API response shape: ' . substr($response, 0, 300);
    fwrite(STDERR, "[FATAL] {$msg}\n");
    log_sync_result($pdo, 0, 0, 0, 'Failed', $msg);
    exit(1);
}

$allRecords   = $data['detail'];
$totalFetched = count($allRecords);

// -----------------------------------------------------------------------------
// 3) Filter: รายเดือน + ยังทำงานอยู่ เท่านั้น
// -----------------------------------------------------------------------------
$filtered = array_values(array_filter($allRecords, function ($r) {
    return ($r['EmployeeType'] ?? '') === 'รายเดือน (Month)'
        && ($r['ResignStatus'] ?? '') === 'Active';
}));

echo "[INFO] Fetched {$totalFetched} total, filtered to " . count($filtered) . " active monthly employees\n";

// -----------------------------------------------------------------------------
// helper: ทำความสะอาดค่าจาก API ก่อนเข้า DB
// -----------------------------------------------------------------------------
function clean_date(?string $v): ?string {
    if ($v === null || $v === '' || $v === '-') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) return null;
    if (substr($v, 0, 4) === '0001') return null; // .NET DateTime.MinValue
    return substr($v, 0, 10);
}
function clean_str($v): ?string {
    if ($v === null) return null;
    $v = trim((string)$v);
    return ($v === '' || $v === '-') ? null : $v;
}

// -----------------------------------------------------------------------------
// 4) Upsert แบบ batch (chunk ละ 300 record ต่อ transaction)
// -----------------------------------------------------------------------------
$upsertSql = "
    INSERT INTO cc_central_employee_db.employees
        (person_id, person_code, person_card_id, gender, title, first_name, last_name,
         company, department_id_ext, department, section, position_type, work_location,
         scope_work, company_group, position, employee_type, end_date_api, start_date,
         probation_pass_date, last_date, probation_period_days, resign_status, degree,
         age, chk_delete_person, religion_name, citizen_name, nationality_name, site_id, synced_at)
    VALUES
        (:person_id, :person_code, :person_card_id, :gender, :title, :first_name, :last_name,
         :company, :department_id_ext, :department, :section, :position_type, :work_location,
         :scope_work, :company_group, :position, :employee_type, :end_date_api, :start_date,
         :probation_pass_date, :last_date, :probation_period_days, :resign_status, :degree,
         :age, :chk_delete_person, :religion_name, :citizen_name, :nationality_name, :site_id, NOW())
    ON DUPLICATE KEY UPDATE
        person_code = VALUES(person_code), person_card_id = VALUES(person_card_id),
        gender = VALUES(gender), title = VALUES(title),
        first_name = VALUES(first_name), last_name = VALUES(last_name),
        company = VALUES(company), department_id_ext = VALUES(department_id_ext),
        department = VALUES(department), section = VALUES(section),
        position_type = VALUES(position_type), work_location = VALUES(work_location),
        scope_work = VALUES(scope_work), company_group = VALUES(company_group),
        position = VALUES(position), employee_type = VALUES(employee_type),
        end_date_api = VALUES(end_date_api), start_date = VALUES(start_date),
        probation_pass_date = VALUES(probation_pass_date), last_date = VALUES(last_date),
        probation_period_days = VALUES(probation_period_days), resign_status = VALUES(resign_status),
        degree = VALUES(degree), age = VALUES(age),
        chk_delete_person = VALUES(chk_delete_person), religion_name = VALUES(religion_name),
        citizen_name = VALUES(citizen_name), nationality_name = VALUES(nationality_name),site_id = VALUES(site_id),
        synced_at = NOW()
";
$stmt = $pdo->prepare($upsertSql);

$inserted = 0;
$updated  = 0;
$chunks   = array_chunk($filtered, 300);

// -----------------------------------------------------------------------------
// เตรียม site mapping: company code -> site_id
//  จับคู่โดยตัดข้อความหลัง '|' ออกจาก site_name ก่อนเทียบ
//  เช่น "PLP | BPD" -> "PLP"
// -----------------------------------------------------------------------------
$siteRows = $pdo->query("SELECT id, site_name FROM it_asset_mgmt.sites")->fetchAll();
$siteMap = [];       // ['PCS' => 2, 'PACA' => 4, 'PLP' => 7, ...]
foreach ($siteRows as $s) {
    $prefix = trim(explode('|', $s['site_name'])[0]);
    $siteMap[$prefix] = (int)$s['id'];
}

$unmatchedCompanies = [];  // เก็บ company code ที่ map ไม่เจอ ไว้ warn ตอนจบ

foreach ($chunks as $chunk) {
    $pdo->beginTransaction();
    try {
        foreach ($chunk as $r) {
            $companyCode = clean_str($r['Company'] ?? null);
            $siteId = null;
            if ($companyCode !== null) {
                if (isset($siteMap[$companyCode])) {
                    $siteId = $siteMap[$companyCode];
                } else {
                    $unmatchedCompanies[$companyCode] = true;
                }
            }
            $stmt->execute([
                ':person_id'             => (string)($r['PersonID'] ?? ''),
                ':person_code'           => clean_str($r['PersonCode'] ?? null),
                ':person_card_id'        => clean_str($r['PersonCardID'] ?? null),
                ':gender'                => clean_str($r['Gender'] ?? null),
                ':title'                 => clean_str($r['Title'] ?? null),
                ':first_name'            => (string)($r['FirstName'] ?? ''),
                ':last_name'             => (string)($r['LastName'] ?? ''),
                ':company'               => clean_str($r['Company'] ?? null),
                ':department_id_ext'     => isset($r['DepartmentID']) ? (int)$r['DepartmentID'] : null,
                ':department'            => clean_str($r['Department'] ?? null),
                ':section'               => clean_str($r['Section'] ?? null),
                ':position_type'         => clean_str($r['Position_Type'] ?? null),
                ':work_location'         => clean_str($r['Work_Location'] ?? null),
                ':scope_work'            => clean_str($r['Scope_Work'] ?? null),
                ':company_group'         => clean_str($r['Company_Group'] ?? null),
                ':position'              => clean_str($r['Position'] ?? null),
                ':employee_type'         => clean_str($r['EmployeeType'] ?? null),
                ':end_date_api'          => clean_date($r['EndDate_API'] ?? null),
                ':start_date'            => clean_date($r['StartDate'] ?? null),
                ':probation_pass_date'   => clean_date($r['ProbationPassDate'] ?? null),
                ':last_date'             => clean_date($r['LastDate'] ?? null),
                ':probation_period_days' => isset($r['ProbationPeriodDays']) ? (int)$r['ProbationPeriodDays'] : null,
                ':resign_status'         => clean_str($r['ResignStatus'] ?? null),
                ':degree'                => clean_str($r['Degree'] ?? null),
                ':age'                   => isset($r['Age']) ? (int)$r['Age'] : null,
                ':chk_delete_person'     => isset($r['ChkDeletePerson']) ? (int)$r['ChkDeletePerson'] : null,
                ':religion_name'         => clean_str($r['ReligionName'] ?? null),
                ':citizen_name'          => clean_str($r['CitizenName'] ?? null),
                ':nationality_name'      => clean_str($r['NationalityName'] ?? null),
                ':site_id'               => $siteId,

            ]);
            // MySQL convention สำหรับ ON DUPLICATE KEY UPDATE: rowCount() 1=insert, 2=update จริง, 0=update แต่ค่าไม่เปลี่ยน
            if ($stmt->rowCount() === 1) $inserted++;
            elseif ($stmt->rowCount() === 2) $updated++;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $msg = $e->getMessage();
        fwrite(STDERR, "[ERROR] Chunk failed: {$msg}\n");
        log_sync_result($pdo, $totalFetched, $inserted, $updated, 'Failed', $msg);
        exit(1);
    }
}

// -----------------------------------------------------------------------------
// 5) Mark ลาออก — แก้ปัญหา resign_status ค้างเป็น 'Active' ตลอดไป
//    (เดิม record ของคนลาออกถูก filter ทิ้งในขั้น 3 จึงไม่เคยถูก UPDATE)
//    ใช้ ResignStatus จาก HR ตรงๆ ไม่อนุมานจากการที่ record หายไปจาก API
// -----------------------------------------------------------------------------

// 5.1 PersonID ที่ยัง Active อยู่ใน API (ทุก EmployeeType) — ตัวนี้ชนะเสมอ
$activePersonIds = [];
$statusBreakdown = [];   // สรุปจำนวนต่อ ResignStatus ไว้ echo ลง log
foreach ($allRecords as $r) {
    $st = clean_str($r['ResignStatus'] ?? null) ?? '(null)';
    $statusBreakdown[$st] = ($statusBreakdown[$st] ?? 0) + 1;
    if ($st === 'Active') {
        $activePersonIds[(string)($r['PersonID'] ?? '')] = true;
    }
}
arsort($statusBreakdown);
$parts = [];
foreach ($statusBreakdown as $st => $n) $parts[] = "{$st}={$n}";
echo "[INFO] ResignStatus breakdown: " . implode(', ', $parts) . "\n";

// 5.2 ผู้สมัครที่จะ mark: ไม่ใช่ Active, ไม่มี record Active ซ้อน, ResignStatus ไม่ว่าง
//     PersonID ซ้ำหลาย record → เก็บอันที่ LastDate ล่าสุด
$resignCandidates = [];
foreach ($allRecords as $r) {
    $pid = (string)($r['PersonID'] ?? '');
    $st  = clean_str($r['ResignStatus'] ?? null);
    if ($pid === '' || $st === null || $st === 'Active' || isset($activePersonIds[$pid])) {
        continue;
    }
    $ld = clean_date($r['LastDate'] ?? null);
    if (!isset($resignCandidates[$pid]) || ($ld ?? '') > ($resignCandidates[$pid]['last_date'] ?? '')) {
        $resignCandidates[$pid] = ['status' => $st, 'last_date' => $ld];
    }
}

// 5.3 จำกัดเฉพาะคนที่มีอยู่ใน DB แล้ว (หลักร้อย) — ไม่ยิง UPDATE หลายพันครั้งต่อคืน
$existingIds = $pdo->query("SELECT person_id FROM cc_central_employee_db.employees")
                   ->fetchAll(PDO::FETCH_COLUMN);
$toMark = array_intersect_key($resignCandidates, array_flip(array_map('strval', $existingIds)));

// 5.4 UPDATE เฉพาะแถวที่ค่าเปลี่ยนจริง (<=> คือ NULL-safe equal)
//     placeholder ห้ามซ้ำชื่อ (ATTR_EMULATE_PREPARES = false)
$markStmt = $pdo->prepare("
    UPDATE cc_central_employee_db.employees
       SET resign_status = :st, last_date = :ld, synced_at = NOW()
     WHERE person_id = :pid
       AND (NOT (resign_status <=> :st2) OR NOT (last_date <=> :ld2))
");

$markedResigned = 0;
if ($toMark) {
    $pdo->beginTransaction();
    try {
        foreach ($toMark as $pid => $c) {
            $markStmt->execute([
                ':st'  => $c['status'], ':ld'  => $c['last_date'], ':pid' => (string)$pid,
                ':st2' => $c['status'], ':ld2' => $c['last_date'],
            ]);
            $markedResigned += $markStmt->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $msg = 'Mark resigned failed: ' . $e->getMessage();
        fwrite(STDERR, "[ERROR] {$msg}\n");
        log_sync_result($pdo, $totalFetched, $inserted, $updated, 'Failed', $msg);
        exit(1);
    }
}
echo "[INFO] Resigned in DB (matched): " . count($toMark) . ", newly marked/changed this run: {$markedResigned}\n";

echo "[DONE] Inserted: {$inserted}, Updated: {$updated}\n";
if ($unmatchedCompanies) {
    $list = implode(', ', array_keys($unmatchedCompanies));
    echo "[WARN] Company codes not found in sites table: {$list}\n";
}
log_sync_result($pdo, $totalFetched, $inserted, $updated, 'Success', null);
exit(0);

function log_sync_result(PDO $pdo, int $fetched, int $inserted, int $updated, string $status, ?string $error): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO cc_central_employee_db.employee_sync_log
                (total_fetched, total_inserted, total_updated, status, error_message)
            VALUES (:f, :i, :u, :s, :e)
        ");
        $stmt->execute([':f'=>$fetched, ':i'=>$inserted, ':u'=>$updated, ':s'=>$status, ':e'=>$error]);
    } catch (Throwable $e) {
        fwrite(STDERR, "[WARN] Failed to write sync log: " . $e->getMessage() . "\n");
    }
}
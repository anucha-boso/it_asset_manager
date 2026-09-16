<?php
/**
 * Employee Transaction Log — shared write helper
 * includes/employee_log.php
 *
 * เขียน log ทุกครั้งที่มีการ assign/revoke อะไรก็ตามให้พนักงานคนหนึ่ง
 * (asset, software license, ในอนาคตจะขยายไปถึง access request)
 *
 * กติกาสำคัญ: เรียกฟังก์ชันนี้ได้เฉพาะตอนที่รู้ employee_id ที่เป็นพนักงาน
 * ภายในจริงเท่านั้น (ไม่ใช่ NULL หรือ external) — ผู้เรียกมีหน้าที่เช็คเงื่อนไข
 * นี้เองก่อนเรียก ฟังก์ชันนี้ไม่เช็คซ้ำ
 *
 * การเขียน log ต้อง "ไม่ทำให้ transaction หลักพัง" แม้ log จะเขียนไม่สำเร็จ
 * (เช่น ตาราง log มีปัญหาชั่วคราว) — จึงครอบ try/catch ไว้ในฟังก์ชันนี้เอง
 * และ error_log() ไว้เฉยๆ แทนที่จะ throw ต่อ
 */
declare(strict_types=1);

/**
 * @param PDO         $pdo            การเชื่อมต่อ it_asset_mgmt (employee_transaction_log อยู่ DB นี้)
 * @param int         $employeeId     พนักงานที่เกี่ยวข้องกับ transaction นี้
 * @param string      $txnType        ต้องตรงกับค่าใน ENUM ของตาราง
 * @param string|null $refTable       ชื่อตารางต้นทาง เช่น 'hardware_assets'
 * @param int|null    $refId          id ของ record ต้นทาง
 * @param string|null $detail         คำอธิบายสั้นๆ อ่านง่าย แสดงในหน้า History
 * @param string|null $performedByAd  username คนที่ทำรายการ (จาก session)
 */
function logEmployeeTransaction(
    PDO $pdo,
    int $employeeId,
    string $txnType,
    ?string $refTable,
    ?int $refId,
    ?string $detail,
    ?string $performedByAd
): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO employee_transaction_log
                (employee_id, txn_type, ref_table, ref_id, detail, performed_by_ad)
            VALUES
                (:employee_id, :txn_type, :ref_table, :ref_id, :detail, :performed_by_ad)
        ");
        $stmt->execute([
            ':employee_id'    => $employeeId,
            ':txn_type'       => $txnType,
            ':ref_table'      => $refTable,
            ':ref_id'         => $refId,
            ':detail'         => $detail,
            ':performed_by_ad'=> $performedByAd,
        ]);
    } catch (PDOException $e) {
        // ไม่ throw ต่อ — การ log ล้มเหลวต้องไม่ทำให้ transaction หลัก (เช่น การ
        // allocate license, ผูก asset) ที่เพิ่งสำเร็จไปแล้วกลายเป็นพังตามไปด้วย
        error_log('[EMPLOYEE LOG FAIL] ' . $e->getMessage());
    }
}
<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PUT, DELETE, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once "../db.php";
require_once "../functions.php";

$db    = new Database();
$conn  = $db->getConnection();
$value = new DataValues($conn);

$request_method = $_SERVER["REQUEST_METHOD"];

switch ($request_method) {
    case 'GET':    get_personal();    break;
    case 'POST':   add_personal();    break;
    case 'PUT':    update_personal(); break;
    case 'DELETE': delete_personal(); break;
    default:
        http_response_code(405);
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

// ═══════════════════════════════════════════════════════════════════
// GET — no lock gating. Client decides masking.
// ═══════════════════════════════════════════════════════════════════
function get_personal() {
    global $conn;

    try {
        $sql = "SELECT p.perID,
                       p.perCode,
                       p.perLock,
                       p.perName,
                       p.perLastName,
                       p.perGender,
                       p.perDoB,
                       p.perENIDNo,
                       p.perAddress,
                       p.perPhone,
                       p.perEmail,
                       p.perPhoto,
                       a.addID, a.addName, a.addCity, a.addProvince,
                       a.addCountry, a.addZipCode, a.addMailing
                FROM personal p
                JOIN address a ON a.addID = p.perAddress";

        if (isset($_GET['perID']) && !empty($_GET['perID'])) {
            $sql .= " WHERE p.perID = :id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $_GET['perID'], PDO::PARAM_INT);
        } elseif (isset($_GET['search']) && !empty($_GET['search'])) {
            $sql .= " WHERE p.perName     LIKE :s1
                        OR p.perLastName LIKE :s2
                        OR p.perENIDNo   LIKE :s3
                        OR p.perPhone    LIKE :s4
                        OR p.perCode     LIKE :s5
                        OR p.perEmail    LIKE :s6";
            $stmt = $conn->prepare($sql);
            $term = '%' . $_GET['search'] . '%';
            for ($i = 1; $i <= 6; $i++) {
                $stmt->bindValue(":s$i", $term, PDO::PARAM_STR);
            }
        } else {
            $stmt = $conn->prepare($sql);
        }

        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // ── Accounts (per stakeholder) ────────────────────────────
        $accStmt = $conn->prepare("
            SELECT
                ac.accNumber                                       AS accNumber,
                ac.accName                                         AS accName,
                ad.actCurrency                                     AS accCurrency,
                ad.actStatus                                       AS accStatus,
                CASE WHEN ad.actCreditLimit = 999999999999.9999
                     THEN 'Unlimited' ELSE ad.actCreditLimit END   AS accLimit,
                COALESCE(SUM(CASE WHEN td.trdDrCr = 'Cr'
                                  THEN td.trdAmount ELSE -td.trdAmount END), 0)
                                                                   AS accBalance
            FROM accountDetails ad
            JOIN accounts ac ON ac.accNumber = ad.actAccount
            LEFT JOIN trnDetails td ON td.trdAccount = ac.accNumber
            WHERE ad.actSignatory = :perID
            GROUP BY ac.accNumber, ac.accName, ad.actCurrency, ad.actStatus, ad.actCreditLimit
            ORDER BY ac.accNumber
        ");

        // ── Recent transactions (with running balance) ────────────
        $trnStmt = $conn->prepare("
            SELECT
                t.trnReference    AS reference,
                t.trnType         AS type,
                t.trnStateText    AS statusText,
                t.trnStatus       AS status,
                t.trnEntryDate    AS entryDate,
                td.trdAccount     AS account,
                ac.accName        AS accountName,
                td.trdCcy         AS currency,
                td.trdAmount      AS amount,
                td.trdDrCr        AS drCr,
                td.trdNarration   AS narration,
                (
                    SELECT COALESCE(SUM(
                        CASE WHEN inner_td.trdDrCr = 'Cr'
                             THEN inner_td.trdAmount
                             ELSE -inner_td.trdAmount
                        END
                    ), 0)
                    FROM trnDetails inner_td
                    JOIN transactions inner_t ON inner_t.trnReference = inner_td.trdReference
                    WHERE inner_td.trdAccount = td.trdAccount
                      AND (
                            inner_t.trnEntryDate < t.trnEntryDate
                            OR (
                                inner_t.trnEntryDate = t.trnEntryDate
                                AND inner_t.trnReference <= t.trnReference
                            )
                          )
                ) AS runningBalance
            FROM transactions t
            JOIN trnDetails td ON td.trdReference = t.trnReference
            LEFT JOIN accounts ac ON ac.accNumber = td.trdAccount
            WHERE td.trdAccount IN (
                SELECT actAccount FROM accountDetails WHERE actSignatory = :perID
            )
            ORDER BY t.trnEntryDate DESC, t.trnReference DESC
            LIMIT 10
        ");

        // ── Attach accounts + transactions + casts ────────────────
        foreach ($data as &$row) {
            $row['perID']      = (int) $row['perID'];
            $row['perLock']    = (int) $row['perLock'];
            $row['perAddress'] = $row['perAddress'] !== null
                ? (int) $row['perAddress'] : null;
            $row['addID']      = (int) $row['addID'];
            $row['addMailing'] = (int) $row['addMailing'];

            $accStmt->execute([':perID' => $row['perID']]);
            $row['accounts'] = $accStmt->fetchAll(PDO::FETCH_ASSOC);

            $trnStmt->execute([':perID' => $row['perID']]);
            $row['recentTransactions'] = $trnStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($row['accounts'] as &$acc) {
                $acc['accNumber']  = (int)   $acc['accNumber'];
                $acc['accStatus']  = (int)   $acc['accStatus'];
                $acc['accBalance'] = (float) $acc['accBalance'];
                if ($acc['accLimit'] !== 'Unlimited') {
                    $acc['accLimit'] = (float) $acc['accLimit'];
                }
            }
            unset($acc);

            foreach ($row['recentTransactions'] as &$trx) {
                $trx['account']        = (int)   $trx['account'];
                $trx['status']         = (int)   $trx['status'];
                $trx['amount']         = (float) $trx['amount'];
                $trx['runningBalance'] = (float) $trx['runningBalance'];
            }
            unset($trx);
        }
        unset($row);

        echo json_encode($data, JSON_PRETTY_PRINT);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["msg" => $e->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// POST
// ═══════════════════════════════════════════════════════════════════
function add_personal() {
    global $conn, $value;

    $data = json_decode(file_get_contents("php://input"), true) ?: [];

    $firstName = $data['first_name']  ?? '';
    $lastName  = $data['last_name']   ?? '';
    $gender    = $data['per_gender']  ?? null;
    $tazNo     = $data['per_nidno']   ?? null;
    $cellNo    = $data['cell_number'] ?? null;
    $email     = $data['email']       ?? null;
    $addName   = $data['add_name']    ?? null;
    $city      = $data['add_city']    ?? null;
    $province  = $data['add_province']?? null;
    $country   = $data['add_country'] ?? null;
    $zipCode   = $data['zip_code']    ?? null;
    $isMailing = isset($data['is_mailing']) ? (int)$data['is_mailing'] : 0;

    $perCodeRaw = $data['perCode'] ?? null;
    $perCode    = is_string($perCodeRaw) ? trim($perCodeRaw) : $perCodeRaw;
    if ($perCode === '') $perCode = null;

    $perLock = isset($data['perLock']) ? (int)$data['perLock'] : 0;
    $perLock = $perLock === 1 ? 1 : 0;

    $dob = $data['per_DoB'] ?? null;
    if ($dob === '') $dob = null;

    if ($perCode !== null && !preg_match('/^[A-Za-z0-9._-]{1,30}$/', $perCode)) {
        http_response_code(422);
        echo json_encode([
            "msg"     => "failed",
            "error"   => "invalid_code",
            "message" => "Stakeholder code must be 1–30 chars (A–Z, 0–9, . _ -).",
        ]);
        return;
    }

    $accCategory = 8;
    $newAcc      = $value->getNewAccount($accCategory);
    $localCcy    = $value->getLocalCurrency();
    $creditLimit = 0;
    $com         = 1;

    try {
        $conn->beginTransaction();

        $stmt1 = $conn->prepare(
            "INSERT INTO address
                (addName, addCity, addProvince, addCountry, addZipCode, addMailing)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt1->execute([$addName, $city, $province, $country, $zipCode, $isMailing]);
        $addID = $conn->lastInsertId();

        $stmt2 = $conn->prepare(
            "INSERT INTO personal
                (perCode, perLock, perName, perLastName, perGender,
                 perDoB, perENIDNo, perAddress, perPhone, perEmail)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt2->execute([
            $perCode, $perLock, $firstName, $lastName, $gender,
            $dob, $tazNo, $addID, $cellNo, $email,
        ]);
        $personalID = (int) $conn->lastInsertId();

        if ($perCode === null) {
            $stmtCode = $conn->prepare(
                "UPDATE personal SET perCode = :code WHERE perID = :id"
            );
            $stmtCode->execute([
                ':code' => (string) $personalID,
                ':id'   => $personalID,
            ]);
        }

        $stmt3 = $conn->prepare(
            "INSERT INTO accounts (accNumber, accName, accCategory)
             VALUES (?, ?, ?)"
        );
        $stmt3->execute([$newAcc, trim("$firstName $lastName"), $accCategory]);

        $stmt4 = $conn->prepare(
            "INSERT INTO accountDetails
                (actAccount, actCurrency, actCreditLimit, actSignatory, actCompany)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt4->execute([$newAcc, $localCcy, $creditLimit, $personalID, $com]);

        $conn->commit();

        echo json_encode([
            "msg"        => "success",
            "personalID" => $personalID,
            "perCode"    => $perCode ?? (string) $personalID,
        ], JSON_PRETTY_PRINT);
    } catch (\Throwable $th) {
        $conn->rollBack();

        if ($th instanceof PDOException && $th->getCode() === '23000') {
            echo json_encode([
                "msg"     => "failed",
                "error"   => "code_exists",
                "message" => "Stakeholder code is already used by another stakeholder.",
            ], JSON_PRETTY_PRINT);
            return;
        }

        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ], JSON_PRETTY_PRINT);
    }
}

// ═══════════════════════════════════════════════════════════════════
// PUT — partial update
// ═══════════════════════════════════════════════════════════════════
function update_personal() {
    global $conn;

    $data = json_decode(file_get_contents("php://input"), true) ?: [];

    $perID = isset($data['per_ID']) ? (int) $data['per_ID'] : 0;
    if ($perID <= 0) {
        http_response_code(400);
        echo json_encode([
            "msg"     => "failed",
            "error"   => "missing_id",
            "message" => "per_ID is required.",
        ]);
        return;
    }

    $allowed = [];

    if (array_key_exists('perCode', $data)) {
        $code = is_string($data['perCode']) ? trim($data['perCode']) : $data['perCode'];
        $allowed['perCode'] = ($code === null || $code === '')
            ? (string) $perID
            : $code;
    }
    if (array_key_exists('perLock', $data)) {
        $allowed['perLock'] = ((int) $data['perLock']) === 1 ? 1 : 0;
    }
    if (array_key_exists('first_name', $data)) {
        $allowed['perName'] = $data['first_name'];
    }
    if (array_key_exists('last_name', $data)) {
        $allowed['perLastName'] = $data['last_name'];
    }
    if (array_key_exists('per_DoB', $data)) {
        $allowed['perDoB'] = ($data['per_DoB'] === '' || $data['per_DoB'] === null)
            ? null
            : $data['per_DoB'];
    }
    if (array_key_exists('per_nidno', $data)) {
        $allowed['perENIDNo'] = $data['per_nidno'];
    }
    if (array_key_exists('per_gender', $data)) {
        $allowed['perGender'] = $data['per_gender'];
    }
    if (array_key_exists('cell_number', $data)) {
        $allowed['perPhone'] = $data['cell_number'];
    }
    if (array_key_exists('email', $data)) {
        $allowed['perEmail'] = $data['email'];
    }

    $setParts = [];
    $params   = [];
    foreach ($allowed as $col => $val) {
        $setParts[] = "$col = ?";
        $params[]   = $val;
    }

    $addID      = isset($data['add_ID']) ? (int) $data['add_ID'] : null;
    $addrUpdate = null;
    if ($addID !== null && $addID > 0) {
        $addrUpdate = [
            'addName'     => array_key_exists('add_name',     $data) ? $data['add_name']     : '__skip__',
            'addCity'     => array_key_exists('add_city',     $data) ? $data['add_city']     : '__skip__',
            'addProvince' => array_key_exists('add_province', $data) ? $data['add_province'] : '__skip__',
            'addCountry'  => array_key_exists('add_country',  $data) ? $data['add_country']  : '__skip__',
            'addZipCode'  => array_key_exists('zip_code',     $data) ? $data['zip_code']     : '__skip__',
            'addMailing'  => array_key_exists('is_mailing',   $data) ? ((int) $data['is_mailing']) : '__skip__',
        ];
    }

    if (empty($setParts) && $addrUpdate === null) {
        echo json_encode([
            "msg"     => "success",
            "perID"   => $perID,
            "message" => "Nothing to update.",
        ]);
        return;
    }

    try {
        $conn->beginTransaction();

        if (!empty($setParts)) {
            $params[] = $perID;
            $sql = "UPDATE personal SET " . implode(', ', $setParts) . " WHERE perID = ?";
            $conn->prepare($sql)->execute($params);
        }

        if ($addrUpdate !== null) {
            $addrSet    = [];
            $addrParams = [];
            foreach ($addrUpdate as $col => $val) {
                if ($val === '__skip__') continue;
                $addrSet[]    = "$col = ?";
                $addrParams[] = $val;
            }
            if (!empty($addrSet)) {
                $addrParams[] = $addID;
                $sql = "UPDATE address SET " . implode(', ', $addrSet) . " WHERE addID = ?";
                $conn->prepare($sql)->execute($addrParams);
            }
        }

        $conn->commit();

        $eff = $allowed['perCode'] ?? null;
        if ($eff === null) {
            $q = $conn->prepare("SELECT perCode FROM personal WHERE perID = ?");
            $q->execute([$perID]);
            $eff = $q->fetchColumn();
        }

        echo json_encode([
            "msg"     => "success",
            "perID"   => $perID,
            "perCode" => $eff,
        ]);
    } catch (\Throwable $th) {
        $conn->rollBack();

        if ($th instanceof PDOException && $th->getCode() === '23000') {
            echo json_encode([
                "msg"     => "failed",
                "error"   => "code_exists",
                "message" => "Stakeholder code is already used by another stakeholder.",
            ]);
            return;
        }

        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// DELETE
// ═══════════════════════════════════════════════════════════════════
function delete_personal() {
    global $conn;

    $data = json_decode(file_get_contents("php://input"), true) ?: [];
    $id = $data['id'] ?? $_GET['id'] ?? null;

    if (!$id) {
        http_response_code(400);
        echo json_encode([
            "msg"     => "failed",
            "error"   => "missing_id",
            "message" => "Stakeholder id is required",
        ]);
        return;
    }
    $id = (int) $id;

    try {
        $conn->beginTransaction();

        $check = $conn->prepare(
            "SELECT perID, perAddress FROM personal WHERE perID = ?"
        );
        $check->execute([$id]);
        $person = $check->fetch(PDO::FETCH_ASSOC);

        if (!$person) {
            $conn->rollBack();
            http_response_code(404);
            echo json_encode([
                "msg"     => "failed",
                "error"   => "not_found",
                "message" => "Stakeholder $id does not exist",
            ]);
            return;
        }

        $addressId = $person['perAddress'] ?? null;

        $accStmt = $conn->prepare(
            "SELECT actAccount FROM accountDetails WHERE actSignatory = ?"
        );
        $accStmt->execute([$id]);
        $accountNumbers = $accStmt->fetchAll(PDO::FETCH_COLUMN);

        if (!empty($accountNumbers)) {
            $in = implode(',', array_fill(0, count($accountNumbers), '?'));

            $trnStmt = $conn->prepare(
                "SELECT COUNT(*) FROM trnDetails WHERE trdAccount IN ($in)"
            );
            $trnStmt->execute($accountNumbers);
            $txCount = (int) $trnStmt->fetchColumn();

            if ($txCount > 0) {
                $conn->rollBack();
                echo json_encode([
                    "msg"               => "failed",
                    "error"             => "trn_exists",
                    "message"           => "Cannot delete stakeholder. One or more accounts have existing transactions.",
                    "transaction_count" => $txCount,
                    "account_count"     => count($accountNumbers),
                ]);
                return;
            }

            $in = implode(',', array_fill(0, count($accountNumbers), '?'));

            $delDetails = $conn->prepare(
                "DELETE FROM accountDetails WHERE actAccount IN ($in)"
            );
            $delDetails->execute($accountNumbers);

            $delAccounts = $conn->prepare(
                "DELETE FROM accounts WHERE accNumber IN ($in)"
            );
            $delAccounts->execute($accountNumbers);
        }

        $delPersonal = $conn->prepare(
            "DELETE FROM personal WHERE perID = ?"
        );
        $delPersonal->execute([$id]);

        if (!empty($addressId)) {
            $delAddress = $conn->prepare(
                "DELETE FROM address WHERE addID = ?"
            );
            $delAddress->execute([$addressId]);
        }

        $conn->commit();
        echo json_encode([
            "msg"              => "success",
            "message"          => "Stakeholder deleted successfully",
            "perID"            => $id,
            "accounts_deleted" => count($accountNumbers),
        ]);
    } catch (\Throwable $th) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}
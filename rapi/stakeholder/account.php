<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once "../db.php";
require_once "../functions.php";

$db    = new Database();
$conn  = $db->getConnection();
$value = new DataValues($conn);

$request_method = $_SERVER["REQUEST_METHOD"];

switch ($request_method) {
    case 'GET':    get_accounts();    break;
    case 'POST':   add_accounts();    break;
    case 'PUT':    update_accounts(); break;
    case 'DELETE': delete_accounts(); break;
    default:
        http_response_code(405);
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

// ─────────────────────────────────────────────────────────────
// Reads request JSON body safely (null if not JSON)
// ─────────────────────────────────────────────────────────────
function body_json(): ?array {
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// ─────────────────────────────────────────────────────────────
// GET  /accounts.php
//   optional ?perID=123  → only that signatory's accounts
//   optional ?search=…   → name or account number
//
// Returns one row per (account, currency).
//
// Currency rules:
//   Stakeholders (8) → accountDetails.actCurrency (own currency)
//   GL (others)      → trnDetails.trdCcy, or comLocalCcy if no txns
//
// Limit / status rules:
//   Stakeholders (8) → real actCreditlimit / actStatus
//   GL (others)      → NULL limit, always active (status = 1)
//
// Sign rules:
//   Assets (1,2,3), Expenses (11,12)  → Dr + / Cr −
//   Liability (4,5), Equity (6,7),
//   Income (9,10), Stakeholders (8)   → Cr + / Dr −
// ─────────────────────────────────────────────────────────────
function get_accounts() {
    global $conn;
    try {
        $sql = "
            SELECT
                a.accNumber,
                a.accName,
                a.accCategory,

                -- Currency
                CASE
                    WHEN a.accCategory = 8 THEN
                        COALESCE(
                            d.actCurrency,
                            t.trdCcy,
                            (SELECT comLocalCcy FROM companyProfile LIMIT 1)
                        )
                    ELSE
                        COALESCE(
                            t.trdCcy,
                            (SELECT comLocalCcy FROM companyProfile LIMIT 1)
                        )
                END AS accCurrency,

                -- Credit limit (stakeholders only)
                CASE
                    WHEN a.accCategory = 8 THEN d.actCreditlimit
                    ELSE NULL
                END AS accLimit,

                -- Status (stakeholders real, GL always active)
                CASE
                    WHEN a.accCategory = 8 THEN COALESCE(d.actStatus, 1)
                    ELSE 1
                END AS accStatus,

                -- Signed balance
                COALESCE(SUM(
                    CASE
                        WHEN a.accCategory IN (1, 2, 3, 11, 12) THEN
                            CASE t.trdDrCr
                                WHEN 'Dr' THEN  t.trdAmount
                                WHEN 'Cr' THEN -t.trdAmount
                                ELSE 0
                            END
                        ELSE
                            CASE t.trdDrCr
                                WHEN 'Cr' THEN  t.trdAmount
                                WHEN 'Dr' THEN -t.trdAmount
                                ELSE 0
                            END
                    END
                ), 0) AS accBalance
            FROM accounts a
            LEFT JOIN accountDetails d ON d.actAccount = a.accNumber
            LEFT JOIN trnDetails t     ON t.trdAccount = a.accNumber
            LEFT JOIN transactions tr  ON tr.trnReference = t.trdReference
                                      AND tr.trnStateText = 'Authorized'
        ";

        // ── Filters
        $where  = [];
        $params = [];

        if (isset($_GET['perID']) && $_GET['perID'] !== '') {
            $where[] = "d.actSignatory = :perID";
            $params[':perID'] = (int)$_GET['perID'];
        }

        if (isset($_GET['search']) && trim($_GET['search']) !== '') {
            $where[] = "(
                a.accName       LIKE :search
                OR CAST(a.accNumber AS CHAR) LIKE :search
            )";
            $params[':search'] = '%' . trim($_GET['search']) . '%';
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " GROUP BY
                    a.accNumber, a.accName, a.accCategory,
                    accCurrency, accLimit, accStatus
                  ORDER BY a.accNumber ASC, accCurrency ASC";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["msg" => "failed", "error" => $e->getMessage()]);
    }
}
// ─────────────────────────────────────────────────────────────
// POST /accounts.php  → create
// ─────────────────────────────────────────────────────────────
function add_accounts() {
    global $conn, $value;

    $data = body_json();
    if (!$data) { echo json_encode(["msg" => "failed", "error" => "invalid_json"]); return; }

    foreach (['accName', 'actCurrency', 'actSignatory'] as $k) {
        if (!isset($data[$k]) || $data[$k] === '') {
            echo json_encode(["msg" => "failed", "error" => "missing_$k"]);
            return;
        }
    }

    $accName       = trim((string)$data['accName']);
    $actCurrency   = (string)$data['actCurrency'];
    $actCreditLimit = $data['actCreditLimit'] ?? 'Unlimited';
    $actSignatory  = (int)$data['actSignatory'];
    $accCategory   = 8;

    $newAcc = $value->getNewAccount($accCategory);
    $com    = 1;

    try {
        $conn->beginTransaction();

        $stmt3 = $conn->prepare(
            "INSERT INTO accounts (accNumber, accName, accCategory) VALUES (?, ?, ?)"
        );
        $stmt3->execute([$newAcc, $accName, $accCategory]);

        $stmt4 = $conn->prepare(
            "INSERT INTO accountDetails
             (actAccount, actCurrency, actCreditlimit, actSignatory, actCompany, actStatus)
             VALUES (?, ?, ?, ?, ?, 1)"
        );
        $stmt4->execute([$newAcc, $actCurrency, $actCreditLimit, $actSignatory, $com]);

        $conn->commit();
        echo json_encode(["msg" => "success", "accountNo" => $newAcc], JSON_PRETTY_PRINT);
    } catch (\Throwable $th) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode(["msg" => "failed", "error" => $th->getMessage()]);
    }
}

// ─────────────────────────────────────────────────────────────
// PUT /accounts.php  → surgical update
//   Allowed always:  accName, actCreditLimit, actStatus
//   Allowed only if 0 transactions:  actCurrency
// ─────────────────────────────────────────────────────────────
function update_accounts() {
    global $conn;

    $data = body_json();
    if (!$data || !isset($data['accNumber'])) {
        echo json_encode(["msg" => "failed", "error" => "invalid_json"]);
        return;
    }

    $accNumber = (int)$data['accNumber'];

    $newName     = array_key_exists('accName', $data)        ? trim((string)$data['accName']) : null;
    $newLimit    = array_key_exists('actCreditLimit', $data) ? $data['actCreditLimit']         : null;
    $newStatus   = array_key_exists('actStatus', $data)      ? (int)$data['actStatus']         : null;
    $newCurrency = array_key_exists('actCurrency', $data)    ? (string)$data['actCurrency']    : null;

    try {
        $conn->beginTransaction();

        // 1. Account exists?
        $check = $conn->prepare(
            "SELECT a.accNumber, a.accName, d.actCurrency, d.actCreditlimit, d.actStatus
             FROM accounts a
             JOIN accountDetails d ON d.actAccount = a.accNumber
             WHERE a.accNumber = ?"
        );
        $check->execute([$accNumber]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed", "error" => "account_not_found"]);
            return;
        }

        // 2. Transactions?
        $trn = $conn->prepare("SELECT COUNT(*) FROM trnDetails WHERE trdAccount = ?");
        $trn->execute([$accNumber]);
        $txCount = (int)$trn->fetchColumn();

        // 3. Currency change blocked if transactions exist
        if ($newCurrency !== null && strtoupper($newCurrency) !== strtoupper($row['actCurrency'])) {
            if ($txCount > 0) {
                $conn->rollBack();
                echo json_encode([
                    "msg"               => "failed",
                    "error"             => "trn_exists",
                    "message"           => "Cannot change currency. There are existing transactions.",
                    "transaction_count" => $txCount,
                ]);
                return;
            }
        }

        // 4. Update accounts.accName (only if provided)
        if ($newName !== null && $newName !== '') {
            $s1 = $conn->prepare("UPDATE accounts SET accName = ? WHERE accNumber = ?");
            $s1->execute([$newName, $accNumber]);
        }

        // 5. Update accountDetails fields (only those provided)
        $setParts = [];
        $params   = [];
        if ($newLimit !== null)    { $setParts[] = "actCreditlimit = ?"; $params[] = $newLimit; }
        if ($newStatus !== null)   { $setParts[] = "actStatus = ?";      $params[] = $newStatus; }
        if ($newCurrency !== null && strtoupper($newCurrency) !== strtoupper($row['actCurrency'])) {
            $setParts[] = "actCurrency = ?"; $params[] = $newCurrency;
        }
        if ($setParts) {
            $params[] = $accNumber;
            $s2 = $conn->prepare(
                "UPDATE accountDetails SET " . implode(', ', $setParts) . "
                 WHERE actAccount = ?"
            );
            $s2->execute($params);
        }

        $conn->commit();
        echo json_encode(["msg" => "success", "accountNo" => $accNumber]);
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

// ─────────────────────────────────────────────────────────────
// DELETE /accounts.php?accNumber=500000
// ─────────────────────────────────────────────────────────────
function delete_accounts() {
    global $conn;

    $data = body_json();
    $accNumber = $data['accNumber'] ?? $_GET['accNumber'] ?? null;

    if (!$accNumber) {
        echo json_encode(["msg" => "failed", "error" => "missing_account_number"]);
        return;
    }
    $accNumber = (int)$accNumber;

    try {
        $conn->beginTransaction();

        $check = $conn->prepare(
            "SELECT accNumber, accName FROM accounts WHERE accNumber = ?"
        );
        $check->execute([$accNumber]);
        $account = $check->fetch(PDO::FETCH_ASSOC);
        if (!$account) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed", "error" => "account_not_found"]);
            return;
        }

        $trn = $conn->prepare("SELECT COUNT(*) FROM trnDetails WHERE trdAccount = ?");
        $trn->execute([$accNumber]);
        $txCount = (int)$trn->fetchColumn();

        if ($txCount > 0) {
            $conn->rollBack();
            echo json_encode([
                "msg"               => "failed",
                "error"             => "trn_exists",
                "message"           => "Cannot delete account. There are existing transactions.",
                "transaction_count" => $txCount,
                "account_number"    => $accNumber,
                "account_name"      => $account['accName'],
            ]);
            return;
        }

        $delDetails = $conn->prepare("DELETE FROM accountDetails WHERE actAccount = ?");
        $delDetails->execute([$accNumber]);

        $delAccount = $conn->prepare("DELETE FROM accounts WHERE accNumber = ?");
        $delAccount->execute([$accNumber]);

        $conn->commit();
        echo json_encode([
            "msg"            => "success",
            "account_number" => $accNumber,
            "account_name"   => $account['accName'],
        ]);
    } catch (\Throwable $th) {
        $conn->rollBack();
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
        ]);
    }
}
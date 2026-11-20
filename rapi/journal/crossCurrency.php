<?php
// ────────────────────────────────────────────────────────────────
// Warnings must NOT leak into the JSON body.
// ────────────────────────────────────────────────────────────────
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ────────────────────────────────────────────────────────────────
// Fatal error → JSON, so the client never gets a truncated body.
// ────────────────────────────────────────────────────────────────
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array(
        $err['type'],
        [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
        true
    )) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
        }
        echo json_encode([
            'msg'   => 'failed',
            'error' => 'fatal: ' . $err['message'],
            'line'  => $err['line'],
            'file'  => $err['file'],
        ]);
    }
});

require_once "../db.php";
require_once "../functions.php";

$db    = new Database();
$conn  = $db->getConnection();
$value = new DataValues($conn);

$request_method = $_SERVER["REQUEST_METHOD"];

// Read the body ONCE. On FPM `php://input` is single-read; passing
// the decoded body avoids null warnings inside handlers.
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) $data = [];

switch ($request_method) {
    case 'GET':
        if (!empty($_GET['ref'])) {
            get_fxTransaction();
        } else {
            list_fxTransactions();
        }
        break;

    case 'POST':
        add_fxTransaction($data);
        break;

    case 'PUT':
    case 'PATCH':
        edit_fxTransactions($data);
        break;

    case 'DELETE':
        delete_fxTransaction($data);
        break;

    default:
        http_response_code(405);
        header('Allow: GET, POST, PUT, DELETE, OPTIONS');
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

// ═════════════════════════════════════════════════════════════════
// Helpers
// ═════════════════════════════════════════════════════════════════

function load_fx_transaction($conn, string $ref): ?array {
    $stmt = $conn->prepare("
        SELECT tr.trnReference, tr.trnType, tr.trnStatus, tr.trnStateText,
               tr.trnMaker, tr.trnAuthorizer, tr.trnEntryDate,
               mk.usrName AS maker, ck.usrName AS checker
        FROM transactions tr
        LEFT JOIN users mk ON mk.usrID = tr.trnMaker
        LEFT JOIN users ck ON ck.usrID = tr.trnAuthorizer
        WHERE tr.trnReference = ?
        LIMIT 1
    ");
    $stmt->execute([$ref]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function load_fx_legs($conn, string $ref): array {
    $stmt = $conn->prepare("
        SELECT td.trdAccount, ac.accName, td.trdCcy, td.trdAmount,
               td.trdDrCr, td.trdNarration, td.trdBranch, td.trdEntryDate
        FROM trnDetails td
        JOIN accounts ac ON ac.accNumber = td.trdAccount
        WHERE td.trdReference = ?
        ORDER BY td.trdDrCr DESC, td.trdID ASC
    ");
    $stmt->execute([$ref]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function account_currency($conn, int $accNumber): string {
    $stmt = $conn->prepare("
        SELECT actCurrency FROM accountDetails
        WHERE actAccount = ? LIMIT 1
    ");
    $stmt->execute([$accNumber]);
    return (string)($stmt->fetchColumn() ?: '');
}

// ═════════════════════════════════════════════════════════════════
// GET list — used by the cross-currency list screen
// ═════════════════════════════════════════════════════════════════
function list_fxTransactions() {
    global $conn;
    try {
        $where  = ["tr.trnType = 'CRFX'"];
        $params = [];

        if (!empty($_GET['state'])) {
            $where[] = "tr.trnStateText = :state";
            $params[':state'] = $_GET['state'];
        }
        if (!empty($_GET['search'])) {
            $where[] = "(tr.trnReference LIKE :s
                         OR tdDr.trdNarration LIKE :s
                         OR accDr.accName LIKE :s
                         OR accCr.accName LIKE :s)";
            $params[':s'] = '%' . trim($_GET['search']) . '%';
        }

        $sql = "
            SELECT tr.trnReference       AS reference,
                   tr.trnStatus          AS status,
                   tr.trnStateText       AS stateText,
                   mk.usrName            AS maker,
                   ck.usrName            AS checker,
                   tr.trnEntryDate       AS entryDate,
                   tdDr.trdAccount       AS debitAccount,
                   accDr.accName         AS debitAccountName,
                   tdDr.trdCcy           AS debitCcy,
                   tdDr.trdAmount        AS debitAmount,
                   tdCr.trdAccount       AS creditAccount,
                   accCr.accName         AS creditAccountName,
                   tdCr.trdCcy           AS creditCcy,
                   tdCr.trdAmount        AS creditAmount,
                   tdDr.trdNarration     AS narration
            FROM transactions tr
            LEFT JOIN users mk      ON mk.usrID = tr.trnMaker
            LEFT JOIN users ck      ON ck.usrID = tr.trnAuthorizer
            JOIN trnDetails tdDr    ON tdDr.trdReference = tr.trnReference AND tdDr.trdDrCr = 'Dr'
            JOIN trnDetails tdCr    ON tdCr.trdReference = tr.trnReference AND tdCr.trdDrCr = 'Cr'
            JOIN accounts accDr     ON accDr.accNumber   = tdDr.trdAccount
            JOIN accounts accCr     ON accCr.accNumber   = tdCr.trdAccount
            WHERE " . implode(" AND ", $where) . "
            ORDER BY tr.trnEntryDate DESC
        ";

        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->execute();
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_PRETTY_PRINT);
    } catch (PDOException $th) {
        http_response_code(500);
        echo json_encode(["msg" => "failed", "error" => $th->getMessage()]);
    }
}

// ═════════════════════════════════════════════════════════════════
// GET single — used by the edit form
// ═════════════════════════════════════════════════════════════════
function get_fxTransaction() {
    global $conn;
    $ref = $_GET['ref'] ?? '';
    if ($ref === '') {
        http_response_code(400);
        echo json_encode(["msg" => "missing_ref"]);
        return;
    }

    try {
        $tran = load_fx_transaction($conn, $ref);
        if (!$tran) {
            http_response_code(404);
            echo json_encode(["msg" => "not_found"]);
            return;
        }
        if ($tran['trnType'] !== 'CRFX') {
            http_response_code(400);
            echo json_encode(["msg" => "invalid_type"]);
            return;
        }

        $legs   = load_fx_legs($conn, $ref);
        $debit  = array_values(array_filter($legs, fn($l) => $l['trdDrCr'] === 'Dr'));
        $credit = array_values(array_filter($legs, fn($l) => $l['trdDrCr'] === 'Cr'));

        $tran['debit']  = $debit;
        $tran['credit'] = $credit;

        echo json_encode($tran, JSON_PRETTY_PRINT);
    } catch (PDOException $th) {
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ═════════════════════════════════════════════════════════════════
// POST — add cross-currency / exchange money (Pending only)
//
//   Records shape (array of legs, each with one side):
//     { account, ccy, debit, credit, narration }
//
//   A cross-currency transfer has:
//     - one Dr leg (account debited, its currency)
//     - one Cr leg (account credited, its currency)
//     - the two currencies MUST differ (otherwise it's not FX)
//     - at least one Dr + one Cr, sum is not required to match
//       because the two currencies have different rates.
// ═════════════════════════════════════════════════════════════════
function add_fxTransaction(array $data) {
    global $conn, $value;
    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));

    $user    = trim($data['usrName'] ?? '');
    $records = $data['records'] ?? null;

    if ($user === '' || !is_array($records) || count($records) < 2) {
        echo json_encode(["msg" => "empty"]); return;
    }

    $type      = "CRFX";
    $status    = 0;
    $stateText = "Pending";
    $entryAt   = date("Y-m-d H:i:s");

    $userResult = $value->getUserDetails($user);
    if (empty($userResult['usrID'])) {
        echo json_encode(["msg" => "invalid_user"]); return;
    }
    $branch = (int)$userResult['usrBranch'];
    $usrID  = (int)$userResult['usrID'];

    // ── Pre-validate legs ─────────────────────────────────────
    $currencies = [];
    $drCount = 0;
    $crCount = 0;

    foreach ($records as $rec) {
        $acc     = (int)($rec['account'] ?? 0);
        $ccy     = trim($rec['ccy'] ?? '');
        $debit   = (float)($rec['debit'] ?? 0);
        $credit  = (float)($rec['credit'] ?? 0);
        $remark  = trim($rec['narration'] ?? '');

        if ($acc === 0 || $ccy === '' || ($debit <= 0 && $credit <= 0)) {
            echo json_encode(["msg" => "empty", "account" => $acc]);
            return;
        }

        $currencies[$ccy] = true;
        if ($debit > 0) {
            $drCount++;
            if ($value->checkForAccountLimit($acc, $debit) != 1) {
                echo json_encode(["msg" => "no_limit", "account" => $acc]);
                return;
            }
        } else {
            $crCount++;
        }

        if ($acc < 10000000) {
            $accStatus = $value->getAccountDetails($acc, 'actStatus');
            if ($accStatus == 0) {
                echo json_encode(["msg" => "blocked", "account" => $acc]);
                return;
            }
        }
    }

    if ($drCount !== 1 || $crCount !== 1) {
        echo json_encode(["msg" => "invalid_legs"]);
        return;
    }
    if (count($currencies) < 2) {
        // Same-currency pairs must use fundTransfer.php (ATAT).
        echo json_encode(["msg" => "same_ccy"]);
        return;
    }

    $trnRef = $value->generateTrnRef($branch, $type);

    try {
        $conn->beginTransaction();

        $conn->prepare("
            INSERT INTO transactions
                (trnReference, trnType, trnStatus, trnStateText,
                 trnMaker, trnAuthorizer, trnEntryDate)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$trnRef, $type, $status, $stateText, $usrID, null, $entryAt]);

        $stmt1 = $conn->prepare("
            INSERT INTO trnDetails
                (trdReference, trdCcy, trdBranch, trdAccount,
                 trdDrCr, trdAmount, trdNarration, trdEntryDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($records as $rec) {
            $acc    = (int)$rec['account'];
            $ccy    = $rec['ccy'];
            $debit  = (float)($rec['debit']  ?? 0);
            $credit = (float)($rec['credit'] ?? 0);
            $remark = trim($rec['narration'] ?? '');
            $isDr   = $debit > 0;
            $amount = $isDr ? $debit : $credit;
            $drCr   = $isDr ? 'Dr' : 'Cr';

            $stmt1->execute([
                $trnRef, $ccy, $branch, $acc, $drCr, $amount, $remark, $entryAt,
            ]);
        }

        $conn->commit();
        echo json_encode(["msg" => "success", "reference" => $trnRef]);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ═════════════════════════════════════════════════════════════════
// PUT — edit a Pending CRFX
//
//   Expected body:
//     {
//       reference, usrName,
//       debit:  { account, ccy, amount, narration },
//       credit: { account, ccy, amount, narration }
//     }
//
//   Both legs are updated in place — Dr matches Dr, Cr matches Cr,
//   so the two currencies/amounts stay in sync with the caller.
// ═════════════════════════════════════════════════════════════════
function edit_fxTransactions(array $data) {
    global $conn, $value;
    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));

    $ref     = trim($data['reference'] ?? '');
    $user    = trim($data['usrName']   ?? '');
    $debit   = $data['debit']  ?? null;
    $credit  = $data['credit'] ?? null;

    if ($ref === '' || $user === '' || !is_array($debit) || !is_array($credit)) {
        echo json_encode(["msg" => "empty"]); return;
    }

    $dAcc = (int)($debit['account']  ?? 0);
    $dCcy = trim($debit['ccy']       ?? '');
    $dAmt = (float)($debit['amount'] ?? 0);
    $dNar = trim($debit['narration'] ?? '');

    $cAcc = (int)($credit['account']  ?? 0);
    $cCcy = trim($credit['ccy']       ?? '');
    $cAmt = (float)($credit['amount'] ?? 0);
    $cNar = trim($credit['narration'] ?? '');

    if ($dAcc === 0 || $cAcc === 0 || $dAmt <= 0 || $cAmt <= 0
        || $dCcy === '' || $cCcy === '') {
        echo json_encode(["msg" => "empty"]); return;
    }
    if ($dCcy === $cCcy) {
        echo json_encode(["msg" => "same_ccy"]); return;
    }
    if ($dAcc === $cAcc) {
        echo json_encode(["msg" => "same_account"]); return;
    }

    $row = load_fx_transaction($conn, $ref);
    if (!$row) {
        http_response_code(404);
        echo json_encode(["msg" => "not_found"]); return;
    }
    if ($row['trnType'] !== 'CRFX') {
        echo json_encode(["msg" => "invalid_type"]); return;
    }
    if ($row['trnStateText'] !== 'Pending') {
        echo json_encode([
            "msg"   => "invalid_action",
            "error" => "only_pending_can_be_edited",
        ]); return;
    }
    if ((string)$row['maker'] !== (string)$user) {
        echo json_encode([
            "msg"   => "invalid",
            "error" => "only_maker_can_act",
        ]); return;
    }

    // Currency of each account must match the leg we're writing.
    $dAccCcy = account_currency($conn, $dAcc);
    $cAccCcy = account_currency($conn, $cAcc);
    if ($dAccCcy !== '' && $dAccCcy !== $dCcy) {
        echo json_encode(["msg" => "ccy_mismatch", "leg" => "debit"]); return;
    }
    if ($cAccCcy !== '' && $cAccCcy !== $cCcy) {
        echo json_encode(["msg" => "ccy_mismatch", "leg" => "credit"]); return;
    }

    if ($value->checkForAccountLimit($dAcc, $dAmt) != 1) {
        echo json_encode(["msg" => "no_limit", "account" => $dAcc]); return;
    }

    try {
        $conn->beginTransaction();

        // Update Dr leg only.
        $updDr = $conn->prepare("
            UPDATE trnDetails
               SET trdAccount = ?, trdCcy = ?, trdAmount = ?, trdNarration = ?
             WHERE trdReference = ? AND trdDrCr = 'Dr'
        ");
        $updDr->execute([$dAcc, $dCcy, $dAmt, $dNar, $ref]);

        // Update Cr leg only.
        $updCr = $conn->prepare("
            UPDATE trnDetails
               SET trdAccount = ?, trdCcy = ?, trdAmount = ?, trdNarration = ?
             WHERE trdReference = ? AND trdDrCr = 'Cr'
        ");
        $updCr->execute([$cAcc, $cCcy, $cAmt, $cNar, $ref]);

        // Entry date is preserved (audit trail). Only the state stays Pending.

        $conn->commit();
        echo json_encode(["msg" => "success", "reference" => $ref]);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ═════════════════════════════════════════════════════════════════
// DELETE — soft delete a Pending CRFX
//   Maker only. Sets trnStateText = 'Deleted', removes detail legs
//   so balances roll back, keeps the header row for the audit list.
// ═════════════════════════════════════════════════════════════════
function delete_fxTransaction(array $data) {
    global $conn, $value;

    $ref  = trim($data['reference'] ?? ($_GET['ref']     ?? ''));
    $user = trim($data['usrName']   ?? ($_GET['usrName'] ?? ''));

    if ($ref === '' || $user === '') {
        echo json_encode(["msg" => "empty"]); return;
    }

    $row = load_fx_transaction($conn, $ref);
    if (!$row) {
        http_response_code(404);
        echo json_encode(["msg" => "not_found"]); return;
    }
    if ($row['trnType'] !== 'CRFX') {
        echo json_encode(["msg" => "invalid_type"]); return;
    }
    if ($row['trnStateText'] !== 'Pending') {
        echo json_encode([
            "msg"   => "invalid_action",
            "error" => "only_pending_can_be_deleted",
        ]); return;
    }
    if ((string)$row['maker'] !== (string)$user) {
        echo json_encode([
            "msg"   => "invalid",
            "error" => "only_maker_can_act",
        ]); return;
    }

    try {
        $conn->beginTransaction();

        $usrDetails = $value->getUserDetails($user);
        $checkerID  = (int)($usrDetails['usrID'] ?? 0);

        $conn->prepare("DELETE FROM trnDetails WHERE trdReference = ?")
             ->execute([$ref]);

        $conn->prepare("
            UPDATE transactions
               SET trnStateText = 'Deleted', trnStatus = 0, trnAuthorizer = ?
             WHERE trnReference = ?
        ")->execute([$checkerID, $ref]);

        $conn->commit();
        echo json_encode([
            "msg"       => "success",
            "reference" => $ref,
            "stateText" => "Deleted",
        ]);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}
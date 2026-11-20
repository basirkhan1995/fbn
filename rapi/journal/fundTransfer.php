<?php
// ────────────────────────────────────────────────────────────────
// Warnings must NOT be printed into the JSON body — they would
// corrupt the response and the client would see "network error".
// Log them instead, and convert fatals to JSON below.
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

$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) $data = [];

switch ($request_method) {
    case 'GET':
        if (!empty($_GET['ref'])) {
            get_fundTransfer();
        } else {
            list_fundTransfers();
        }
        break;

    case 'POST':
        add_fundTransfer($data);
        break;

    case 'PUT':
    case 'PATCH':
        edit_fundTransfer($data);
        break;

    case 'DELETE':
        delete_fundTransfer($data);
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

/**
 * An account is a valid transfer endpoint if:
 *   - it exists
 *   - it belongs to a cash/bank GL category (1) or is a
 *     stakeholder account (8)
 *   - for stakeholders only: actStatus = 1
 */
function isTransferableAccount($conn, int $accNumber): bool {
    $stmt = $conn->prepare("
        SELECT a.accCategory, d.actStatus
        FROM accounts a
        LEFT JOIN accountDetails d ON d.actAccount = a.accNumber
        WHERE a.accNumber = ?
        LIMIT 1
    ");
    $stmt->execute([$accNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) return false;

    $cat    = (int)$row['accCategory'];
    $status = $row['actStatus'];

    if (!in_array($cat, [1, 8], true)) return false;

    if ($cat === 8) {
        return $status !== null && (int)$status === 1;
    }
    return true;
}

function isStakeholderAccount($conn, int $accNumber): bool {
    $stmt = $conn->prepare("
        SELECT accCategory FROM accounts WHERE accNumber = ? LIMIT 1
    ");
    $stmt->execute([$accNumber]);
    return (int)($stmt->fetchColumn() ?: 0) === 8;
}

function load_fund_transfer($conn, string $ref): ?array {
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

function load_fund_transfer_legs($conn, string $ref): array {
    // accCategory is included so the Flutter edit form can tell
    // stakeholder vs. GL legs apart.
    $stmt = $conn->prepare("
        SELECT td.trdAccount, ac.accName, ac.accCategory,
               td.trdCcy, td.trdAmount, td.trdDrCr,
               td.trdNarration, td.trdBranch, td.trdEntryDate
        FROM trnDetails td
        JOIN accounts ac ON ac.accNumber = td.trdAccount
        WHERE td.trdReference = ?
        ORDER BY td.trdDrCr DESC
    ");
    $stmt->execute([$ref]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Look up the currency of an account:
 *   - Stakeholders → accountDetails.actCurrency
 *   - GL           → most recent trnDetails.trdCcy
 *   - fallback     → companyProfile.comLocalCcy
 */
function get_account_currency($conn, int $accNumber): string {
    $stmt = $conn->prepare("
        SELECT actCurrency FROM accountDetails
        WHERE actAccount = ? LIMIT 1
    ");
    $stmt->execute([$accNumber]);
    $ccy = $stmt->fetchColumn();
    if (!empty($ccy)) return (string)$ccy;

    $stmt = $conn->prepare("
        SELECT trdCcy FROM trnDetails
        WHERE trdAccount = ?
        ORDER BY trdID DESC
        LIMIT 1
    ");
    $stmt->execute([$accNumber]);
    $ccy = $stmt->fetchColumn();
    if (!empty($ccy)) return (string)$ccy;

    $base = $conn->query("
        SELECT comLocalCcy FROM companyProfile LIMIT 1
    ")->fetchColumn();
    return $base ? (string)$base : '';
}

// ═════════════════════════════════════════════════════════════════
// GET single
// ═════════════════════════════════════════════════════════════════
function get_fundTransfer() {
    global $conn;
    $ref = $_GET['ref'] ?? '';
    if ($ref === '') {
        http_response_code(400);
        echo json_encode(["msg" => "missing_ref"]);
        return;
    }

    try {
        $tran = load_fund_transfer($conn, $ref);
        if (!$tran) {
            http_response_code(404);
            echo json_encode(["msg" => "not_found"]);
            return;
        }
        if ($tran['trnType'] !== 'ATAT') {
            http_response_code(400);
            echo json_encode(["msg" => "invalid_type"]);
            return;
        }

        $legs   = load_fund_transfer_legs($conn, $ref);
        $debit  = array_values(array_filter($legs, fn($l) => $l['trdDrCr'] === 'Dr'));
        $credit = array_values(array_filter($legs, fn($l) => $l['trdDrCr'] === 'Cr'));

        $tran['type']   = count($legs) <= 2 ? 'single' : 'double';
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
// GET list
// ═════════════════════════════════════════════════════════════════
function list_fundTransfers() {
    global $conn;
    try {
        $where  = ["tr.trnType = 'ATAT'"];
        $params = [];

        if (!empty($_GET['state'])) {
            $where[] = "tr.trnStateText = :state";
            $params[':state'] = $_GET['state'];
        }
        if (!empty($_GET['search'])) {
            $where[] = "(tr.trnReference LIKE :s
                         OR tdFrom.trdNarration LIKE :s
                         OR accFrom.accName LIKE :s
                         OR accTo.accName LIKE :s)";
            $params[':s'] = '%' . trim($_GET['search']) . '%';
        }

        $sql = "
            SELECT tr.trnReference       AS reference,
                   tr.trnStatus          AS status,
                   tr.trnStateText       AS stateText,
                   mk.usrName            AS maker,
                   ck.usrName            AS checker,
                   tr.trnEntryDate       AS entryDate,
                   tdFrom.trdAccount     AS fromAccount,
                   accFrom.accName       AS fromAccountName,
                   tdFrom.trdCcy         AS fromCcy,
                   tdFrom.trdAmount      AS amount,
                   tdTo.trdAccount       AS toAccount,
                   accTo.accName         AS toAccountName,
                   tdTo.trdCcy           AS toCcy,
                   tdFrom.trdNarration   AS narration
            FROM transactions tr
            LEFT JOIN users mk     ON mk.usrID = tr.trnMaker
            LEFT JOIN users ck     ON ck.usrID = tr.trnAuthorizer
            JOIN trnDetails tdFrom ON tdFrom.trdReference = tr.trnReference AND tdFrom.trdDrCr = 'Dr'
            JOIN trnDetails tdTo   ON tdTo.trdReference   = tr.trnReference AND tdTo.trdDrCr   = 'Cr'
            JOIN accounts accFrom  ON accFrom.accNumber   = tdFrom.trdAccount
            JOIN accounts accTo    ON accTo.accNumber     = tdTo.trdAccount
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
// POST — add
// ═════════════════════════════════════════════════════════════════
function add_fundTransfer(array $data) {
    global $conn, $value;
    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));

    $user    = trim($data['usrName']     ?? '');
    $fromAcc = (int)($data['fromAccount'] ?? 0);
    $toAcc   = (int)($data['toAccount']   ?? 0);
    $fromCcy = trim($data['fromAccCcy']   ?? '');
    $toCcy   = trim($data['toAccCcy']     ?? '');
    $amount  = (float)($data['amount']    ?? 0);
    $remark  = trim($data['narration']    ?? '');
    $entryAt = date("Y-m-d H:i:s");

    if ($user === '' || $fromAcc === 0 || $toAcc === 0
        || $fromCcy === '' || $toCcy === ''
        || $amount <= 0 || $remark === '') {
        echo json_encode(["msg" => "empty"]); return;
    }
    if ($fromAcc === $toAcc) {
        echo json_encode(["msg" => "same_account"]); return;
    }
    if ($fromCcy !== $toCcy) {
        echo json_encode(["msg" => "currency_unmatch"]); return;
    }
    if (!isTransferableAccount($conn, $fromAcc)
        || !isTransferableAccount($conn, $toAcc)) {
        echo json_encode(["msg" => "blocked"]); return;
    }

    $userResult = $value->getUserDetails($user);
    if (empty($userResult['usrID'])) {
        echo json_encode(["msg" => "invalid_user"]); return;
    }
    $usrID  = (int)$userResult['usrID'];
    $branch = (int)$userResult['usrBranch'];

    $type      = "ATAT";
    $status    = 0;
    $stateText = "Pending";
    $authUser  = null;

    // Credit limit only applies to stakeholder source accounts.
    if (isStakeholderAccount($conn, $fromAcc)
        && $value->checkForAccountLimit($fromAcc, $amount) != 1) {
        echo json_encode(["msg" => "no_limit"]); return;
    }

    $trnRef = $value->generateTrnRef($branch, $type);

    try {
        $conn->beginTransaction();

        $conn->prepare("
            INSERT INTO transactions
                (trnReference, trnType, trnStatus, trnStateText,
                 trnMaker, trnAuthorizer, trnEntryDate)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([$trnRef, $type, $status, $stateText, $usrID, $authUser, $entryAt]);

        $stmt1 = $conn->prepare("
            INSERT INTO trnDetails
                (trdReference, trdCcy, trdBranch, trdAccount,
                 trdDrCr, trdAmount, trdNarration, trdEntryDate)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $ok1 = $stmt1->execute([$trnRef, $fromCcy, $branch, $fromAcc, "Dr", $amount, $remark, $entryAt]);
        $ok2 = $stmt1->execute([$trnRef, $toCcy,   $branch, $toAcc,   "Cr", $amount, $remark, $entryAt]);

        if (!$ok1 || !$ok2) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed"]); return;
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
// PUT — edit
// ═════════════════════════════════════════════════════════════════
function edit_fundTransfer(array $data) {
    global $conn, $value;
    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));

    $ref     = trim($data['reference']    ?? '');
    $user    = trim($data['usrName']      ?? '');
    $fromAcc = (int)($data['fromAccount'] ?? 0);
    $toAcc   = (int)($data['toAccount']   ?? 0);
    $amount  = (float)($data['amount']    ?? 0);
    $remark  = trim($data['narration']    ?? '');

    // Edit-form may send currencies explicitly (needed when the
    // source/target is a GL account, whose currency isn't fixed).
    $reqFromCcy = trim((string)($data['fromAccCcy'] ?? ''));
    $reqToCcy   = trim((string)($data['toAccCcy']   ?? ''));

    if ($ref === '' || $user === '' || $fromAcc === 0 || $toAcc === 0
        || $amount <= 0 || $remark === '') {
        echo json_encode(["msg" => "empty"]); return;
    }
    if ($fromAcc === $toAcc) {
        echo json_encode(["msg" => "same_account"]); return;
    }
    if (!isTransferableAccount($conn, $fromAcc)
        || !isTransferableAccount($conn, $toAcc)) {
        echo json_encode(["msg" => "blocked"]); return;
    }

    $row = load_fund_transfer($conn, $ref);
    if (!$row) {
        http_response_code(404);
        echo json_encode(["msg" => "not_found"]); return;
    }
    if ($row['trnType'] !== 'ATAT') {
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

    // Credit limit only applies to stakeholder source accounts.
    if (isStakeholderAccount($conn, $fromAcc)
        && $value->checkForAccountLimit($fromAcc, $amount) != 1) {
        echo json_encode(["msg" => "no_limit"]); return;
    }

    // ── Currency resolution ─────────────────────────────────
    //    Stakeholder legs: take actCurrency (never trust client).
    //    GL legs: honour the client-sent value because GL has no
    //    single currency — the user picked one in the form.
    if (isStakeholderAccount($conn, $fromAcc)) {
        $fromCcy = get_account_currency($conn, $fromAcc);
    } else {
        $fromCcy = $reqFromCcy !== '' ? $reqFromCcy : get_account_currency($conn, $fromAcc);
    }

    if (isStakeholderAccount($conn, $toAcc)) {
        $toCcy = get_account_currency($conn, $toAcc);
    } else {
        $toCcy = $reqToCcy !== '' ? $reqToCcy : get_account_currency($conn, $toAcc);
    }

    if ($fromCcy === '' || $toCcy === '') {
        echo json_encode(["msg" => "blocked"]); return;
    }
    if ($fromCcy !== $toCcy) {
        echo json_encode(["msg" => "currency_unmatch"]); return;
    }

    try {
        $conn->beginTransaction();

        $upd = $conn->prepare("
            UPDATE trnDetails
               SET trdAccount = ?, trdAmount = ?, trdNarration = ?, trdCcy = ?
             WHERE trdReference = ? AND trdDrCr = ?
        ");
        $upd->execute([$fromAcc, $amount, $remark, $fromCcy, $ref, 'Dr']);
        $upd->execute([$toAcc,   $amount, $remark, $toCcy,   $ref, 'Cr']);

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
// DELETE — soft
// ═════════════════════════════════════════════════════════════════
function delete_fundTransfer(array $data) {
    global $conn, $value;

    $ref  = trim($data['reference'] ?? ($_GET['ref']     ?? ''));
    $user = trim($data['usrName']   ?? ($_GET['usrName'] ?? ''));

    if ($ref === '' || $user === '') {
        echo json_encode(["msg" => "empty"]); return;
    }

    $row = load_fund_transfer($conn, $ref);
    if (!$row) {
        http_response_code(404);
        echo json_encode(["msg" => "not_found"]); return;
    }
    if ($row['trnType'] !== 'ATAT') {
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
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

$db = new Database();
$conn = $db->getConnection();
$value = new DataValues($conn);

$request_method = $_SERVER["REQUEST_METHOD"];

switch ($request_method) {
    case 'GET':    get_cashTxn();       break;
    case 'POST':   cash_Transaction();  break;
    case 'PUT':    edit_Transactions(); break;
    case 'DELETE': delete_cashTxn();    break;
    default:
        http_response_code(405);
        header('Allow: GET, POST, PUT, DELETE, OPTIONS');
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

// ═════════════════════════════════════════════════════════════
// Shared: fetch the full detail (header + lines + account name).
// Same flat shape as GET so the Flutter model parses it directly.
// ═════════════════════════════════════════════════════════════
function _fetchCashDetail($conn, $ref) {
    $stmt = $conn->prepare(
        "SELECT tr.trnReference, tr.trnType, tr.trnStatus, tr.trnStateText,
                tr.trnMaker, mk.usrName AS makerName,
                tr.trnAuthorizer, ck.usrName AS checkerName,
                tr.trnEntryDate
         FROM transactions tr
         LEFT JOIN users mk ON mk.usrID = tr.trnMaker
         LEFT JOIN users ck ON ck.usrID = tr.trnAuthorizer
         WHERE tr.trnReference = ?"
    );
    $stmt->execute([$ref]);
    $header = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$header) {
        return ["msg" => "failed", "error" => "not_found"];
    }

    $stmt1 = $conn->prepare(
        "SELECT d.trdCcy, d.trdBranch, d.trdAccount,
                a.accName AS accName,
                d.trdDrCr, d.trdAmount, d.trdNarration, d.trdEntryDate
         FROM trnDetails d
         LEFT JOIN accounts a ON a.accNumber = d.trdAccount
         WHERE d.trdReference = ?
         ORDER BY d.trdID ASC"
    );
    $stmt1->execute([$ref]);
    $lines = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    return [
        "msg"         => "success",
        "reference"   => $header['trnReference'],
        "type"        => $header['trnType'],
        "status"      => (int)$header['trnStatus'],
        "stateText"   => $header['trnStateText'],
        "maker"       => (int)$header['trnMaker'],
        "makerName"   => $header['makerName'],
        "checker"     => $header['trnAuthorizer'] === null
                            ? null
                            : (int)$header['trnAuthorizer'],
        "checkerName" => $header['checkerName'],
        "entryDate"   => $header['trnEntryDate'],
        "lines"       => $lines,
    ];
}

// ═════════════════════════════════════════════════════════════
// POST /journal/cashWD.php — create a new transaction
// ═════════════════════════════════════════════════════════════
function cash_Transaction() {
    global $conn, $value;
    $data = json_decode(file_get_contents("php://input"), true);
    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));

    if (!is_array($data)) {
        echo json_encode(["msg" => "failed", "error" => "invalid_json"], JSON_PRETTY_PRINT);
        return;
    }

    $type     = $data['trnType']   ?? null;
    $user     = $data['usrName']   ?? null;
    $account  = $data['account']   ?? null;
    $accCcy   = $data['accCcy']    ?? null;
    $amount   = $data['amount']    ?? null;
    $remark   = $data['narration'] ?? null;

    $entryDateTime = date("Y-m-d H:i:s");

    if (empty($account) || empty($type) || empty($user)
        || empty($accCcy) || empty($amount) || empty($remark)) {
        echo json_encode(["msg" => "empty"], JSON_PRETTY_PRINT);
        return;
    }

    $userResult = $value->getUserDetails($user);
    $branch = $userResult['usrBranch'];
    $usrID  = $userResult['usrID'];
    $trnRef = $value->generateTrnRef($branch, $type);

    $limit   = $value->getBranchAuthLimit($branch, $accCcy);
    $valueGL = 10101010;

    if ($amount <= $limit) {
        $authUser  = $usrID;
        $stateText = "Authorized";
        $status    = 1;
    } else {
        $authUser  = NULL;
        $stateText = "Pending";
        $status    = 0;
    }

    $accStatus = null;
    $acclimit  = null;
    if ($account < 600000) {
        $accStatus = $value->getAccountDetails($account, 'actStatus');
        $acclimit  = $value->checkForAccountLimit($account, $amount);
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare(
            "INSERT INTO transactions
                (trnReference, trnType, trnStatus, trnStateText, trnMaker, trnAuthorizer, trnEntryDate)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([$trnRef, $type, $status, $stateText, $usrID, $authUser, $entryDateTime]);

        $stmt1 = $conn->prepare(
            "INSERT INTO trnDetails
                (trdReference, trdCcy, trdBranch, trdAccount, trdDrCr, trdAmount, trdNarration, trdEntryDate)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        switch ($type) {
            case 'CHDP':
                if ($accStatus != 1) {
                    $conn->rollBack();
                    echo json_encode(["msg" => "blocked"], JSON_PRETTY_PRINT);
                    return;
                }
                $done1 = $stmt1->execute([$trnRef, $accCcy, $branch, $valueGL, "Dr", $amount, $remark, $entryDateTime]);
                $done2 = $stmt1->execute([$trnRef, $accCcy, $branch, $account,  "Cr", $amount, $remark, $entryDateTime]);
                break;

            case 'CHWL':
                if ($acclimit != 1) {
                    $conn->rollBack();
                    echo json_encode(["msg" => "over limit"], JSON_PRETTY_PRINT);
                    return;
                }
                if ($accStatus != 1) {
                    $conn->rollBack();
                    echo json_encode(["msg" => "blocked"], JSON_PRETTY_PRINT);
                    return;
                }
                $done1 = $stmt1->execute([$trnRef, $accCcy, $branch, $account,  "Dr", $amount, $remark, $entryDateTime]);
                $done2 = $stmt1->execute([$trnRef, $accCcy, $branch, $valueGL, "Cr", $amount, $remark, $entryDateTime]);
                break;

            case 'INCM':
                $done1 = $stmt1->execute([$trnRef, $accCcy, $branch, $valueGL, "Dr", $amount, $remark, $entryDateTime]);
                $done2 = $stmt1->execute([$trnRef, $accCcy, $branch, $account,  "Cr", $amount, $remark, $entryDateTime]);
                break;

            case 'XPNS':
                $done1 = $stmt1->execute([$trnRef, $accCcy, $branch, $account,  "Dr", $amount, $remark, $entryDateTime]);
                $done2 = $stmt1->execute([$trnRef, $accCcy, $branch, $valueGL, "Cr", $amount, $remark, $entryDateTime]);
                break;

            case 'GLDR':
                $done1 = $stmt1->execute([$trnRef, $accCcy, $branch, $account,  "Dr", $amount, $remark, $entryDateTime]);
                $done2 = $stmt1->execute([$trnRef, $accCcy, $branch, $valueGL, "Cr", $amount, $remark, $entryDateTime]);
                break;

            case 'GLCR':
                $done1 = $stmt1->execute([$trnRef, $accCcy, $branch, $valueGL, "Dr", $amount, $remark, $entryDateTime]);
                $done2 = $stmt1->execute([$trnRef, $accCcy, $branch, $account,  "Cr", $amount, $remark, $entryDateTime]);
                break;

            default:
                $conn->rollBack();
                echo json_encode(["msg" => "failed type"], JSON_PRETTY_PRINT);
                return;
        }

        if (!$done1 || !$done2) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed"], JSON_PRETTY_PRINT);
            return;
        }

        $conn->commit();

        $value->generateUserActivityLog(
            $user,
            $type,
            "$trnRef,\n$account,\n$amount-$accCcy"
        );

        // ── Return the same flat shape as GET ──────────────
        $detail = _fetchCashDetail($conn, $trnRef);
        echo json_encode($detail, JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        $conn->rollBack();
        echo json_encode([
            'msg'   => 'failed',
            'error' => $th->getMessage(),
            'line'  => $th->getLine(),
            'file'  => $th->getFile(),
        ], JSON_PRETTY_PRINT);
    }
}

// ═════════════════════════════════════════════════════════════
// PUT /journal/cashWD.php — edit a pending transaction
// ═════════════════════════════════════════════════════════════
function edit_Transactions() {
    global $conn, $value;
    $data = json_decode(file_get_contents("php://input"), true);
    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));

    if (!is_array($data)) {
        echo json_encode(["msg" => "failed", "error" => "invalid_json"], JSON_PRETTY_PRINT);
        return;
    }

    $ref    = $data['reference']  ?? null;
    $user   = $data['usrName']    ?? null;
    $amount = $data['amount']     ?? null;
    $accCcy = $data['accCcy']     ?? null;
    $remark = $data['narration']  ?? null;

    $entryDateTime = date("Y-m-d H:i:s");

    if (empty($ref) || empty($user) || empty($accCcy) || empty($amount) || empty($remark)) {
        echo json_encode(["msg" => "empty"], JSON_PRETTY_PRINT);
        return;
    }

    $usrDetails = $value->getUserDetails($user);
    $checkerID  = $usrDetails['usrID'];
    $branch     = $usrDetails['usrBranch'];

    $limit = $value->getBranchAuthLimit($branch, $accCcy);

    if ($amount <= $limit) {
        $authUser   = $checkerID;
        $status     = 1;
        $statusText = "Authorized";
    } else {
        $authUser   = NULL;
        $status     = 0;
        $statusText = "Pending";
    }

    try {
        $conn->beginTransaction();

        $stmt0 = $conn->prepare("SELECT * FROM transactions WHERE trnReference = ?");
        $stmt0->execute([$ref]);
        $row = $stmt0->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed", "error" => "not_found"], JSON_PRETTY_PRINT);
            return;
        }

        $makerID       = $row['trnMaker'];
        $trnStatus     = (int)$row['trnStatus'];
        $trnStatusText = $row['trnStateText'];

        if ($makerID != $checkerID) {
            $conn->rollBack();
            echo json_encode(["msg" => "invalid user"], JSON_PRETTY_PRINT);
            return;
        }
        if ($trnStatus != 0) {
            $conn->rollBack();
            echo json_encode(["msg" => "invalid action"], JSON_PRETTY_PRINT);
            return;
        }
        if ($trnStatusText == 'Reversed' || $trnStatusText == 'Authorized') {
            $conn->rollBack();
            echo json_encode(["msg" => "invalid action"], JSON_PRETTY_PRINT);
            return;
        }

        $stmt1 = $conn->prepare(
            "UPDATE transactions
                SET trnStatus = ?, trnStateText = ?, trnAuthorizer = ?, trnEntryDate = ?
              WHERE trnReference = ?"
        );
        $done1 = $stmt1->execute([$status, $statusText, $authUser, $entryDateTime, $ref]);

        $stmt2 = $conn->prepare(
            "UPDATE trnDetails SET trdAmount = ?, trdNarration = ? WHERE trdReference = ?"
        );
        $done2 = $stmt2->execute([$amount, $remark, $ref]);

        if (!$done1 || !$done2) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed"], JSON_PRETTY_PRINT);
            return;
        }

        $conn->commit();

        // ── Return the same flat shape as GET ──────────────
        $detail = _fetchCashDetail($conn, $ref);
        echo json_encode($detail, JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ], JSON_PRETTY_PRINT);
    }
}

// ═════════════════════════════════════════════════════════════
// GET /journal/cashWD.php?ref=…
// ═════════════════════════════════════════════════════════════
function get_cashTxn() {
    global $conn;

    $ref = $_GET['ref'] ?? null;
    if (!$ref) {
        echo json_encode(["msg" => "failed", "error" => "missing_ref"]);
        return;
    }

    try {
        $detail = _fetchCashDetail($conn, $ref);
        if (($detail['msg'] ?? '') !== 'success') {
            http_response_code(404);
        }
        echo json_encode($detail, JSON_PRETTY_PRINT);
    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ═════════════════════════════════════════════════════════════
// DELETE /journal/cashWD.php — maker only, pending only
// ═════════════════════════════════════════════════════════════
function delete_cashTxn() {
    global $conn, $value;

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) $data = [];

    $ref  = $data['reference'] ?? ($_GET['ref']     ?? null);
    $user = $data['username']  ?? ($_GET['usrName'] ?? null);

    if (!$ref || !$user) {
        echo json_encode(["msg" => "failed", "error" => "missing_ref_or_user"]);
        return;
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare(
            "SELECT tr.*, mk.usrName AS makerName
             FROM transactions tr
             LEFT JOIN users mk ON mk.usrID = tr.trnMaker
             WHERE tr.trnReference = ?"
        );
        $stmt->execute([$ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed", "error" => "not_found"]);
            return;
        }

        if ((string)$row['makerName'] !== (string)$user) {
            $conn->rollBack();
            echo json_encode(["msg" => "invalid", "error" => "only_maker_can_act"]);
            return;
        }

        if ((int)$row['trnStatus'] !== 0 || $row['trnStateText'] !== 'Pending') {
            $conn->rollBack();
            echo json_encode([
                "msg"   => "invalid action",
                "error" => "only_pending_can_be_deleted",
            ]);
            return;
        }

        $usrDetails = $value->getUserDetails($user);
        $checkerID  = $usrDetails['usrID'];

        $conn->prepare("DELETE FROM trnDetails WHERE trdReference = ?")
             ->execute([$ref]);
        $conn->prepare(
            "UPDATE transactions
                SET trnStateText = 'Deleted', trnStatus = 0, trnAuthorizer = ?
              WHERE trnReference = ?"
        )->execute([$checkerID, $ref]);

        $conn->commit();
        echo json_encode(["msg" => "success", "stateText" => "Deleted"], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        $conn->rollBack();
        echo json_encode(["msg" => "failed", "error" => $th->getMessage()]);
    }
}
?>
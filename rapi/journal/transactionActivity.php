<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, PUT, DELETE, OPTIONS");
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

// Body — read once, use everywhere.
$data = json_decode(file_get_contents("php://input"), true);
if (!is_array($data)) $data = [];

// Dispatcher.
switch ($request_method) {
    case 'POST':
        reverse_transaction();
        break;

    case 'PUT':
        // PUT dispatches on `action`:
        //   { action: 'authorize'   }  → default
        //   { action: 'unauthorize' }
        $action = $data['action'] ?? 'authorize';
        switch ($action) {
            case 'authorize':
                auth_transaction();
                break;
            case 'unauthorize':
                unauth_transaction();
                break;
            default:
                http_response_code(400);
                echo json_encode([
                    "msg"   => "failed",
                    "error" => "unknown_put_action",
                ]);
                break;
        }
        break;

    case 'DELETE':
        delete_transaction();
        break;

    default:
        http_response_code(405);
        header('Allow: POST, PUT, DELETE, OPTIONS');
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

// ═════════════════════════════════════════════════════════════════
// Shared helper — maker check by usrName.
// Used by UNAUTHORIZE / REVERSE / DELETE.
// ═════════════════════════════════════════════════════════════════
function load_txn_if_maker($ref, $userName) {
    global $conn;

    if (!$ref || !$userName) {
        return [false, null, ["msg" => "failed", "error" => "missing_ref_or_user"]];
    }

    $stmt = $conn->prepare(
        "SELECT tr.*, mk.usrName AS makerName
         FROM transactions tr
         LEFT JOIN users mk ON mk.usrID = tr.trnMaker
         WHERE tr.trnReference = ?"
    );
    $stmt->execute([$ref]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [false, null, ["msg" => "failed", "error" => "not_found"]];
    }

    if ((string)$row['makerName'] !== (string)$userName) {
        return [false, null, [
            "msg"   => "invalid",
            "error" => "only_maker_can_act",
        ]];
    }

    return [true, $row, null];
}

// ═════════════════════════════════════════════════════════════════
// Authorization-only helper.
//   Loads the transaction, resolves the caller, and enforces:
//     • reference + usrName present
//     • transaction exists
//     • caller exists in users
//     • maker is not null (data integrity)
//     • caller is NOT the maker            → `same_user`
//     • caller is NOT the current authorizer (if already Authorized)
//   Returns [true, row, checkerID, null] on success,
//           [false, null, null, errorArray] on failure.
// ═════════════════════════════════════════════════════════════════
function load_txn_for_authorization($ref, $userName) {
    global $conn, $value;

    if (!$ref || !$userName) {
        return [false, null, null, ["msg" => "failed", "error" => "missing_ref_or_user"]];
    }

    // 1. Transaction must exist
    $stmt = $conn->prepare(
        "SELECT tr.*, mk.usrName AS makerName
         FROM transactions tr
         LEFT JOIN users mk ON mk.usrID = tr.trnMaker
         WHERE tr.trnReference = ?"
    );
    $stmt->execute([$ref]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return [false, null, null, ["msg" => "failed", "error" => "not_found"]];
    }

    // 2. Caller must exist
    $usrDetails = $value->getUserDetails($userName);
    $checkerID  = $usrDetails['usrID'] ?? null;
    if ($checkerID === null) {
        return [false, null, null, [
            "msg"   => "failed",
            "error" => "invalid_user",
            "message" => "Caller does not exist.",
        ]];
    }

    // 3. Maker must be set — otherwise we can't enforce maker≠checker
    $makerID = $row['trnMaker'] === null ? null : (int)$row['trnMaker'];
    if ($makerID === null || $makerID === 0) {
        return [false, null, null, [
            "msg"   => "failed",
            "error" => "missing_maker",
            "message" => "Transaction has no maker; cannot be authorized.",
        ]];
    }

    // 4. ✅ Maker ≠ Checker
    if ($makerID === (int)$checkerID) {
        return [false, null, null, [
            "msg"     => "failed",
            "error"   => "same_user",
            "message" => "A transaction cannot be authorized by its maker.",
        ]];
    }

    return [true, $row, (int)$checkerID, null];
}

// ═════════════════════════════════════════════════════════════════
// AUTHORIZE — PUT, action='authorize' (default)
//   Must be a *different* user than the maker.
//   Pending → Authorized.
// ═════════════════════════════════════════════════════════════════
function auth_transaction() {
    global $conn, $value;

    $data = json_decode(file_get_contents("php://input"), true);
    $ref  = $data['reference'] ?? null;
    $user = $data['username']  ?? null;

    [$ok, $row, $checkerID, $err] = load_txn_for_authorization($ref, $user);
    if (!$ok) {
        echo json_encode($err, JSON_PRETTY_PRINT);
        return;
    }

    // Only Pending may be authorized.
    if ((int)$row['trnStatus'] !== 0 || $row['trnStateText'] !== 'Pending') {
        echo json_encode([
            "msg"   => "invalid action",
            "error" => "only_pending_can_be_authorized",
        ], JSON_PRETTY_PRINT);
        return;
    }

    try {
        $conn->beginTransaction();

        $conn->prepare(
            "UPDATE transactions
                SET trnStatus = 1,
                    trnStateText = 'Authorized',
                    trnAuthorizer = ?
              WHERE trnReference = ?"
        )->execute([$checkerID, $ref]);

        $conn->commit();
        echo json_encode([
            "msg"        => "success",
            "state"      => "authorized",
            "reference"  => $ref,
            "stateText"  => "Authorized",
            "authorizer" => $checkerID,
        ], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ], JSON_PRETTY_PRINT);
    }
}

// ═════════════════════════════════════════════════════════════════
// UNAUTHORIZE — PUT, action='unauthorize'
//   Maker only. Authorized → Pending.
// ═════════════════════════════════════════════════════════════════
function unauth_transaction() {
    global $conn, $value;

    $data = json_decode(file_get_contents("php://input"), true);
    $ref  = $data['reference'] ?? null;
    $user = $data['username']  ?? null;

    [$ok, $row, $err] = load_txn_if_maker($ref, $user);
    if (!$ok) { echo json_encode($err); return; }

    // Reversed is terminal — must not be flipped back to Pending.
    if ($row['trnStateText'] === 'Reversed') {
        echo json_encode([
            "msg"   => "invalid action",
            "error" => "cannot_unauthorize_reversed",
        ]);
        return;
    }

    // Already Pending → nothing to do.
    if ((int)$row['trnStatus'] === 0 && $row['trnStateText'] === 'Pending') {
        echo json_encode([
            "msg"   => "failed",
            "error" => "already_pending",
        ]);
        return;
    }

    // Only Authorized can be unauthorized.
    if ((int)$row['trnStatus'] !== 1 || $row['trnStateText'] !== 'Authorized') {
        echo json_encode([
            "msg"   => "invalid action",
            "error" => "only_authorized_can_be_unauthorized",
        ]);
        return;
    }

    try {
        $conn->beginTransaction();

        $conn->prepare(
            "UPDATE transactions
                SET trnStatus = 0,
                    trnStateText = 'Pending',
                    trnAuthorizer = NULL
              WHERE trnReference = ?"
        )->execute([$ref]);

        $conn->commit();
        echo json_encode([
            "msg"       => "success",
            "reference" => $ref,
            "stateText" => "Pending",
        ], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ], JSON_PRETTY_PRINT);
    }
}

// ═════════════════════════════════════════════════════════════════
// REVERSE — POST
//   Maker only. Authorized → Reversed (inserts reversing lines).
// ═════════════════════════════════════════════════════════════════
function reverse_transaction() {
    global $conn, $value;

    $data = json_decode(file_get_contents("php://input"), true);
    $ref  = $data['reference'] ?? null;
    $user = $data['username']  ?? null;

    [$ok, $row, $err] = load_txn_if_maker($ref, $user);
    if (!$ok) { echo json_encode($err); return; }

    if ((int)$row['trnStatus'] !== 1 || $row['trnStateText'] !== 'Authorized') {
        echo json_encode([
            "msg"   => "invalid action",
            "error" => "only_authorized_can_be_reversed",
        ]);
        return;
    }

    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));
    $localDateTime = date("Y-m-d H:i:s");

    try {
        $conn->beginTransaction();

        $conn->prepare(
            "INSERT INTO trnDetails
                (trdReference, trdCcy, trdBranch, trdAccount, trdDrCr, trdAmount, trdNarration, trdEntryDate)
             SELECT trdReference, trdCcy, trdBranch, trdAccount, trdDrCr, -trdAmount,
                    CONCAT('Reversed: ', trdNarration), ?
             FROM trnDetails WHERE trdReference = ?"
        )->execute([$localDateTime, $ref]);

        $conn->prepare(
            "UPDATE transactions SET trnStateText = 'Reversed' WHERE trnReference = ?"
        )->execute([$ref]);

        $conn->commit();
        echo json_encode([
            "msg"       => "success",
            "reference" => $ref,
            "stateText" => "Reversed",
        ], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ], JSON_PRETTY_PRINT);
    }
}

// ═════════════════════════════════════════════════════════════════
// DELETE — DELETE
//   Maker only. Pending → Deleted (soft delete, keeps header).
// ═════════════════════════════════════════════════════════════════
function delete_transaction() {
    global $conn, $value;

    $data = json_decode(file_get_contents("php://input"), true);
    $ref  = $data['reference'] ?? null;
    $user = $data['username']  ?? null;

    [$ok, $row, $err] = load_txn_if_maker($ref, $user);
    if (!$ok) { echo json_encode($err); return; }

    if ((int)$row['trnStatus'] !== 0 || $row['trnStateText'] !== 'Pending') {
        echo json_encode([
            "msg"   => "invalid action",
            "error" => "only_pending_can_be_deleted",
        ]);
        return;
    }

    try {
        $conn->beginTransaction();

        $usrDetails = $value->getUserDetails($user);
        $checkerID  = $usrDetails['usrID'];
        $trnType    = $row['trnType'];

        if ($trnType === 'TRPT') {
            $conn->prepare("DELETE FROM shippingDetails WHERE shdTrnRef = ?")
                 ->execute([$ref]);
        }
        if ($trnType === 'SLRY') {
            $conn->prepare("DELETE FROM payroll WHERE prlTrnRef = ?")
                 ->execute([$ref]);
        }

        $conn->prepare("DELETE FROM trnDetails WHERE trdReference = ?")
             ->execute([$ref]);

        $conn->prepare(
            "UPDATE transactions
                SET trnStateText = 'Deleted', trnStatus = 0, trnAuthorizer = ?
              WHERE trnReference = ?"
        )->execute([$checkerID, $ref]);

        $conn->commit();
        echo json_encode([
            "msg"       => "success",
            "reference" => $ref,
            "stateText" => "Deleted",
        ], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ], JSON_PRETTY_PRINT);
    }
}
?>
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
    case 'GET':    get_fundTransfer();     break;
    case 'POST':   add_fundTransfer();     break;
    case 'PUT':    update_fundTransfer();  break;
    case 'DELETE': delete_fundTransfer();  break;
    default:
        http_response_code(405);
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

function body_json(): ?array {
    $raw = file_get_contents("php://input");
    if ($raw === false || trim($raw) === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

// ─────────────────────────────────────────────────────────────
// GET — fetch header + lines for edit (any status)
// ─────────────────────────────────────────────────────────────
function get_fundTransfer() {
    global $conn;

    $ref = $_GET['ref'] ?? null;
    if (!$ref) {
        echo json_encode(["msg" => "failed", "error" => "missing_ref"]);
        return;
    }

    try {
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
            http_response_code(404);
            echo json_encode(["msg" => "failed", "error" => "not_found"]);
            return;
        }

        $stmt1 = $conn->prepare(
            "SELECT trdCcy, trdBranch, trdAccount, trdDrCr,
                    trdAmount, trdNarration, trdEntryDate
             FROM trnDetails
             WHERE trdReference = ?
             ORDER BY trdDrCr DESC, trdAccount ASC"
        );
        $stmt1->execute([$ref]);
        $lines = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
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
        ], JSON_PRETTY_PRINT);

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

// ─────────────────────────────────────────────────────────────
// POST — create
// ─────────────────────────────────────────────────────────────
function add_fundTransfer() {
    global $conn, $value;

    $data = body_json();
    if (!$data || !isset($data['usrName']) || !isset($data['records'])) {
        echo json_encode(["msg" => "failed", "error" => "invalid_json"]);
        return;
    }

    $user    = $data['usrName'];
    $records = $data['records'];

    if (!is_array($records) || count($records) === 0) {
        echo json_encode(["msg" => "empty"]);
        return;
    }

    $type          = "BULK";
    $status        = 0;
    $stateText     = "Pending";
    $entryDateTime = date("Y-m-d H:i:s");

    try {
        // ── Everything that can throw is now inside try/catch ─────
        $userResult = $value->getUserDetails($user);
        if (!is_array($userResult) || empty($userResult['usrBranch']) || empty($userResult['usrID'])) {
            echo json_encode(["msg" => "failed", "error" => "invalid_user"]);
            return;
        }
        $branch = $userResult['usrBranch'];
        $usrID  = $userResult['usrID'];

        $trnRef       = $value->generateTrnRef($branch, $type);
        $currencies   = [];
        $totalDebit   = 0;
        $totalCredit  = 0;

        foreach ($records as $i => $record) {
            $account = $record['account'] ?? null;
            $ccy     = $record['ccy']     ?? null;
            $debit   = (float)($record['debit']  ?? 0);
            $credit  = (float)($record['credit'] ?? 0);

            if (!$account || !$ccy) {
                echo json_encode(["msg" => "empty", "row" => $i]);
                return;
            }
            if ($debit < 0 || $credit < 0) {
                echo json_encode(["msg" => "failed", "error" => "negative_amount", "row" => $i]);
                return;
            }
            if ($debit > 0 && $credit > 0) {
                echo json_encode(["msg" => "failed", "error" => "mixed_amount", "row" => $i]);
                return;
            }

            $currencies[$ccy] = true;

            // ✅ Defensive currency check.
            //    getAccountDetails() may return a string, an array, or null
            //    depending on the helper's implementation — normalise it.
            $accountCcyRaw = $value->getAccountDetails($account, 'actCurrency');
            $accountCcy = null;
            if (is_string($accountCcyRaw)) {
                $accountCcy = $accountCcyRaw;
            } elseif (is_array($accountCcyRaw)) {
                // If it returned a row, grab the first scalar value.
                foreach ($accountCcyRaw as $v) {
                    if (is_string($v) && $v !== '') { $accountCcy = $v; break; }
                }
            }

            if ($accountCcy !== null && strcasecmp($accountCcy, (string)$ccy) !== 0) {
                // Multi-ccy GL accounts (e.g. 10101010) have one row per
                // currency; getAccountDetails() returns an arbitrary one.
                // Accept the ccy if the account actually has a row in it.
                $chk = $conn->prepare(
                    "SELECT 1 FROM accounts
                      WHERE accNumber = ? AND accCurrency = ?
                      LIMIT 1"
                );
                $chk->execute([$account, $ccy]);
                if (!$chk->fetchColumn()) {
                    echo json_encode([
                        "msg"        => "diff ccy",
                        "error"      => "account currency mismatch",
                        "row"        => $i,
                        "account"    => $account,
                        "accountCcy" => $accountCcy,
                        "rowCcy"     => $ccy,
                    ], JSON_PRETTY_PRINT);
                    return;
                }
            }

            if ($debit > 0) {
                $limit = $value->checkForAccountLimit($account, $debit);
                if ($limit != 1) {
                    echo json_encode(["msg" => "no limit", "account" => $account], JSON_PRETTY_PRINT);
                    return;
                }
            }

            if ($account < 10000000) {
                $accStatus = $value->getAccountDetails($account, 'actStatus');
                // Same normalisation for status.
                if (is_array($accStatus)) {
                    $accStatus = reset($accStatus);
                }
                if ((int)$accStatus === 0) {
                    echo json_encode(["msg" => "blocked", "account" => $account], JSON_PRETTY_PRINT);
                    return;
                }
            }

            $totalDebit  += $debit;
            $totalCredit += $credit;
        }

        if (count($currencies) > 1) {
            echo json_encode(["msg" => "diff ccy"], JSON_PRETTY_PRINT);
            return;
        }
        if (abs($totalDebit - $totalCredit) > 0.01) {
            echo json_encode([
                "msg"         => "failed",
                "error"       => "unbalanced",
                "totalDebit"  => $totalDebit,
                "totalCredit" => $totalCredit,
            ], JSON_PRETTY_PRINT);
            return;
        }

        $limit = $value->getBranchAuthLimit($branch, array_key_first($currencies));
        if ($totalDebit <= $limit) {
            $authUser  = $usrID;
            $stateText = "Authorized";
            $status    = 1;
        } else {
            $authUser  = NULL;
            $stateText = "Pending";
            $status    = 0;
        }

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

        foreach ($records as $rec) {
            $acc    = $rec['account'];
            $ccy    = $rec['ccy'];
            $debit  = (float)$rec['debit'];
            $credit = (float)$rec['credit'];
            $remark = $rec['narration'] ?? '';

            if ($debit > 0) {
                $stmt1->execute([$trnRef, $ccy, $branch, $acc, "Dr", $debit, $remark, $entryDateTime]);
            } else {
                $stmt1->execute([$trnRef, $ccy, $branch, $acc, "Cr", $credit, $remark, $entryDateTime]);
            }
        }

        $conn->commit();
        echo json_encode([
            "msg"       => "success",
            "reference" => $trnRef,
        ], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        // Only rollBack if a transaction is actually open.
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ─────────────────────────────────────────────────────────────
// PUT — update
//   Rules:
//     • Only the maker can edit.
//     • Only Pending can be edited.
//   Authorized → Unauthorize first, then edit.
// ─────────────────────────────────────────────────────────────
function update_fundTransfer() {
    global $conn, $value;

    $data = body_json();
    if (!$data) {
        echo json_encode(["msg" => "failed", "error" => "invalid_json"]);
        return;
    }

    $ref     = $data['reference'] ?? null;
    $user    = $data['usrName']   ?? null;
    $records = $data['records']   ?? null;

    if (!$ref || !$user || !is_array($records) || count($records) === 0) {
        echo json_encode(["msg" => "empty"]);
        return;
    }

    $entryDateTime = date("Y-m-d H:i:s");

    try {
        $conn->beginTransaction();

        // 1. Header exists?
        $stmt0 = $conn->prepare("SELECT * FROM transactions WHERE trnReference = ?");
        $stmt0->execute([$ref]);
        $header = $stmt0->fetch(PDO::FETCH_ASSOC);

        if (!$header) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed", "error" => "not_found"]);
            return;
        }

        // 2. Only the maker can edit.
        $usrDetails = $value->getUserDetails($user);
        if (!is_array($usrDetails) || empty($usrDetails['usrID'])) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed", "error" => "invalid_user"]);
            return;
        }
        $checkerID = $usrDetails['usrID'];
        if ((int)$header['trnMaker'] !== (int)$checkerID) {
            $conn->rollBack();
            echo json_encode(["msg" => "invalid user"]);
            return;
        }

        // 3. Only Pending may be edited.
        if ((int)$header['trnStatus'] !== 0 || $header['trnStateText'] !== 'Pending') {
            $conn->rollBack();
            echo json_encode([
                "msg"   => "invalid action",
                "error" => "only_pending_can_be_edited",
            ]);
            return;
        }

        // 4. Re-validate every row.
        $currencies  = [];
        $totalDebit  = 0;
        $totalCredit = 0;
        foreach ($records as $i => $record) {
            $account = $record['account'] ?? null;
            $ccy     = $record['ccy']     ?? null;
            $debit   = (float)($record['debit']  ?? 0);
            $credit  = (float)($record['credit'] ?? 0);

            if (!$account || !$ccy) {
                $conn->rollBack();
                echo json_encode(["msg" => "empty", "row" => $i]);
                return;
            }
            if ($debit > 0 && $credit > 0) {
                $conn->rollBack();
                echo json_encode(["msg" => "failed", "error" => "mixed_amount", "row" => $i]);
                return;
            }

            $currencies[$ccy] = true;

            // ✅ Defensive currency check (same normalisation as add).
            $accountCcyRaw = $value->getAccountDetails($account, 'actCurrency');
            $accountCcy = null;
            if (is_string($accountCcyRaw)) {
                $accountCcy = $accountCcyRaw;
            } elseif (is_array($accountCcyRaw)) {
                foreach ($accountCcyRaw as $v) {
                    if (is_string($v) && $v !== '') { $accountCcy = $v; break; }
                }
            }

            if ($accountCcy !== null && strcasecmp($accountCcy, (string)$ccy) !== 0) {
                $chk = $conn->prepare(
                    "SELECT 1 FROM accounts
                      WHERE accNumber = ? AND accCurrency = ?
                      LIMIT 1"
                );
                $chk->execute([$account, $ccy]);
                if (!$chk->fetchColumn()) {
                    $conn->rollBack();
                    echo json_encode([
                        "msg"        => "diff ccy",
                        "error"      => "account currency mismatch",
                        "row"        => $i,
                        "account"    => $account,
                        "accountCcy" => $accountCcy,
                        "rowCcy"     => $ccy,
                    ], JSON_PRETTY_PRINT);
                    return;
                }
            }

            $totalDebit  += $debit;
            $totalCredit += $credit;
        }

        if (count($currencies) > 1) {
            $conn->rollBack();
            echo json_encode(["msg" => "diff ccy"]);
            return;
        }
        if (abs($totalDebit - $totalCredit) > 0.01) {
            $conn->rollBack();
            echo json_encode([
                "msg"         => "failed",
                "error"       => "unbalanced",
                "totalDebit"  => $totalDebit,
                "totalCredit" => $totalCredit,
            ], JSON_PRETTY_PRINT);
            return;
        }

        // 5. Recompute authorisation status based on new total.
        $branch = $usrDetails['usrBranch'] ?? $header['trnBranch'] ?? null;
        $limit  = $value->getBranchAuthLimit($branch, array_key_first($currencies));
        if ($totalDebit <= $limit) {
            $authUser  = $checkerID;
            $stateText = "Authorized";
            $status    = 1;
        } else {
            $authUser  = NULL;
            $stateText = "Pending";
            $status    = 0;
        }

        // 6. Update header.
        $stmt1 = $conn->prepare(
            "UPDATE transactions
                SET trnStatus = ?, trnStateText = ?, trnAuthorizer = ?, trnEntryDate = ?
              WHERE trnReference = ?"
        );
        $stmt1->execute([$status, $stateText, $authUser, $entryDateTime, $ref]);

        // 7. Wipe old details and re-insert.
        $conn->prepare("DELETE FROM trnDetails WHERE trdReference = ?")
             ->execute([$ref]);

        $stmt2 = $conn->prepare(
            "INSERT INTO trnDetails
                (trdReference, trdCcy, trdBranch, trdAccount, trdDrCr, trdAmount, trdNarration, trdEntryDate)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        foreach ($records as $rec) {
            $acc    = $rec['account'];
            $ccy    = $rec['ccy'];
            $debit  = (float)$rec['debit'];
            $credit = (float)$rec['credit'];
            $remark = $rec['narration'] ?? '';

            if ($debit > 0) {
                $stmt2->execute([$ref, $ccy, $branch, $acc, "Dr", $debit, $remark, $entryDateTime]);
            } else {
                $stmt2->execute([$ref, $ccy, $branch, $acc, "Cr", $credit, $remark, $entryDateTime]);
            }
        }

        $conn->commit();
        echo json_encode([
            "msg"       => "success",
            "reference" => $ref,
        ], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ─────────────────────────────────────────────────────────────
// DELETE — hard delete
//   Rules:
//     • Only the maker can delete.
//     • Only Pending can be deleted.
// ─────────────────────────────────────────────────────────────
function delete_fundTransfer() {
    global $conn, $value;

    // Accept query string OR body
    $ref  = $_GET['ref']     ?? $_POST['ref']     ?? null;
    $user = $_GET['usrName'] ?? $_POST['usrName'] ?? null;

    if (!$ref || !$user) {
        $data = body_json();
        if ($data) {
            $ref  = $ref  ?: ($data['reference'] ?? null);
            $user = $user ?: ($data['usrName']   ?? null);
        }
    }

    if (!$ref || !$user) {
        echo json_encode(["msg" => "failed", "error" => "missing_ref_or_user"]);
        return;
    }

    try {
        $conn->beginTransaction();

        // 1. Header exists?
        $stmt0 = $conn->prepare("SELECT * FROM transactions WHERE trnReference = ?");
        $stmt0->execute([$ref]);
        $header = $stmt0->fetch(PDO::FETCH_ASSOC);

        if (!$header) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed", "error" => "not_found"]);
            return;
        }

        // 2. Only the maker can delete.
        $usrDetails = $value->getUserDetails($user);
        $checkerID  = $usrDetails['usrID'];
        if ((int)$header['trnMaker'] !== (int)$checkerID) {
            $conn->rollBack();
            echo json_encode(["msg" => "invalid user"]);
            return;
        }

        // 3. Only Pending may be deleted.
        if ((int)$header['trnStatus'] !== 0 || $header['trnStateText'] !== 'Pending') {
            $conn->rollBack();
            echo json_encode([
                "msg"   => "invalid action",
                "error" => "only_pending_can_be_deleted",
            ]);
            return;
        }

        // 4. Delete lines first (FK child), then header.
        $delDetails = $conn->prepare("DELETE FROM trnDetails WHERE trdReference = ?");
        $delDetails->execute([$ref]);
        $deletedLines = $delDetails->rowCount();

        $delHeader = $conn->prepare("DELETE FROM transactions WHERE trnReference = ?");
        $delHeader->execute([$ref]);
        $deletedHeaders = $delHeader->rowCount();

        if ($deletedHeaders === 0) {
            $conn->rollBack();
            echo json_encode(["msg" => "failed", "error" => "header_not_deleted"]);
            return;
        }

        $conn->commit();
        echo json_encode([
            "msg"           => "success",
            "reference"     => $ref,
            "deletedLines"  => $deletedLines,
            "deletedHeader" => $deletedHeaders,
        ], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}
?>
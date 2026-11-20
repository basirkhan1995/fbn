<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once "../db.php";
require_once "../functions.php";

$db = new Database();
$conn = $db->getConnection();
$value = new DataValues($conn);
$data = json_decode(file_get_contents("php://input"), true);
$request_method = $_SERVER["REQUEST_METHOD"];

switch ($request_method) {
    case 'GET':
        get_order_txn();
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method Not Allowed"));
        break;
}

function get_order_txn() {
    global $conn;

    $ref = $_GET['ref'] ?? null;
    if (!$ref) {
        http_response_code(400);
        echo json_encode(["message" => "Missing ref parameter"]);
        return;
    }

    try {
        // Company's local currency, used as fallback for GL / NULL currencies
        $lcStmt = $conn->prepare("SELECT comLocalCcy FROM companyProfile LIMIT 1");
        $lcStmt->execute();
        $localCcy = $lcStmt->fetchColumn() ?: 'USD';

        $stmt = $conn->prepare("
            SELECT
                tr.trnReference,
                tr.trnType,
                tp.trntName,
                tr.trnStatus,
                tr.trnStateText,
                mk.usrName AS maker,
                ck.usrName AS checker,
                tr.trnEntryDate,
                ROUND((SUM(td.trdAmount)/2), 2) AS total_bill,
                MAX(CASE WHEN td.trdID THEN COALESCE(td.trdCcy, :localCcy) END)      AS ccy,
                MAX(CASE WHEN td.trdID THEN COALESCE(cy.ccySymbol, lcy.ccySymbol) END) AS ccy_symbol,
                MAX(CASE WHEN td.trdID THEN COALESCE(cy.ccyName,   lcy.ccyName)   END) AS ccy_name,
                MAX(CASE WHEN td.trdID THEN br.brcName END)          AS branch,
                MAX(CASE WHEN td.trdID THEN td.trdNarration END)     AS remark
            FROM transactions tr
            LEFT JOIN users      mk  ON mk.usrID     = tr.trnMaker
            LEFT JOIN users      ck  ON ck.usrID     = tr.trnAuthorizer
            LEFT JOIN trnTypes   tp  ON tp.trntCode  = tr.trnType
            LEFT JOIN trnDetails td  ON td.trdReference = tr.trnReference
            LEFT JOIN currency   cy  ON cy.ccyCode   = td.trdCcy
            LEFT JOIN currency   lcy ON lcy.ccyCode  = :localCcy
            LEFT JOIN branch     br  ON br.brcID     = td.trdBranch
            WHERE tr.trnReference = :ref
        ");
        $stmt->execute([':localCcy' => $localCcy, ':ref' => $ref]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt1 = $conn->prepare("
            SELECT
                ac.accName AS account_name,
                td.trdAccount AS account_number,
                td.trdAmount AS amount,
                CASE WHEN td.trdDrCr = 'Dr' THEN 'Debit' ELSE 'Credit' END AS debit_credit,
                COALESCE(td.trdCcy, :localCcy) AS currency
            FROM trnDetails td
            JOIN accounts ac ON ac.accNumber = td.trdAccount
            WHERE td.trdReference = :ref
        ");
        $stmt1->execute([':localCcy' => $localCcy, ':ref' => $ref]);
        $data['records'] = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($data, JSON_PRETTY_PRINT);
    }
    catch (PDOException $th) {
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile()
        ]);
    }
}


?>
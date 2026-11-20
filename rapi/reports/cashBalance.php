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
        get_cashBalance();
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method Not Allowed"));
        break;
}

function get_cashBalance() {
    global $conn, $value;
    
    $localCcy = $value->getCompanyAttributes("comLocalCcy");
    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));
    
    try {
        $branchID = isset($_GET['brcID']) && !empty($_GET['brcID']) ? $_GET['brcID'] : null;
        
        // Get branch info
        if ($branchID) {
            $stmt1 = $conn->prepare("SELECT b.*, concat(a.addName, ' ', a.addCity, ' ', a.addProvince, ' ', a.addCountry, ' ', a.addZipCode) as address
                FROM branch b left join address a on a.addID = b.brcAddress where brcID = ?");
            $stmt1->execute([$branchID]);
        } else {
            $stmt1 = $conn->prepare("SELECT b.*, concat(a.addName, ' ', a.addCity, ' ', a.addProvince, ' ', a.addCountry, ' ', a.addZipCode) as address
                FROM branch b left join address a on a.addID = b.brcAddress");
            $stmt1->execute();
        }
        $branches = $stmt1->fetchAll(PDO::FETCH_ASSOC);
        
        // Prepare the response
        $response = [];
        $todayDate = date("Y-m-d");
        
        foreach ($branches as $brc) {
            // Get balances - FIXED: Use unique parameter names or bind properly
            $stmt3 = $conn->prepare("
                SELECT ac.accName, td.trdAccount, cy.ccyName, td.trdCcy, cy.ccySymbol,
                    SUM(CASE WHEN tr.trnStatus=1 AND DATE(td.trdEntryDate) <= DATE_SUB(:dt, INTERVAL 1 DAY) THEN 
                            CASE WHEN td.trdDrCr='Dr' THEN trdAmount ELSE -trdAmount END 
                        ELSE 0 END) as opening_balance,
                    SUM(CASE WHEN tr.trnStatus=1 AND DATE(td.trdEntryDate) <= DATE_SUB(:dt, INTERVAL 1 DAY) THEN
                            CASE WHEN td.trdDrCr='Dr' THEN trdAmount*getRate(td.trdCcy, :lccy1) ELSE -trdAmount*getRate(td.trdCcy, :lccy1) END
                        ELSE 0 END) as opening_sys_equivalent,
                    SUM(CASE WHEN tr.trnStatus=1 THEN 
                            CASE WHEN td.trdDrCr='Cr' THEN -trdAmount ELSE trdAmount END
                        ELSE 0 END) as closing_balance,
                    SUM(CASE WHEN tr.trnStatus=1 THEN 
                            CASE WHEN td.trdDrCr='Dr' THEN trdAmount*getRate(td.trdCcy, :lccy2) ELSE -trdAmount*getRate(td.trdCcy, :lccy2) END
                        ELSE 0 END) as closing_sys_equivalent
                FROM trnDetails td
                JOIN transactions tr ON tr.trnReference = td.trdReference
                JOIN currency cy ON cy.ccyCode = td.trdCcy
                JOIN accounts ac ON ac.accNumber = td.trdAccount
                WHERE td.trdAccount = 10101010 AND td.trdBranch = :brcID
                GROUP BY ac.accName, td.trdAccount, cy.ccyName, td.trdCcy, cy.ccySymbol
            ");
            
            $stmt3->execute([
                ':dt' => $todayDate,
                ':lccy1' => $localCcy,
                ':lccy2' => $localCcy,
                ':brcID' => $brc['brcID']
            ]);
            $brc['records'] = $stmt3->fetchAll(PDO::FETCH_ASSOC);
            
            // Get transactions - FIXED: Use proper parameter binding
            $stmt2 = $conn->prepare("
                SELECT 
                    DATE(td.trdEntryDate) AS date,
                    td.trdNarration AS narration,
                    CASE WHEN td.trdDrCr = 'Dr' THEN td.trdAmount ELSE 0 END AS debit,
                    CASE WHEN td.trdDrCr = 'Cr' THEN td.trdAmount ELSE 0 END AS credit,
                    td.trdCcy AS currency,
                    td.trdReference AS reference,
                    other_acc.accName AS credit_account_name,
                    other_acc.accNumber AS credit_account_number,
                    t.trnType AS transaction_type,
                    t.trnStateText AS status,
                    td.trdDrCr AS debit_credit,
                    td.trdAmount AS amount
                FROM trnDetails td
                JOIN transactions t ON t.trnReference = td.trdReference
                LEFT JOIN trnDetails other_td ON other_td.trdReference = td.trdReference 
                    AND other_td.trdID != td.trdID
                    AND other_td.trdDrCr != td.trdDrCr
                LEFT JOIN accounts other_acc ON other_acc.accNumber = other_td.trdAccount
                WHERE td.trdAccount = 10101010 
                    AND td.trdBranch = :brcID
                    AND t.trnStatus = 1
                ORDER BY td.trdEntryDate ASC, td.trdID ASC
            ");
            
            $stmt2->execute([':brcID' => $brc['brcID']]);
            $transactions = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            
            // Calculate running balance per currency
            $runningBalances = [];
            foreach ($transactions as &$txn) {
                $currency = $txn['currency'];
                if (!isset($runningBalances[$currency])) {
                    $runningBalances[$currency] = 0;
                }
                
                // Update running balance
                $amount = floatval($txn['amount']);
                if ($txn['debit_credit'] == 'Dr') {
                    $runningBalances[$currency] += $amount;
                } else {
                    $runningBalances[$currency] -= $amount;
                }
                $txn['running_balance'] = number_format($runningBalances[$currency], 4, '.', '');
                
                // Clean up - remove unnecessary fields
                unset($txn['debit_credit']);
                unset($txn['amount']);
            }
            
            $brc['transactions'] = $transactions;
            $response[] = $brc;
        }
        
        echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
    } catch (PDOException $th) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Failed to retrieve cash transactions',
            'error' => $th->getMessage(),
            'line' => $th->getLine(),
            'file' => $th->getFile()
        ], JSON_PRETTY_PRINT);
    }
}

?>
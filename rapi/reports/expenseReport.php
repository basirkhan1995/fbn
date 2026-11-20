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

$request_method = $_SERVER["REQUEST_METHOD"];
if ($request_method !== 'POST') {
    http_response_code(405);
    echo json_encode(array("message" => "Method Not Allowed. Use POST method."));
    exit;
}

// Get data from body
$input = json_decode(file_get_contents("php://input"), true);
if (!$input) {
    // If no input, return last 30 days
    get_default_report();
    exit;
}

$date_from = isset($input['date_from']) ? $input['date_from'] : null;
$date_to = isset($input['date_to']) ? $input['date_to'] : null;
$account_number = isset($input['account_number']) ? (int)$input['account_number'] : null;
$currency = isset($input['currency']) ? $input['currency'] : null;
$expense_category = isset($input['expense_category']) ? $input['expense_category'] : null;
$branch = isset($input['branch']) ? $input['branch'] : null;
$status = isset($input['status']) ? $input['status'] : null;
$limit = isset($input['limit']) ? (int)$input['limit'] : 1000;
$offset = isset($input['offset']) ? (int)$input['offset'] : 0;

try {
    $sql = "SELECT 
                transaction_date,
                reference,
                account_number,
                account_name,
                expense_category,
                currency,
                expense_amount,
                usd_equivalent,
                narration,
                branch,
                maker,
                status
            FROM vw_expenseReport
            WHERE 1=1";
    
    $params = [];
    $whereConditions = [];

    if ($date_from && $date_to) {
        $whereConditions[] = "transaction_date BETWEEN :date_from AND :date_to";
        $params[':date_from'] = $date_from;
        $params[':date_to'] = $date_to;
    } elseif ($date_from) {
        $whereConditions[] = "transaction_date >= :date_from";
        $params[':date_from'] = $date_from;
    } elseif ($date_to) {
        $whereConditions[] = "transaction_date <= :date_to";
        $params[':date_to'] = $date_to;
    } else {
        $whereConditions[] = "transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    }

    // Apply account filter (expense account number)
    if (!empty($account_number)) {
        $whereConditions[] = "account_number = :account_number";
        $params[':account_number'] = $account_number;
    }

    // Apply currency filter
    if (!empty($currency)) {
        $whereConditions[] = "currency = :currency";
        $params[':currency'] = $currency;
    }

    // Apply expense category filter
    if (!empty($expense_category)) {
        $whereConditions[] = "expense_category = :expense_category";
        $params[':expense_category'] = $expense_category;
    }

    // Apply branch filter
    if (!empty($branch)) {
        $whereConditions[] = "branch = :branch";
        $params[':branch'] = $branch;
    }

    // Apply status filter
    if (!empty($status)) {
        $whereConditions[] = "status = :status";
        $params[':status'] = $status;
    }

    if (!empty($whereConditions)) {
        $sql .= " AND " . implode(" AND ", $whereConditions);
    }

    $sql .= " ORDER BY transaction_date DESC, reference DESC";
    $sql .= " LIMIT :limit OFFSET :offset";
    $params[':limit'] = $limit;
    $params[':offset'] = $offset;

    $stmt = $conn->prepare($sql);
    
    foreach ($params as $key => $value) {
        if (is_int($value)) {
            $stmt->bindValue($key, $value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value, PDO::PARAM_STR);
        }
    }
    
    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get summary (always included)
    $summary = get_expense_summary($whereConditions, $params);

    // Prepare response with data and summary only
    $response = [
        "data" => $expenses,
        "summary" => $summary
    ];

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (PDOException $th) {
    echo json_encode([
        "error" => true,
        "message" => "Database error",
        "details" => $th->getMessage()
    ]);
}

function get_expense_summary($whereConditions, $params) {
    global $conn;
    
    try {
        $sql = "SELECT 
                    currency,
                    COUNT(*) as transaction_count,
                    SUM(expense_amount) as total_amount,
                    ROUND(SUM(usd_equivalent), 4) as total_usd,
                    MIN(expense_amount) as min_amount,
                    MAX(expense_amount) as max_amount,
                    ROUND(AVG(expense_amount), 4) as avg_amount
                FROM vw_expenseReport
                WHERE 1=1";
        
        if (!empty($whereConditions)) {
            $sql .= " AND " . implode(" AND ", $whereConditions);
        }
        
        $sql .= " GROUP BY currency";
        
        $stmt = $conn->prepare($sql);
        foreach ($params as $key => $value) {
            if ($key !== ':limit' && $key !== ':offset') {
                if (is_int($value)) {
                    $stmt->bindValue($key, $value, PDO::PARAM_INT);
                } else {
                    $stmt->bindValue($key, $value, PDO::PARAM_STR);
                }
            }
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $th) {
        return ["error" => $th->getMessage()];
    }
}

function get_default_report() {
    global $conn;
    
    try {
        $sql = "SELECT 
                    transaction_date,
                    reference,
                    account_number,
                    account_name,
                    expense_category,
                    currency,
                    expense_amount,
                    usd_equivalent,
                    narration,
                    branch,
                    maker,
                    status
                FROM vw_expenseReport
                WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                ORDER BY transaction_date DESC, reference DESC
                LIMIT 1000";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute();
        $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get summary for default report
        $summarySql = "SELECT 
                            currency,
                            COUNT(*) as transaction_count,
                            SUM(expense_amount) as total_amount,
                            ROUND(SUM(usd_equivalent), 4) as total_usd,
                            MIN(expense_amount) as min_amount,
                            MAX(expense_amount) as max_amount,
                            ROUND(AVG(expense_amount), 4) as avg_amount
                        FROM vw_expenseReport
                        WHERE transaction_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                        GROUP BY currency";
        
        $summaryStmt = $conn->prepare($summarySql);
        $summaryStmt->execute();
        $summary = $summaryStmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            "data" => $expenses,
            "summary" => $summary
        ];

        echo json_encode($response, JSON_PRETTY_PRINT);

    } catch (PDOException $th) {
        echo json_encode([
            "error" => true,
            "message" => "Database error",
            "details" => $th->getMessage()
        ]);
    }
}

?>
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
    case 'POST':
        get_stock_report();
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method Not Allowed"));
        break;
}

function get_stock_report() {
    global $conn;

    $input = json_decode(file_get_contents("php://input"), true);
    if (!$input) exit;
    
    $product = (INT)$input['product'];
    $storage = (INT)$input['storage'];
    $category = (INT)$input['category'];
    $availability = $input['availability'];
    $lsq = (INT)$input['lsq']; // 0 = All products, 1 = Low stock alert only
    $salesFilter = $input['salesFilter'] ?? '';

    $zero = "0";

    try {
        $sql = "SELECT ROW_NUMBER() OVER (ORDER BY proID ASC) AS No,
            proID, proName, proCode, stgName, proUnit, proLSNqty, recent_PurPrice, 
            available, stkQtyInBatch as batch, available_Item, average_price, sell_price, 
            (available_Item*average_price) as total_value,
            COALESCE(sales.total_sold, 0) as total_sold,
            COALESCE(sales.total_sales_amount, 0) as total_sales_amount
            FROM vw_availableProducts vp
            LEFT JOIN (
                SELECT stkProduct, 
                       SUM(stkQuantity * stkQtyInBatch) as total_sold,
                       SUM(stkQuantity * stkQtyInBatch * stkSalePrice) as total_sales_amount
                FROM stock
                WHERE stkEntryType = 'OUT'
                GROUP BY stkProduct
            ) sales ON sales.stkProduct = vp.proID
            WHERE proCode != :zero";

        $params = [':zero' => $zero];

        // Apply filters (these apply to ALL queries)
        if (!empty($product)) {
            $sql .= " AND proID = :pro ";
            $params[':pro'] = $product;
        }

        if (!empty($storage)) {
            $sql .= " AND stkStorage = :stg";
            $params[':stg'] = $storage;
        }
        if (!empty($category)) {
            $sql .= " AND proCategory = :cat";
            $params[':cat'] = $category;
        }
        
        if ($availability == 1) {
            $sql .= " AND available > :av";
            $params[':av'] = 0;
        } elseif ($availability == 2) {
            $sql .= " AND available <= :av";
            $params[':av'] = 0;
        }
        
        // ✅ LSQ FILTER - ONLY applies when lsq = 1
        // Shows products where alert level is set AND stock is at or below alert level
        if ($lsq == 1) {
            $sql .= " AND proLSNqty > 1 AND available <= proLSNqty";
        }
        // When lsq = 0, NO extra condition is added - shows ALL products

        // Sales-based filters
        if ($salesFilter == 'most_sold') {
            $sql .= " HAVING total_sold > 0 AND total_sold >= (SELECT COALESCE(AVG(total_sold), 0) FROM (SELECT SUM(stkQuantity * stkQtyInBatch) as total_sold FROM stock WHERE stkEntryType = 'OUT' GROUP BY stkProduct) as avg_sales)";
        } elseif ($salesFilter == 'not_sold') {
            $sql .= " HAVING total_sold = 0";
        } elseif ($salesFilter == 'less_sold') {
            $sql .= " HAVING total_sold > 0 AND total_sold < (SELECT COALESCE(AVG(total_sold), 0) FROM (SELECT SUM(stkQuantity * stkQtyInBatch) as total_sold FROM stock WHERE stkEntryType = 'OUT' GROUP BY stkProduct) as avg_sales)";
        }

        // ORDER BY
        if ($salesFilter == 'most_sold') {
            $sql .= " ORDER BY total_sold DESC, proName ASC";
        } elseif ($salesFilter == 'less_sold') {
            $sql .= " ORDER BY total_sold ASC, proName ASC";
        } else {
            $sql .= " ORDER BY proName ASC";
        }

        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($data, JSON_PRETTY_PRINT);
    } catch (PDOException $th) {
        echo json_encode([
            "msg" => "failed",
            "error" => $th->getMessage(),
            "line" => $th->getLine(),
            "file" => $th->getFile()
        ]);
    }
}

?>
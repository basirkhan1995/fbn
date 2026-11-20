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
        load_orders();
        break;
    case 'PUT':
        change_orders_status();
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method Not Allowed"));
        break;
}

function load_orders() {
    global $conn;
    try {
        if (isset($_GET['ordID']) && !empty($_GET['ordID'])) {
            $ordID = $_GET['ordID'];
            // Use SQL for single record
            $sql = "SELECT o.ordID, o.ordName, p.perID, CONCAT(p.perName, ' ', p.perLastName) AS personal, o.ordxRef, o.ordTrnRef, o.ordBranch, b.brcName, st.totalBill, st.benifit, o.ordStatus, o.ordEntryDate FROM orders o
LEFT JOIN (SELECT stkOrder,
        SUM(CASE WHEN stkEntryType = 'IN' THEN (stkQuantity * stkQtyInBatch) * stkPurPrice ELSE (stkQuantity * stkQtyInBatch) * stkSalePrice END) AS totalBill,
        SUM(CASE WHEN stkEntryType = 'OUT' THEN ((stkQuantity * stkQtyInBatch) * stkSalePrice) - ((stkQuantity * stkQtyInBatch) * stkLandedPurPrice) ELSE 0 END) AS benifit
    FROM stock
    GROUP BY stkOrder
) st ON st.stkOrder = o.ordID
LEFT JOIN personal p ON p.perID = o.ordPersonal
LEFT JOIN transactions t ON t.trnReference = o.ordTrnRef
LEFT JOIN trnDetails td ON td.trdReference = t.trnReference
JOIN branch b ON b.brcID = o.ordBranch
WHERE o.ordName IN ('Sale', 'Purchase')
AND (
    o.ordStatus = 'Pending'
    OR ( o.ordStatus IN ('Authorized', 'Deleted') AND DATE(o.ordEntryDate) BETWEEN DATE_SUB(CURDATE(), INTERVAL 1 DAY) AND DATE_ADD(CURDATE(), INTERVAL 1 DAY))
)
and ordID = :id
GROUP BY o.ordID, o.ordName, p.perID, personal, o.ordxRef, o.ordTrnRef, o.ordBranch, b.brcName, o.ordStatus, o.ordEntryDate, st.totalBill, st.benifit ORDER BY o.ordID DESC;";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $ordID, PDO::PARAM_INT);
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

        } else {
            // Use default SQL for all records
            $sql = "SELECT o.ordID, o.ordName, p.perID, CONCAT(p.perName, ' ', p.perLastName) AS personal, o.ordxRef, o.ordTrnRef, o.ordBranch, b.brcName, st.totalBill, st.benifit, o.ordStatus, o.ordEntryDate FROM orders o
LEFT JOIN (SELECT stkOrder,
        SUM(CASE WHEN stkEntryType = 'IN' THEN (stkQuantity * stkQtyInBatch) * stkPurPrice ELSE (stkQuantity * stkQtyInBatch) * stkSalePrice END) AS totalBill,
        SUM(CASE WHEN stkEntryType = 'OUT' THEN ((stkQuantity * stkQtyInBatch) * stkSalePrice) - ((stkQuantity * stkQtyInBatch) * stkLandedPurPrice) ELSE 0 END) AS benifit
    FROM stock
    GROUP BY stkOrder
) st ON st.stkOrder = o.ordID
LEFT JOIN personal p ON p.perID = o.ordPersonal
LEFT JOIN transactions t ON t.trnReference = o.ordTrnRef
LEFT JOIN trnDetails td ON td.trdReference = t.trnReference
JOIN branch b ON b.brcID = o.ordBranch
WHERE o.ordName IN ('Sale', 'Purchase')
AND (
    o.ordStatus = 'Pending'
    OR (
        o.ordStatus IN ('Authorized', 'Deleted')
        AND o.ordEntryDate >= CURDATE()
        AND o.ordEntryDate < CURDATE() + INTERVAL 1 DAY
    )
)
GROUP BY o.ordID, o.ordName, p.perID, personal, o.ordxRef, o.ordTrnRef, o.ordBranch, b.brcName, o.ordStatus, o.ordEntryDate, st.totalBill, st.benifit ORDER BY o.ordID DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        echo json_encode($data, JSON_PRETTY_PRINT);
    } 
    catch (PDOException $th) {
        echo json_encode([
            "msg" => "failed",
            "error" => $th->getMessage(),
            "line" => $th->getLine(),
            "file" => $th->getFile()
        ]);
    }
}

function change_orders_status() {
    global $conn;
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        echo json_encode([
            "msg" => "failed",
            "error" => "Invalid input data"
        ]);
        return;
    }
    try {
        $conn->beginTransaction();
        $sql = "UPDATE orders SET ordStatus = :st WHERE ordID = :id";
        $stmt = $conn->prepare($sql);

        foreach ($data as $row) {
            $status = $row['ordStatus'];
            $ordID = $row['ordID'];
            $stmt->bindParam(':st', $status, PDO::PARAM_STR);
            $stmt->bindParam(':id', $ordID, PDO::PARAM_INT);
            $stmt->execute();
        }
        $conn->commit();
        echo json_encode([
            "msg" => "success",
            "message" => "Order status updated successfully"
        ]);
    } catch (PDOException $th) {
        $conn->rollBack();
        echo json_encode([
            "msg" => "failed",
            "error" => $th->getMessage(),
            "line" => $th->getLine(),
            "file" => $th->getFile()
        ]);
    }
}

?>
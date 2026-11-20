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
        get_product();
        break;
    case 'POST':
        add_product();
        break;
    case 'PUT':
        update_product();
        break;
    case 'DELETE':
        delete_product();
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method Not Allowed"));
        break;
}

// function get_product() {
//     global $conn;
//     try {
//         if (isset($_GET['proID']) && !empty($_GET['proID'])) {
//             $stmtProBatch = $conn->prepare("
//                 SELECT 
//                 s.stkStorage as storage_id,
//                 st.stgName as storage_name,
//                 stkQtyInBatch as batch,
//                 sum(case when stkEntryType='IN' then stkQuantity else -stkQuantity end) as available_Quantity
//                 from stock s
//                 LEFT JOIN storages st ON s.stkStorage = st.stgID
//                 where stkProduct = ?
//                 group by stkProduct, stkStorage, stkQtyInBatch
//                 having available_Quantity <> 0");
//             $stmtProBatch->execute([$_GET['proID']]);
//             $productBatches = $stmtProBatch->fetchAll(PDO::FETCH_ASSOC);
            
//             // Use SQL for single record
//             $sql = "SELECT 
//                         p.*, 
//                         pc.*,
//                         COALESCE(SUM(CASE WHEN s.stkEntryType='IN' THEN s.stkQuantity ELSE -s.stkQuantity END), 0) AS total_quantity,
//                         COALESCE(SUM(CASE WHEN s.stkEntryType='IN' THEN s.stkQuantity * s.stkQtyInBatch ELSE -s.stkQuantity * s.stkQtyInBatch END), 0) AS total_items
//                     FROM product p 
//                     JOIN productCategory pc ON p.proCategory = pc.pcID
//                     LEFT JOIN stock s ON s.stkProduct = p.proID
//                     WHERE p.proID = :id
//                     GROUP BY p.proID";
//             $stmt = $conn->prepare($sql);
//             $stmt->bindParam(':id', $_GET['proID'], PDO::PARAM_INT);
//             $stmt->execute();
//             $data = $stmt->fetch(PDO::FETCH_ASSOC);
//             $data['batches'] = $productBatches ?? [];
        
//         } elseif(isset($_GET['input']) && !empty($_GET['input'])) {
//             $input = trim($_GET['input']);
//             $search = "%{$input}%";
//             // Search with stock information
//             $sql = "SELECT 
//                         p.*, 
//                         pc.*,
//                         COALESCE(SUM(CASE WHEN s.stkEntryType='IN' THEN s.stkQuantity ELSE -s.stkQuantity END), 0) AS total_quantity,
//                         COALESCE(SUM(CASE WHEN s.stkEntryType='IN' THEN s.stkQuantity * s.stkQtyInBatch ELSE -s.stkQuantity * s.stkQtyInBatch END), 0) AS total_items
//                     FROM product p 
//                     JOIN productCategory pc ON p.proCategory = pc.pcID
//                     LEFT JOIN stock s ON s.stkProduct = p.proID
//                     WHERE p.proCode LIKE :search OR p.proName LIKE :search
//                     GROUP BY p.proID
//                     ORDER BY p.proID DESC";
//             $stmt = $conn->prepare($sql);
//             $stmt->bindValue(':search', $search, PDO::PARAM_STR);
//             $stmt->execute();
//             $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
//         } else {
//             // Get all products with stock summary
//             $sql = "SELECT 
//                         p.*, 
//                         pc.*,
//                         COALESCE(SUM(CASE WHEN s.stkEntryType='IN' THEN s.stkQuantity ELSE -s.stkQuantity END), 0) AS total_quantity,
//                         COALESCE(SUM(CASE WHEN s.stkEntryType='IN' THEN s.stkQuantity * s.stkQtyInBatch ELSE -s.stkQuantity * s.stkQtyInBatch END), 0) AS total_items
//                     FROM product p 
//                     JOIN productCategory pc ON p.proCategory = pc.pcID
//                     LEFT JOIN stock s ON s.stkProduct = p.proID
//                     GROUP BY p.proID
//                     ORDER BY p.proID DESC";
//             $stmt = $conn->prepare($sql);
//             $stmt->execute();
//             $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
//         }
        
//         echo json_encode($data, JSON_PRETTY_PRINT);
//     } 
//     catch (PDOException $th) {
//         echo json_encode([
//             "msg" => "failed",
//             "error" => $th->getMessage(),
//             "line" => $th->getLine(),
//             "file" => $th->getFile()
//         ]);
//     }
// }


function get_product() {
    global $conn;

    try {

        /*
         * Recent 15 purchase transactions per product.
         * Only IN stock entries connected to purchase orders.
         */
        $recentPurchasesCte = <<<'SQL'
WITH recent_purchases AS (
    SELECT
        r.stkProduct AS proID,

        JSON_ARRAYAGG(
            JSON_OBJECT(
                'date', DATE_FORMAT(
                    r.ordEntryDate,
                    '%Y-%m-%d %H:%i:%s'
                ),
                'customer', r.supplierName,
                'currency', r.baseCurrency,
                'order_id', CAST(r.ordID AS SIGNED),
                'quantity', r.stkQuantity * r.stkQtyInBatch,
                'batch_qty', r.stkQtyInBatch,
                'order_type', 'Purchase',
                'unit_price', r.stkPurPrice
            )
        ) AS recent_txn

    FROM (
        SELECT
            s.stkProduct,
            s.stkQuantity,
            s.stkQtyInBatch,
            s.stkPurPrice,

            o.ordID,
            o.ordEntryDate,

            TRIM(CONCAT(
                COALESCE(p.perName, ''),
                ' ',
                COALESCE(p.perLastName, '')
            )) AS supplierName,

            cp.comLocalCcy AS baseCurrency,

            ROW_NUMBER() OVER (
                PARTITION BY s.stkProduct
                ORDER BY
                    o.ordEntryDate DESC,
                    s.stkID DESC
            ) AS rn

        FROM stock s

        INNER JOIN orders o
            ON o.ordID = s.stkOrder

        LEFT JOIN personal p
            ON p.perID = o.ordPersonal

        CROSS JOIN (
            SELECT comLocalCcy
            FROM companyProfile
            LIMIT 1
        ) cp

        WHERE
            s.stkEntryType = 'IN'
            AND s.stkOrder IS NOT NULL

    ) r

    WHERE r.rn <= 15

    GROUP BY r.stkProduct
)
SQL;

        /*
         * Product ID: Get one product with batches.
         */
        if (isset($_GET['proID']) && !empty($_GET['proID'])) {

            // Get product batches by storage.
            $stmtProBatch = $conn->prepare("
                SELECT
                    s.stkStorage AS storage_id,
                    st.stgName AS storage_name,
                    s.stkQtyInBatch AS batch,

                    SUM(
                        CASE
                            WHEN s.stkEntryType = 'IN'
                            THEN s.stkQuantity
                            ELSE -s.stkQuantity
                        END
                    ) AS available_Quantity

                FROM stock s

                LEFT JOIN storages st
                    ON s.stkStorage = st.stgID

                WHERE s.stkProduct = ?

                GROUP BY
                    s.stkProduct,
                    s.stkStorage,
                    s.stkQtyInBatch

                HAVING available_Quantity <> 0
            ");

            $stmtProBatch->execute([$_GET['proID']]);

            $productBatches = $stmtProBatch->fetchAll(
                PDO::FETCH_ASSOC
            );

            // Get product, stock summary and recent purchases.
            $sql = $recentPurchasesCte . "

                SELECT
                    p.*,
                    pc.*,

                    COALESCE(ss.total_quantity, 0) AS total_quantity,
                    COALESCE(ss.total_items, 0) AS total_items,

                    COALESCE(
                        rp.recent_txn,
                        JSON_ARRAY()
                    ) AS recent_txn

                FROM product p

                INNER JOIN productCategory pc
                    ON p.proCategory = pc.pcID

                LEFT JOIN (
                    SELECT
                        stkProduct,

                        SUM(
                            CASE
                                WHEN stkEntryType = 'IN'
                                THEN stkQuantity
                                ELSE -stkQuantity
                            END
                        ) AS total_quantity,

                        SUM(
                            CASE
                                WHEN stkEntryType = 'IN'
                                THEN stkQuantity * stkQtyInBatch
                                ELSE -stkQuantity * stkQtyInBatch
                            END
                        ) AS total_items

                    FROM stock
                    GROUP BY stkProduct
                ) ss
                    ON ss.stkProduct = p.proID

                LEFT JOIN recent_purchases rp
                    ON rp.proID = p.proID

                WHERE p.proID = :id
            ";

            $stmt = $conn->prepare($sql);

            $stmt->bindValue(
                ':id',
                (int)$_GET['proID'],
                PDO::PARAM_INT
            );

            $stmt->execute();

            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data) {

                // Convert MySQL JSON string into a PHP array.
                $data['recent_txn'] = json_decode(
                    $data['recent_txn'] ?? '[]',
                    true
                );

                if (!is_array($data['recent_txn'])) {
                    $data['recent_txn'] = [];
                }

                $data['batches'] = $productBatches ?? [];

            } else {
                $data = null;
            }

        }

        /*
         * Search products by code or name.
         */
        elseif (isset($_GET['input']) && !empty($_GET['input'])) {

            $input = trim($_GET['input']);
            $search = "%{$input}%";

            $sql = $recentPurchasesCte . "

                SELECT
                    p.*,
                    pc.*,

                    COALESCE(ss.total_quantity, 0) AS total_quantity,
                    COALESCE(ss.total_items, 0) AS total_items,

                    COALESCE(
                        rp.recent_txn,
                        JSON_ARRAY()
                    ) AS recent_txn

                FROM product p

                INNER JOIN productCategory pc
                    ON p.proCategory = pc.pcID

                LEFT JOIN (
                    SELECT
                        stkProduct,

                        SUM(
                            CASE
                                WHEN stkEntryType = 'IN'
                                THEN stkQuantity
                                ELSE -stkQuantity
                            END
                        ) AS total_quantity,

                        SUM(
                            CASE
                                WHEN stkEntryType = 'IN'
                                THEN stkQuantity * stkQtyInBatch
                                ELSE -stkQuantity * stkQtyInBatch
                            END
                        ) AS total_items

                    FROM stock
                    GROUP BY stkProduct
                ) ss
                    ON ss.stkProduct = p.proID

                LEFT JOIN recent_purchases rp
                    ON rp.proID = p.proID

                WHERE
                    p.proCode LIKE :search
                    OR p.proName LIKE :search

                ORDER BY p.proID DESC
            ";

            $stmt = $conn->prepare($sql);

            $stmt->bindValue(
                ':search',
                $search,
                PDO::PARAM_STR
            );

            $stmt->execute();

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode recent transactions for Flutter.
            foreach ($data as &$product) {

                $product['recent_txn'] = json_decode(
                    $product['recent_txn'] ?? '[]',
                    true
                );

                if (!is_array($product['recent_txn'])) {
                    $product['recent_txn'] = [];
                }

            }

            unset($product);

        }

        /*
         * Get all products.
         */
        else {

            $sql = $recentPurchasesCte . "

                SELECT
                    p.*,
                    pc.*,

                    COALESCE(ss.total_quantity, 0) AS total_quantity,
                    COALESCE(ss.total_items, 0) AS total_items,

                    COALESCE(
                        rp.recent_txn,
                        JSON_ARRAY()
                    ) AS recent_txn

                FROM product p

                INNER JOIN productCategory pc
                    ON p.proCategory = pc.pcID

                LEFT JOIN (
                    SELECT
                        stkProduct,

                        SUM(
                            CASE
                                WHEN stkEntryType = 'IN'
                                THEN stkQuantity
                                ELSE -stkQuantity
                            END
                        ) AS total_quantity,

                        SUM(
                            CASE
                                WHEN stkEntryType = 'IN'
                                THEN stkQuantity * stkQtyInBatch
                                ELSE -stkQuantity * stkQtyInBatch
                            END
                        ) AS total_items

                    FROM stock
                    GROUP BY stkProduct
                ) ss
                    ON ss.stkProduct = p.proID

                LEFT JOIN recent_purchases rp
                    ON rp.proID = p.proID

                ORDER BY p.proID DESC
            ";

            $stmt = $conn->prepare($sql);
            $stmt->execute();

            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Decode recent transactions for Flutter.
            foreach ($data as &$product) {

                $product['recent_txn'] = json_decode(
                    $product['recent_txn'] ?? '[]',
                    true
                );

                if (!is_array($product['recent_txn'])) {
                    $product['recent_txn'] = [];
                }

            }

            unset($product);
        }

        // Return JSON response.
        echo json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        );

    } catch (Throwable $th) {

        http_response_code(500);

        echo json_encode([
            "msg" => "failed",
            "error" => $th->getMessage(),
            "line" => $th->getLine(),
            "file" => $th->getFile()
        ]);

    }
}


function add_product() {
    global $conn;
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode([
            "msg" => "Invalid or missing JSON payload",
            "raw" => $raw
        ]);
        exit;
    }

    $name = $data['proName'];
    $code = $data['proCode'];
    $unit = $data['proUnit'];
    $brand = $data['proBrand'];
    $madeIn = $data['proMadeIn'];
    $model = $data['proModel'];
    $grade = $data['proGrade'];
    $category= $data['proCategory'];
    $details = $data['proDetails'];
    $lsnQty = $data['proLSNQty'];
    $color = $data['proColor'];
    $width = $data['proWidth'];
    $length = $data['proLength'];
    $weight = $data['proWeight'];
    $breadth = $data['proBreadth'];
    $spp = $data['proSPP'];
    $status = $data['proStatus'];

    try {

        $stmt = $conn->prepare("SELECT count(*) from product where proCode = ? or proName = ?");
        $stmt->execute([$code, $name]);
        $count = $stmt->fetchColumn();
        if($count > 0){
             echo json_encode(["msg" => "exist", "count" => $count]);
             exit;
        }

        $conn->beginTransaction();

        $stmt1 = $conn->prepare("INSERT into product 
        (proName, proCode, proUnit, proBrand, proMadeIn, proModel, proGrade, proCategory, proDetails, proLSNQty, proColor, proStatus, proWidth, proLength, proWeight, proBreadth, proSPP) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt1->execute([$name, $code, $unit, $brand, $madeIn, $model, $grade, $category, $details, $lsnQty, $color, $status, $width, $length, $weight, $breadth, $spp]);
        
        $conn->commit();
        echo json_encode(["msg" => "success"], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        $conn->rollBack();
        echo json_encode([
            "msg" => "failed",
            "error" => $th->getMessage(),
            "line" => $th->getLine(),
            "file" => $th->getFile()
        ]);
    }
}

function update_product(){
    global $conn;

    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    $data = json_decode(file_get_contents("php://input"), true);
    $id = $data['proID'];
    $code = $data['proCode'];
    $category= $data['proCategory'];
    $name = $data['proName'];
    $unit = $data['proUnit'];
    $brand = $data['proBrand'];
    $madeIn = $data['proMadeIn'];
    $model = $data['proModel'];
    $grade = $data['proGrade'];
    $details = $data['proDetails'];
    $lsnQty = $data['proLSNQty'];
    $color = $data['proColor'];
    $width = $data['proWidth'];
    $length = $data['proLength'];
    $weight = $data['proWeight'];
    $breadth = $data['proBreadth'];
    $spp = $data['proSPP'];
    $status = $data['proStatus'];

    try {
        $conn->beginTransaction();

        $stmt1 = $conn->prepare("UPDATE product SET proCategory=?, proName=?, proUnit=?, proBrand=?, proMadeIn=?, proModel=?, proGrade=?, proDetails=?, proCode=?, proLSNQty=?, proColor=?, proStatus=?, proWidth=?, proLength=?, proWeight=?, proBreadth=?, proSPP=? WHERE proID=?");
        $stmt1->execute([$category, $name, $unit, $brand, $madeIn, $model, $grade, $details, $code, $lsnQty, $color, $status, $width, $length, $weight, $breadth, $spp, $id]);

        $conn->commit();
        echo json_encode(["msg" => "success"]);

    } catch (\Throwable $th) {
        $conn->rollBack();
        echo json_encode([
            "msg" => "failed",
            "error" => $th->getMessage(),
            "line" => $th->getLine(),
            "file" => $th->getFile(),
            "trace" => $th->getTrace()
        ]);
    }
}

function delete_product(){
    global $conn;

    error_reporting(E_ALL);
    ini_set('display_errors', 1);
    $data = json_decode(file_get_contents("php://input"), true);

    $id = $data['proID'];

    try {       
        $stmt = $conn->prepare("SELECT count(*) from stock where stkProduct = ?");
        $stmt->execute([$id]);
        $count = $stmt->fetchColumn();
        if($count > 0){
             echo json_encode(["msg" => "dependent"]);
             exit;
        }

        $conn->beginTransaction();

        $stmt1 = $conn->prepare("DELETE from product WHERE proID=?");
        $stmt1->execute([$id]);

        $conn->commit();
        echo json_encode(["msg" => "success"]);

    } catch (\Throwable $th) {
        $conn->rollBack();
        echo json_encode([
            "msg" => "failed",
            "error" => $th->getMessage(),
            "line" => $th->getLine(),
            "file" => $th->getFile(),
            "trace" => $th->getTrace()
        ]);
    }
}
?>
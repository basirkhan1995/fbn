<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE");
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
        get_shifting();
        break;
    case 'POST':
        create_shifting();
        break;
    case 'PUT':
        update_shifting();
        break;
    case 'DELETE':
        delete_shifting();
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method Not Allowed"));
        break;
}

// ==================================================================
// GET — list or single shift (only Pending)
// ==================================================================
function get_shifting() {
    global $conn;
    try {
        // ── Optional filters ─────────────────────────────────────
        $status   = isset($_GET['status']) && $_GET['status'] !== ''
                    ? trim($_GET['status'])
                    : 'Pending';
        $fromDate = isset($_GET['from']) && $_GET['from'] !== ''
                    ? trim($_GET['from'])
                    : null;
        $toDate   = isset($_GET['to']) && $_GET['to'] !== ''
                    ? trim($_GET['to'])
                    : null;

        // Whitelist status so we don't accidentally inject anything
        $allowedStatus = ['Pending', 'Authorized', 'Deleted', 'All'];
        if (!in_array($status, $allowedStatus, true)) {
            $status = 'Pending';
        }

        // Validate date format if present
        $dateRegex = '/^\d{4}-\d{2}-\d{2}$/';
        if ($fromDate !== null && !preg_match($dateRegex, $fromDate)) {
            $fromDate = null;
        }
        if ($toDate !== null && !preg_match($dateRegex, $toDate)) {
            $toDate = null;
        }

        // ── Single shift ─────────────────────────────────────────
        if (isset($_GET['ordID']) && !empty($_GET['ordID'])) {
            $shpID = $_GET['ordID'];

            $sql = "SELECT o.ordID, o.ordName, o.ordStatus AS trnStateText, o.ordEntryDate
                    FROM orders o
                    JOIN stock s ON s.stkOrder = o.ordID
                    WHERE o.ordID = :id
                      AND o.ordName = 'Shifting'";

            // status filter (All → skip)
            if ($status !== 'All') {
                $sql .= " AND o.ordStatus = :st";
            }

            // date filters
            if ($fromDate !== null) {
                $sql .= " AND DATE(o.ordEntryDate) >= :fromDate";
            }
            if ($toDate !== null) {
                $sql .= " AND DATE(o.ordEntryDate) <= :toDate";
            }

            $sql .= " GROUP BY o.ordID, o.ordName, o.ordStatus, o.ordEntryDate";

            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $shpID, PDO::PARAM_INT);
            if ($status !== 'All') {
                $stmt->bindParam(':st', $status, PDO::PARAM_STR);
            }
            if ($fromDate !== null) {
                $stmt->bindParam(':fromDate', $fromDate, PDO::PARAM_STR);
            }
            if ($toDate !== null) {
                $stmt->bindParam(':toDate', $toDate, PDO::PARAM_STR);
            }
            $stmt->execute();
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$data) {
                echo json_encode(["msg" => "not found"], JSON_PRETTY_PRINT);
                exit;
            }

            $stmt1 = $conn->prepare("
                SELECT stkID, stkProduct, p.proName, k.stkEntryType, stkStorage,
                       s.stgName, k.stkQuantity, k.stkQtyInBatch,
                       k.stkPurPrice, k.stkLandedPurPrice
                FROM stock k
                JOIN product  p ON p.proID = k.stkProduct
                JOIN storages s ON s.stgID = k.stkStorage
                WHERE k.stkOrder = ?");
            $stmt1->execute([$_GET['ordID']]);
            $data['records'] = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        // ── List ─────────────────────────────────────────────────
        } else {
            $sql = "SELECT o.ordID, o.ordName, o.ordStatus AS trnStateText, o.ordEntryDate
                    FROM orders o
                    JOIN stock s ON s.stkOrder = o.ordID
                    WHERE o.ordName = 'Shifting'";

            if ($status !== 'All') {
                $sql .= " AND o.ordStatus = :st";
            }
            if ($fromDate !== null) {
                $sql .= " AND DATE(o.ordEntryDate) >= :fromDate";
            }
            if ($toDate !== null) {
                $sql .= " AND DATE(o.ordEntryDate) <= :toDate";
            }

            $sql .= " GROUP BY o.ordID, o.ordName, o.ordStatus, o.ordEntryDate
                      ORDER BY o.ordID DESC";

            $stmt = $conn->prepare($sql);
            if ($status !== 'All') {
                $stmt->bindParam(':st', $status, PDO::PARAM_STR);
            }
            if ($fromDate !== null) {
                $stmt->bindParam(':fromDate', $fromDate, PDO::PARAM_STR);
            }
            if ($toDate !== null) {
                $stmt->bindParam(':toDate', $toDate, PDO::PARAM_STR);
            }
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

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

// ==================================================================
// POST — create shift
// ==================================================================
function create_shifting() {
    global $conn, $value;
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data) exit;
    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));

    $oName = "Shifting";
    $perID = NULL;
    $xRef  = NULL;

    $user    = $data['usrName'];
    $records = $data['records'];

    $entryDateTime = date("Y-m-d H:i:s");
    $userResult    = $value->getUserDetails($user);
    $branch        = $userResult['usrBranch'];
    $usrID         = $userResult['usrID'];

    if (empty($records)) {
        echo json_encode(["msg" => "no items"], JSON_PRETTY_PRINT);
        exit;
    }

    // Validate from/to storage
    foreach ($records as $record) {
        if (($record['fromStorage'] ?? 0) == ($record['toStorage'] ?? 0)) {
            echo json_encode([
                "msg"      => "invalid_storage",
                "specific" => "From and To storage cannot be the same"
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }

    try {
        $conn->beginTransaction();

        // Check stock availability
        foreach ($records as $record) {
            $proID       = $record['stkProduct'];
            $fromStorage = $record['fromStorage'];
            $qty         = $record['stkQuantity'];
            $qtyInBatch  = $record['stkQtyInBatch'];

            $stmtCheck = $conn->prepare("
                SELECT SUM(CASE WHEN stkEntryType='IN' THEN stkQuantity ELSE -stkQuantity END) AS available
                FROM stock
                WHERE stkProduct = ? AND stkStorage = ? AND stkQtyInBatch = ?");
            $stmtCheck->execute([$proID, $fromStorage, $qtyInBatch]);
            $av = $stmtCheck->fetchColumn() ?? 0;

            if ($qty > $av) {
                $conn->rollBack();
                echo json_encode([
                    "msg"      => "not enough",
                    "specific" => "product:$proID, storage:$fromStorage, available: $av"
                ], JSON_PRETTY_PRINT);
                exit;
            }
        }

        // Insert order (status = Pending, no transaction)
        $stmt1 = $conn->prepare("
            INSERT INTO orders (ordPersonal, ordName, ordxRef, ordBranch, ordEntryDate, ordStatus)
            VALUES (?,?,?,?,?,'Pending')");
        $stmt1->execute([$perID, $oName, $xRef, $branch, $entryDateTime]);
        $ordID = $conn->lastInsertId();

        // Insert stock rows (OUT + IN per line)
        $stmt2 = $conn->prepare("
            INSERT INTO stock
              (stkOrder, stkProduct, stkEntryType, stkStorage, stkQuantity,
               stkQtyInBatch, stkPurPrice, stkLandedPurPrice, stkDiscount, stkSalePrice)
            VALUES (?,?,?,?,?,?,?,?,0,0)");

        foreach ($records as $record) {
            $proID       = $record['stkProduct'];
            $fromStorage = $record['fromStorage'];
            $toStorage   = $record['toStorage'];
            $qty         = $record['stkQuantity'];
            $qtyInBatch  = $record['stkQtyInBatch'];
            $pPrice      = $record['stkPurPrice'] ?? 0;
            $lpPrice     = $record['stkLandedPurPrice'] ?? 0;

            $stmt2->execute([$ordID, $proID, 'OUT', $fromStorage,
                round($qty, 8), round($qtyInBatch, 8), round($pPrice, 8), round($lpPrice, 8)]);

            $stmt2->execute([$ordID, $proID, 'IN', $toStorage,
                round($qty, 8), round($qtyInBatch, 8), round($pPrice, 8), round($lpPrice, 8)]);
        }

        $value->generateUserActivityLog(
            $user,
            $oName,
            "Created - Order ID: $ordID, Items: " . count($records)
        );

        $conn->commit();
        echo json_encode(["msg" => "success", "ordID" => $ordID], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile()
        ]);
    }
}

// ==================================================================
// PUT — update shift (only Pending)
// ==================================================================
function update_shifting() {
    global $conn, $value;
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data || !isset($data['usrName'], $data['ordID'])) {
        echo json_encode(["msg" => "Missing required fields"]);
        exit;
    }

    date_default_timezone_set($value->getCompanyAttributes('comTimeZone'));

    $user    = $data['usrName'];
    $ordID   = $data['ordID'];
    $records = $data['records'] ?? [];

    if (empty($records)) {
        echo json_encode(["msg" => "no items"]);
        exit;
    }

    // Validate from/to storage
    foreach ($records as $record) {
        if (($record['fromStorage'] ?? 0) == ($record['toStorage'] ?? 0)) {
            echo json_encode([
                "msg"      => "invalid_storage",
                "specific" => "From and To storage cannot be the same"
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }

    try {
        $conn->beginTransaction();

        // Confirm order exists and is Pending
        $stmtCheck = $conn->prepare("
            SELECT ordStatus FROM orders
            WHERE ordID = ? AND ordName = 'Shifting'");
        $stmtCheck->execute([$ordID]);
        $order = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $conn->rollBack();
            echo json_encode(["msg" => "Order not found"]);
            exit;
        }
        if ($order['ordStatus'] !== 'Pending') {
            $conn->rollBack();
            echo json_encode(["msg" => "Cannot update a shift that is not Pending"]);
            exit;
        }

        // Snapshot old stock so availability check can account for it
        $stmtGetOldStock = $conn->prepare("
            SELECT stkProduct, stkStorage, stkEntryType, stkQuantity, stkQtyInBatch
            FROM stock WHERE stkOrder = ?");
        $stmtGetOldStock->execute([$ordID]);
        $oldStock = $stmtGetOldStock->fetchAll(PDO::FETCH_ASSOC);

        $oldStockByProduct = [];
        foreach ($oldStock as $item) {
            $key = $item['stkProduct'] . '_' . $item['stkStorage'] . '_' . $item['stkQtyInBatch'];
            if (!isset($oldStockByProduct[$key])) {
                $oldStockByProduct[$key] = ['in_qty' => 0, 'out_qty' => 0];
            }
            if ($item['stkEntryType'] === 'IN') {
                $oldStockByProduct[$key]['in_qty'] += $item['stkQuantity'];
            } elseif ($item['stkEntryType'] === 'OUT') {
                $oldStockByProduct[$key]['out_qty'] += $item['stkQuantity'];
            }
        }

        // Remove old stock lines
        $stmtDelStock = $conn->prepare("DELETE FROM stock WHERE stkOrder = ?");
        $stmtDelStock->execute([$ordID]);

        // Availability check (current + what we just removed from source)
        foreach ($records as $record) {
            $proID       = $record['stkProduct'];
            $fromStorage = $record['fromStorage'];
            $qty         = $record['stkQuantity'];
            $qtyInBatch  = $record['stkQtyInBatch'];

            $oldKey    = $proID . '_' . $fromStorage . '_' . $qtyInBatch;
            $oldOutQty = $oldStockByProduct[$oldKey]['out_qty'] ?? 0;

            $stmtCheckStock = $conn->prepare("
                SELECT SUM(CASE WHEN stkEntryType='IN' THEN stkQuantity ELSE -stkQuantity END) AS available
                FROM stock
                WHERE stkProduct = ? AND stkStorage = ? AND stkQtyInBatch = ?");
            $stmtCheckStock->execute([$proID, $fromStorage, $qtyInBatch]);
            $currentAv = $stmtCheckStock->fetchColumn() ?? 0;

            $available = $currentAv + $oldOutQty;

            if ($qty > $available) {
                $conn->rollBack();
                echo json_encode([
                    "msg"      => "not enough",
                    "specific" => "product:$proID, storage:$fromStorage, available: $available, requested: $qty"
                ], JSON_PRETTY_PRINT);
                exit;
            }
        }

        // Insert new stock lines
        $stmtInsertStock = $conn->prepare("
            INSERT INTO stock
              (stkOrder, stkProduct, stkEntryType, stkStorage, stkQuantity,
               stkQtyInBatch, stkPurPrice, stkLandedPurPrice, stkDiscount, stkSalePrice)
            VALUES (?,?,?,?,?,?,?,?,0,0)");

        foreach ($records as $record) {
            $proID       = $record['stkProduct'];
            $fromStorage = $record['fromStorage'];
            $toStorage   = $record['toStorage'];
            $qty         = $record['stkQuantity'];
            $qtyInBatch  = $record['stkQtyInBatch'];
            $pPrice      = $record['stkPurPrice'] ?? 0;
            $lpPrice     = $record['stkLandedPurPrice'] ?? 0;

            $stmtInsertStock->execute([$ordID, $proID, 'OUT', $fromStorage,
                round($qty, 8), round($qtyInBatch, 8), round($pPrice, 8), round($lpPrice, 8)]);

            $stmtInsertStock->execute([$ordID, $proID, 'IN', $toStorage,
                round($qty, 8), round($qtyInBatch, 8), round($pPrice, 8), round($lpPrice, 8)]);
        }

        $value->generateUserActivityLog(
            $user,
            "Shifting",
            "Updated - Order ID: $ordID, Items: " . count($records)
        );

        $conn->commit();
        echo json_encode(["msg" => "success"], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile()
        ]);
    }
}

// ==================================================================
// DELETE — remove shift (only Pending)
// ==================================================================
function delete_shifting() {
    global $conn, $value;
    $data = json_decode(file_get_contents("php://input"), true);
    if (!$data || !isset($data['usrName'], $data['ordID'])) {
        echo json_encode(["msg" => "no data"]);
        exit;
    }

    $user  = $data['usrName'];
    $ordID = (int) $data['ordID'];

    try {
        $conn->beginTransaction();

        // Confirm order exists, is a Shifting, and is Pending
        $stmt0 = $conn->prepare("
            SELECT ordID, ordName, ordStatus
            FROM orders
            WHERE ordID = ? AND ordName = 'Shifting'");
        $stmt0->execute([$ordID]);
        $order = $stmt0->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $conn->rollBack();
            echo json_encode(["msg" => "no order"], JSON_PRETTY_PRINT);
            exit;
        }

        if ($order['ordStatus'] !== 'Pending') {
            $conn->rollBack();
            echo json_encode(["msg" => "Cannot delete a shift that is not Pending"]);
            exit;
        }

        // Delete stock rows first (FK → orders.ordID)
        $stmtDelStock = $conn->prepare("DELETE FROM stock WHERE stkOrder = ?");
        $stmtDelStock->execute([$ordID]);

        // Delete the order
        $stmtDelOrder = $conn->prepare("DELETE FROM orders WHERE ordID = ?");
        $stmtDelOrder->execute([$ordID]);

        $value->generateUserActivityLog(
            $user,
            $order['ordName'],
            "Deleted - Order ID: $ordID"
        );

        $conn->commit();
        echo json_encode(["msg" => "success"], JSON_PRETTY_PRINT);

    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile()
        ]);
    }
}
?>
<?php
/**
 * salePurchase.php
 * Handles create / read / update / delete for purchase and sale orders.
 * FIFO inventory: COGS = pure purchase price (batPurPrice × items).
 * Landed price is stored for reporting only and is never used in COGS/GL.
 * PHP 8.0+
 */

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

set_exception_handler(function (Throwable $e): void {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(
        [
            'msg'   => 'failed',
            'error' => $e->getMessage(),
            'line'  => $e->getLine(),
            'file'  => $e->getFile(),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
});

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

$db    = new Database();
$conn  = $db->getConnection();
$value = new DataValues($conn);

$rawBody       = file_get_contents('php://input');
$data          = $rawBody !== '' ? json_decode($rawBody, true) : null;
$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function jsonOut(array $payload, int $code = 200): never
{
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );
    exit;
}

function jsonFail(string $message, int $code = 400, array $extra = []): never
{
    jsonOut(['msg' => 'failed', 'error' => $message] + $extra, $code);
}

try {
    switch ($requestMethod) {
        case 'GET':
            get_order($conn);
            break;
        case 'POST':
            create_order($conn, $value, $data);
            break;
        case 'PUT':
            update_order($conn, $value, $data);
            break;
        case 'DELETE':
            delete_order($conn, $value, $data);
            break;
        default:
            jsonFail('Method Not Allowed', 405);
    }
} catch (PDOException $e) {
    jsonFail($e->getMessage(), 500, [
        'line' => $e->getLine(),
        'file' => $e->getFile(),
        'sql'  => $e->errorInfo[2] ?? null,
    ]);
} catch (Throwable $e) {
    jsonFail($e->getMessage(), 500, [
        'line' => $e->getLine(),
        'file' => $e->getFile(),
    ]);
}

/* ═══════════════════════════════════════════════════════════════════════════
 * FIFO ALLOCATOR
 * Returns per-batch splits in total items. COGS is derived from 'pur' (pure),
 * never from 'landed'. Landed is returned for the stock snapshot only.
 * ═══════════════════════════════════════════════════════════════════════════ */
function fifoAllocate(PDO $conn, int $proID, int $stgID, float $needItems): array
{
    $stmt = $conn->prepare(
        "SELECT batID, batRemainingItems, batPurPrice, batLandedPurPrice
           FROM batch
          WHERE batProduct = :p AND batStorage = :s
            AND batStatus = 1 AND batRemainingItems > 0
          ORDER BY batID ASC
          FOR UPDATE"
    );
    $stmt->execute([':p' => $proID, ':s' => $stgID]);

    $left = $needItems;
    $out  = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $b) {
        $take = min((float)$b['batRemainingItems'], $left);
        if ($take <= 0) continue;
        $out[] = [
            'batchID' => (int)$b['batID'],
            'items'   => $take,
            'pur'     => (float)$b['batPurPrice'],        // ← COGS basis
            'landed'  => (float)$b['batLandedPurPrice'],  // ← reporting snapshot only
        ];
        $left -= $take;
        if ($left <= 1e-7) break;
    }
    if ($left > 1e-7) {
        throw new RuntimeException(
            "Insufficient FIFO stock for product $proID in storage $stgID: short by $left"
        );
    }
    return $out;
}

function assertPurchaseNotConsumed(PDO $conn, int $ordID): void
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) FROM batch b
           JOIN batch_consumption bc ON bc.bcnBatch = b.batID
          WHERE b.batOrder = ?'
    );
    $stmt->execute([$ordID]);
    if ((int)$stmt->fetchColumn() > 0) {
        jsonFail(
            'This purchase invoice cannot be modified: one of its batches has already been sold.',
            409
        );
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * GET
 * ═══════════════════════════════════════════════════════════════════════════ */
function get_order(PDO $conn): void
{
    if (!empty($_GET['ordID'])) {
        $idRef = $_GET['ordID'];

        $sql  = "SELECT ordID, ordName, ordPersonal,
                        CONCAT(perName, ' ', perLastName) AS ordPersonalName,
                        ordBranch, ordxRef, ordTrnRef, ordRemarks, ordEntryDate
                 FROM orders o
                 LEFT JOIN personal p     ON p.perID = o.ordPersonal
                 LEFT JOIN transactions t ON t.trnReference = o.ordTrnRef
                 WHERE (ordID = :id OR ordTrnRef = :id)
                 LIMIT 1";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':id', $idRef);
        $stmt->execute();
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            jsonOut(['msg' => 'not found'], 404);
        }

        $stmt1 = $conn->prepare(
            'SELECT stkID, stkOrder, stkProduct, p.proUnit, p.proSPP,
                    p.proCode, p.proName, stkEntryType, stkStorage, st.stgName,
                    stkQuantity, stkPurPrice, stkQtyInBatch, stkLandedPurPrice,
                    stkDiscount, stkSalePrice
             FROM stock s
             JOIN product p  ON p.proID = s.stkProduct
             JOIN storages st ON st.stgID = s.stkStorage
             WHERE stkOrder = ?
             ORDER BY stkID ASC'
        );
        $stmt1->execute([$order['ordID']]);
        $order['records'] = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $conn->prepare('SELECT * FROM trnDetails WHERE trdReference = ?');
        $stmt2->execute([$order['ordTrnRef']]);
        $order['payments'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $bc = $conn->prepare(
            'SELECT bc.bcnStock, bc.bcnItems, bc.bcnPurPrice, bc.bcnLandedPurPrice, b.batRef
               FROM batch_consumption bc
               JOIN batch b ON b.batID = bc.bcnBatch
              WHERE bc.bcnOrder = ?'
        );
        $bc->execute([$order['ordID']]);
        $order['batch_consumption'] = $bc->fetchAll(PDO::FETCH_ASSOC);

        // COGS uses stkPurPrice (pure purchase), NOT stkLandedPurPrice.
        $cogs = 0.0;
        $revenue = 0.0;
        foreach ($order['records'] as $r) {
            $items = (float)$r['stkQuantity'] * (float)$r['stkQtyInBatch'];
            if ($r['stkEntryType'] === 'OUT') {
                $cogs    += $items * (float)$r['stkPurPrice'];   // ← pure
                $revenue += $items * (float)$r['stkSalePrice'];
            }
        }
        $order['cogs']         = round($cogs, 4);
        $order['revenue']      = round($revenue, 4);
        $order['gross_profit'] = round($revenue - $cogs, 4);

        jsonOut($order);
    }

    $sql  = 'SELECT ordID, ordName, ordPersonal,
                    CONCAT(perName, \' \', perLastName) AS ordPersonalName,
                    ordxRef, ordTrnRef,
                    MAX(CASE WHEN td.trdAccount < 10000000 THEN td.trdAccount ELSE 0 END) AS account,
                    MAX(CASE WHEN td.trdAccount < 10000000 THEN td.trdAmount  ELSE 0 END) AS amount,
                    trnStateText, ordEntryDate
             FROM orders o
             JOIN stock s           ON s.stkOrder = o.ordID
             LEFT JOIN personal p   ON p.perID = o.ordPersonal
             LEFT JOIN transactions t ON t.trnReference = o.ordTrnRef
             JOIN trnDetails td     ON td.trdReference = t.trnReference
             JOIN branch b          ON b.brcID = o.ordBranch
             GROUP BY ordID, ordName, ordPersonal, ordPersonalName,
                      ordxRef, ordTrnRef, trnStateText, ordEntryDate
             ORDER BY ordID DESC';
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    jsonOut($stmt->fetchAll(PDO::FETCH_ASSOC));
}

/* ═══════════════════════════════════════════════════════════════════════════
 * POST — create
 * ═══════════════════════════════════════════════════════════════════════════ */
function create_order(PDO $conn, DataValues $value, ?array $data): void
{
    if (!is_array($data) || $data === []) {
        jsonFail('Invalid or empty JSON', 400);
    }

    $user       = (string)($data['usrName'] ?? '');
    $userResult = $value->getUserDetails($user);
    if (!is_array($userResult)) {
        jsonFail("Unknown user: '$user'", 400);
    }
    $branch = (string)($userResult['usrBranch'] ?? '');
    $usrID  = (int)($userResult['usrID'] ?? 0);

    date_default_timezone_set((string)$value->getCompanyAttributes('comTimeZone'));
    $now           = new DateTime();
    $entryDateTime = $now->format('Y-m-d H:i:s.v');

    $oName    = (string)($data['ordName'] ?? '');
    $perID    = $data['ordPersonal']  ?? null;
    $xRef     = $data['ordxRef']      ?? null;
    $oRemark  = $data['ordRemarks']   ?? null;
    $records  = $data['records']      ?? [];
    $payments = $data['payments']     ?? [];

    if (!in_array($oName, ['Purchase', 'Sale'], true)) {
        jsonFail('invalid order name', 400);
    }
    if (!is_array($records) || !is_array($payments)) {
        jsonFail('records and payments must be arrays', 400);
    }

    $type  = $oName === 'Purchase' ? 'PRCH' : 'SALE';
    $eType = $oName === 'Purchase' ? 'IN'   : 'OUT';

    $totalExpense   = 0.0;
    $totalItem      = 0.0;
    $totalBill      = 0.0;
    $totalProAmount = 0.0;
    $totalDiscount  = 0.0;
    $totalSalesAmt  = 0.0;
    $totalPurAmt    = 0.0;
    $totalCogs      = 0.0;

    foreach ($payments as $exp) {
        $account = (int)($exp['account'] ?? 0);
        if ($account > 40404030) {
            $totalExpense += (float)($exp['amount'] ?? 0);
        }
    }

    foreach ($records as $rec) {
        $totalItem += (float)($rec['stkQuantity'] ?? 0) * (float)($rec['stkQtyInBatch'] ?? 0);
    }

    if ($totalItem <= 0) {
        jsonFail('No items in records (total quantity is 0).', 400);
    }

    foreach ($records as $rec) {
        $pPrice      = (float)($rec['stkPurPrice'] ?? 0);
        $landedPrice = $pPrice + ($totalExpense / $totalItem);

        $lineQty   = (float)($rec['stkQuantity'] ?? 0) * (float)($rec['stkQtyInBatch'] ?? 0);
        $totalBill      += $landedPrice * $lineQty;
        $totalProAmount += $pPrice     * $lineQty;

        if ($oName === 'Sale') {
            $totalDiscount += (float)($rec['stkDiscount']  ?? 0) * $lineQty;
            $totalSalesAmt += (float)($rec['stkSalePrice'] ?? 0) * $lineQty;
            // NOTE: totalPurAmt / totalCogs are computed from FIFO batches below,
            // not from client-supplied prices.
        }
    }

    $defaultCcy = (string)$value->getCompanyAttributes('comLocalCcy');
    $limit      = (float)$value->getBranchAuthLimit($branch, $defaultCcy);

    $status     = 0;
    $stateText  = 'Pending';
    $authUser   = null;
    if ($totalBill <= $limit) {
        $authUser  = $usrID;
        $status    = 1;
        $stateText = 'Authorized';
    }

    $trnRef = $value->generateTrnRef($branch, $type);

    try {
        $conn->beginTransaction();

        $stmt1 = $conn->prepare(
            'INSERT INTO orders (ordPersonal, ordName, ordxRef, ordBranch, ordEntryDate, ordRemarks)
             VALUES (?,?,?,?,?,?)'
        );
        $stmt2 = $conn->prepare(
            'INSERT INTO stock (stkOrder, stkProduct, stkEntryType, stkStorage,
                                stkQuantity, stkPurPrice, stkLandedPurPrice,
                                stkQtyInBatch, stkDiscount, stkSalePrice)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        );
        $stmt3 = $conn->prepare(
            'INSERT INTO transactions (trnReference, trnType, trnStatus, trnStateText,
                                       trnMaker, trnAuthorizer, trnEntryDate)
             VALUES (?,?,?,?,?,?,?)'
        );
        $stmt4 = $conn->prepare(
            'INSERT INTO trnDetails (trdReference, trdCcy, trdBranch, trdAccount,
                                     trdDrCr, trdAmount, trdNarration, trdEntryDate)
             VALUES (?,?,?,?,?,?,?,?)'
        );
        $stmt5 = $conn->prepare('UPDATE orders SET ordTrnRef = ? WHERE ordID = ?');
        $stmt6 = $conn->prepare('UPDATE product SET proSPP = ? WHERE proID = ?');

        $stmtReconcile = $conn->prepare(
            "UPDATE stock
                SET stkPurPrice = ?, stkLandedPurPrice = ?
              WHERE stkProduct = ? AND stkStorage = ?
                AND stkEntryType = 'OUT'
                AND stkPurPrice IS NULL"
        );

        $stmtBatch = $conn->prepare(
            'INSERT INTO batch
                (batRef, batOrder, batProduct, batStorage, batItemsPerBox,
                 batReceivedItems, batRemainingItems,
                 batPurPrice, batLandedPurPrice, batEntryDate, batStatus)
             VALUES (?,?,?,?,?,?,?,?,?,?,1)'
        );
        $stmtBatchCons = $conn->prepare(
            'INSERT INTO batch_consumption
                (bcnBatch, bcnStock, bcnOrder, bcnItems,
                 bcnPurPrice, bcnLandedPurPrice, bcnEntryDate)
             VALUES (?,?,?,?,?,?,?)'
        );
        $stmtBatchDec = $conn->prepare(
            'UPDATE batch SET batRemainingItems = batRemainingItems - ? WHERE batID = ?'
        );

        $stmt1->execute([$perID, $oName, $xRef, $branch, $entryDateTime, $oRemark]);
        $ordID = (int)$conn->lastInsertId();

        $pRemark = "از درک بل خرید #: $ordID, مبلغ بل: $totalBill";
        $sRemark = "از درک بل فروش #: $ordID, مبلغ بل: $totalSalesAmt";

        $stmt3->execute([$trnRef, $type, $status, $stateText, $usrID, $authUser, $entryDateTime]);

        // The 10101011 (Products) line is posted AFTER the FIFO loop so it uses
        // the FIFO COGS for sales (and pure purchase total for purchases).
        // Purchase: Dr 10101011 = totalProAmount  (pure purchase × items)
        // Sale    : Cr 10101011 = totalCogs        (FIFO pure purchase × items)
        $inventoryLineAmount = $oName === 'Purchase'
            ? $totalProAmount
            : 0.0;   // will be replaced by totalCogs after FIFO allocation below

        if ($oName === 'Purchase') {
            foreach ($records as $record) {
                $proID       = (int)($record['stkProduct']  ?? 0);
                $stgID       = (int)($record['stkStorage']  ?? 0);
                $qty         = (float)($record['stkQuantity'] ?? 0);
                $qtyInBatch  = (float)($record['stkQtyInBatch'] ?? 0);
                $sPercentage = $record['stkSalePrice'] ?? null;
                $pPrice      = (float)($record['stkPurPrice'] ?? 0);
                $landedPrice = $pPrice + ($totalExpense / $totalItem);

                $stmt2->execute([
                    $ordID, $proID, $eType, $stgID, $qty,
                    round($pPrice, 8), round($landedPrice, 8),
                    $qtyInBatch, 0, 0,
                ]);
                $stockID = (int)$conn->lastInsertId();
                $items   = $qty * $qtyInBatch;

                $stmtBatch->execute([
                    "P{$proID}-{$ordID}-{$stockID}",
                    $ordID, $proID, $stgID,
                    (int)$qtyInBatch,
                    $items, $items,
                    round($pPrice, 8),
                    round($landedPrice, 8),
                    $entryDateTime,
                ]);

                $stmtReconcile->execute([
                    round($pPrice, 8),
                    round($landedPrice, 8),
                    $proID,
                    $stgID,
                ]);

                if ($sPercentage !== null && (float)$sPercentage > 0) {
                    $stmt6->execute([(float)$sPercentage, $proID]);
                }
            }
        } else {
            // ── Sale: FIFO allocation, COGS from pure purchase price ─────
            foreach ($records as $record) {
                $proID       = (int)($record['stkProduct']  ?? 0);
                $stgID       = (int)($record['stkStorage']  ?? 0);
                $qty         = (float)($record['stkQuantity'] ?? 0);
                $qtyInBatch  = (float)($record['stkQtyInBatch'] ?? 0);
                $discount    = (float)($record['stkDiscount']  ?? 0);
                $sPrice      = (float)($record['stkSalePrice'] ?? 0);

                $requestedItems = $qty * $qtyInBatch;

                try {
                    $alloc = fifoAllocate($conn, $proID, $stgID, $requestedItems);
                } catch (RuntimeException $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    jsonOut([
                        'msg'      => 'not enough',
                        'specific' => "Product ID $proID: {$e->getMessage()}",
                    ], 400);
                }

                $discPerItem = $requestedItems > 0 ? $discount / $requestedItems : 0.0;
                $itemsPerBox = $qtyInBatch > 0 ? $qtyInBatch : 1;

                foreach ($alloc as $line) {
                    $boxesThisLine = $line['items'] / $itemsPerBox;

                    $stmt2->execute([
                        $ordID, $proID, $eType, $stgID,
                        $boxesThisLine,
                        round($line['pur'], 8),      // stkPurPrice  = pure
                        round($line['landed'], 8),   // stkLandedPurPrice = landed snapshot
                        $itemsPerBox,
                        round($discPerItem * $line['items'], 8),
                        round($sPrice, 8),
                    ]);
                    $outStockID = (int)$conn->lastInsertId();

                    $stmtBatchCons->execute([
                        $line['batchID'], $outStockID, $ordID, $line['items'],
                        round($line['pur'], 8),
                        round($line['landed'], 8),
                        $entryDateTime,
                    ]);
                    $stmtBatchDec->execute([$line['items'], $line['batchID']]);

                    // COGS uses pure purchase price
                    $totalCogs += $line['items'] * $line['pur'];
                }
            }
            $inventoryLineAmount = $totalCogs;
        }

        // Post the inventory (10101011) line now that its amount is known.
        $stmt4->execute([
            $trnRef, $defaultCcy, $branch, 10101011,
            $oName === 'Purchase' ? 'Dr' : 'Cr',
            round($inventoryLineAmount, 4),
            $oName === 'Purchase' ? $pRemark : $sRemark,
            $entryDateTime,
        ]);

        // P&L for sales uses FIFO COGS (pure purchase) vs. revenue
        if ($oName === 'Sale') {
            $totalPurAmt = $totalCogs;

            foreach ($payments as $pym) {
                $entryDateTime = $now->format('Y-m-d H:i:s.v');
                $account       = (int)($pym['account']  ?? 0);
                $amount        = (float)($pym['amount'] ?? 0);
                $currency      = (string)($pym['currency'] ?? '');
                $narration     = ' @Rate: ' . ($pym['exRate'] ?? 1) . ' ' . ($pym['narration'] ?? '');

                if ($account > 40404030) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Dr', round($amount, 4), $sRemark . $narration, $entryDateTime]);
                } elseif ($account < 10000000 && $account > 499999 && $amount > 0) {
                    $accStatus = $value->getAccountDetails($account, 'actStatus');
                    $acclimit  = $value->checkForAccountLimit($account, $amount);
                    if ($accStatus === 0) {
                        $conn->rollBack();
                        jsonFail('Account is blocked', 400);
                    }
                    if ($acclimit != 1) {
                        $conn->rollBack();
                        jsonFail('Account over limit', 400);
                    }
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Dr', round($amount, 4), $sRemark . $narration, $entryDateTime]);
                } elseif ($account === 10101010 && $amount > 0) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Dr', round($amount, 4), $sRemark . $narration, $entryDateTime]);
                } elseif ($account === 10101018 && $amount > 0) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Cr', abs(round($amount, 4)), $sRemark . $narration, $entryDateTime]);
                }
            }

            if ($totalDiscount > 0) {
                $stmt4->execute([$trnRef, $defaultCcy, $branch, 40404053, 'Dr', round($totalDiscount, 4), $sRemark . ' Discount', $entryDateTime]);
            }
            if ($totalSalesAmt > $totalPurAmt) {
                $stmt4->execute([$trnRef, $defaultCcy, $branch, 30303031, 'Cr', round($totalSalesAmt - $totalPurAmt, 4), $sRemark . ' Sales Revenue', $entryDateTime]);
            } else {
                $stmt4->execute([$trnRef, $defaultCcy, $branch, 40404050, 'Dr', abs(round($totalSalesAmt - $totalPurAmt, 4)), $sRemark . ' Sales Loss', $entryDateTime]);
            }
        } else {
            // Purchase: payment lines
            foreach ($payments as $pym) {
                $entryDateTime = $now->format('Y-m-d H:i:s.v');
                $account       = (int)($pym['account']  ?? 0);
                $amount        = (float)($pym['amount'] ?? 0);
                $currency      = (string)($pym['currency'] ?? '');
                $narration     = ' @Rate: ' . ($pym['exRate'] ?? 1) . ' ' . ($pym['narration'] ?? '');

                if ($account > 40404030) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Dr', round($amount, 4), $pRemark . $narration, $entryDateTime]);
                    $stmt4->execute([$trnRef, $currency, $branch, 10101018, 'Cr', round($amount, 4), $pRemark . $narration, $entryDateTime]);
                } elseif ($account < 10000000 && $account > 499999 && $amount > 0) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Cr', round($amount, 4), $pRemark . $narration, $entryDateTime]);
                } elseif ($account === 10101010 && $amount > 0) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Cr', round($amount, 4), $pRemark . $narration, $entryDateTime]);
                }
            }
        }

        $stmt5->execute([$trnRef, $ordID]);

        $value->generateUserActivityLog(
            $user,
            $oName,
            "Order ID: $ordID,\nTrnRef: $trnRef\nFrom Stakeholder: $perID,\nTotal Amount: $totalBill,\nxRef: $xRef"
        );

        $conn->commit();

        jsonOut([
            'msg'   => 'success',
            'ref'   => $trnRef,
            'ordID' => $ordID,
        ]);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        jsonFail($e->getMessage(), 500, [
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * PUT — update
 * ═══════════════════════════════════════════════════════════════════════════ */
function update_order(PDO $conn, DataValues $value, ?array $data): void
{
    if (!is_array($data) || $data === []) {
        jsonFail('Invalid or empty JSON', 400);
    }

    $user       = (string)($data['usrName'] ?? '');
    $userResult = $value->getUserDetails($user);
    if (!is_array($userResult)) {
        jsonFail("Unknown user: '$user'", 400);
    }
    $branch = (string)($userResult['usrBranch'] ?? '');
    $usrID  = (int)($userResult['usrID'] ?? 0);

    $ordID = (int)($data['ordID'] ?? 0);
    if ($ordID <= 0) {
        jsonFail('ordID is required', 400);
    }

    $stmtRef = $conn->prepare('SELECT ordTrnRef, ordName FROM orders WHERE ordID = ? LIMIT 1');
    $stmtRef->execute([$ordID]);
    $row = $stmtRef->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonFail("Order not found: $ordID", 404);
    }
    $trnRef = $row['ordTrnRef'];
    $oldName = (string)$row['ordName'];

    if ($oldName === 'Purchase') {
        assertPurchaseNotConsumed($conn, $ordID);
    }

    date_default_timezone_set((string)$value->getCompanyAttributes('comTimeZone'));
    $now           = new DateTime();
    $entryDateTime = $now->format('Y-m-d H:i:s.v');

    $oName    = (string)($data['ordName'] ?? '');
    $perID    = $data['ordPersonal']  ?? null;
    $xRef     = $data['ordxRef']      ?? null;
    $oRemark  = $data['ordRemarks']   ?? null;
    $records  = $data['records']      ?? [];
    $payments = $data['payments']     ?? [];

    if (!in_array($oName, ['Purchase', 'Sale'], true)) {
        jsonFail('invalid order name', 400);
    }
    if ($oName !== $oldName) {
        jsonFail('Order type cannot be changed.', 400);
    }

    $type  = $oName === 'Purchase' ? 'PRCH' : 'SALE';
    $eType = $oName === 'Purchase' ? 'IN'   : 'OUT';

    $totalExpense   = 0.0;
    $totalItem      = 0.0;
    $totalBill      = 0.0;
    $totalProAmount = 0.0;
    $totalDiscount  = 0.0;
    $totalSalesAmt  = 0.0;
    $totalPurAmt    = 0.0;
    $totalCogs      = 0.0;

    foreach ($payments as $exp) {
        if ((int)($exp['account'] ?? 0) > 40404030) {
            $totalExpense += (float)($exp['amount'] ?? 0);
        }
    }
    foreach ($records as $rec) {
        $totalItem += (float)($rec['stkQuantity'] ?? 0) * (float)($rec['stkQtyInBatch'] ?? 0);
    }
    if ($totalItem <= 0) {
        jsonFail('No items in records (total quantity is 0).', 400);
    }
    foreach ($records as $rec) {
        $pPrice      = (float)($rec['stkPurPrice'] ?? 0);
        $landedPrice = $pPrice + ($totalExpense / $totalItem);
        $lineQty     = (float)($rec['stkQuantity'] ?? 0) * (float)($rec['stkQtyInBatch'] ?? 0);

        $totalBill      += $landedPrice * $lineQty;
        $totalProAmount += $pPrice     * $lineQty;

        if ($oName === 'Sale') {
            $totalDiscount += (float)($rec['stkDiscount']  ?? 0) * $lineQty;
            $totalSalesAmt += (float)($rec['stkSalePrice'] ?? 0) * $lineQty;
            // COGS filled in later from FIFO
        }
    }

    $defaultCcy = (string)$value->getCompanyAttributes('comLocalCcy');
    $limit      = (float)$value->getBranchAuthLimit($branch, $defaultCcy);
    $status     = 0;
    $stateText  = 'Pending';
    $authUser   = null;
    if ($totalBill <= $limit) {
        $authUser  = $usrID;
        $status    = 1;
        $stateText = 'Authorized';
    }

    try {
        $conn->beginTransaction();

        $stmt1            = $conn->prepare('UPDATE orders SET ordPersonal=?, ordName=?, ordxRef=?, ordBranch=?, ordRemarks=? WHERE ordID=?');
        $stmt3            = $conn->prepare('UPDATE transactions SET trnStatus=?, trnStateText=?, trnMaker=?, trnAuthorizer=? WHERE trnReference=?');
        $stmt2            = $conn->prepare('INSERT INTO stock (stkOrder, stkProduct, stkEntryType, stkStorage, stkQuantity, stkPurPrice, stkLandedPurPrice, stkQtyInBatch, stkDiscount, stkSalePrice) VALUES (?,?,?,?,?,?,?,?,?,?)');
        $stmtDeleteStock  = $conn->prepare('DELETE FROM stock WHERE stkOrder = ?');
        $stmt4            = $conn->prepare('INSERT INTO trnDetails (trdReference, trdCcy, trdBranch, trdAccount, trdDrCr, trdAmount, trdNarration, trdEntryDate) VALUES (?,?,?,?,?,?,?,?)');
        $stmtDeleteTrnDet = $conn->prepare('DELETE FROM trnDetails WHERE trdReference = ?');
        $stmt6            = $conn->prepare('UPDATE product SET proSPP = ? WHERE proID = ?');

        $stmtReconcile = $conn->prepare(
            "UPDATE stock
                SET stkPurPrice = ?, stkLandedPurPrice = ?
              WHERE stkProduct = ? AND stkStorage = ?
                AND stkEntryType = 'OUT'
                AND stkPurPrice IS NULL"
        );

        $stmtBatch = $conn->prepare(
            'INSERT INTO batch
                (batRef, batOrder, batProduct, batStorage, batItemsPerBox,
                 batReceivedItems, batRemainingItems,
                 batPurPrice, batLandedPurPrice, batEntryDate, batStatus)
             VALUES (?,?,?,?,?,?,?,?,?,?,1)'
        );
        $stmtBatchCons = $conn->prepare(
            'INSERT INTO batch_consumption
                (bcnBatch, bcnStock, bcnOrder, bcnItems,
                 bcnPurPrice, bcnLandedPurPrice, bcnEntryDate)
             VALUES (?,?,?,?,?,?,?)'
        );
        $stmtBatchDec = $conn->prepare(
            'UPDATE batch SET batRemainingItems = batRemainingItems - ? WHERE batID = ?'
        );

        $stmt1->execute([$perID, $oName, $xRef, $branch, $oRemark, $ordID]);

        $pRemark = "از درک بل خرید #: $ordID, مبلغ بل: $totalBill";
        $sRemark = "از درک بل فروش #: $ordID, مبلغ بل: $totalSalesAmt";

        $stmt3->execute([$status, $stateText, $usrID, $authUser, $trnRef]);

        $stmtDeleteTrnDet->execute([$trnRef]);

        // For sales: reverse existing consumption BEFORE deleting stock rows,
        // so we can re-allocate FIFO afterwards on the full remaining pool.
        if ($oldName === 'Sale') {
            $conn->prepare(
                'UPDATE batch b
                   JOIN batch_consumption bc ON bc.bcnBatch = b.batID
                    SET b.batRemainingItems = b.batRemainingItems + bc.bcnItems
                  WHERE bc.bcnOrder = ?'
            )->execute([$ordID]);
            $conn->prepare('DELETE FROM batch_consumption WHERE bcnOrder = ?')->execute([$ordID]);
        } else {
            // Purchase: safe because assertPurchaseNotConsumed guaranteed no sales
            $conn->prepare('DELETE FROM batch WHERE batOrder = ?')->execute([$ordID]);
        }

        $stmtDeleteStock->execute([$ordID]);

        $inventoryLineAmount = $oName === 'Purchase' ? $totalProAmount : 0.0;

        if ($oName === 'Purchase') {
            foreach ($records as $record) {
                $proID       = (int)($record['stkProduct']  ?? 0);
                $stgID       = (int)($record['stkStorage']  ?? 0);
                $qty         = (float)($record['stkQuantity'] ?? 0);
                $qtyInBatch  = (float)($record['stkQtyInBatch'] ?? 0);
                $sPercentage = $record['stkSalePrice'] ?? null;
                $pPrice      = (float)($record['stkPurPrice'] ?? 0);
                $landedPrice = $pPrice + ($totalExpense / $totalItem);

                $stmt2->execute([
                    $ordID, $proID, $eType, $stgID, $qty,
                    round($pPrice, 8), round($landedPrice, 8),
                    $qtyInBatch, 0, 0,
                ]);
                $stockID = (int)$conn->lastInsertId();
                $items   = $qty * $qtyInBatch;

                $stmtBatch->execute([
                    "P{$proID}-{$ordID}-{$stockID}",
                    $ordID, $proID, $stgID,
                    (int)$qtyInBatch,
                    $items, $items,
                    round($pPrice, 8),
                    round($landedPrice, 8),
                    $entryDateTime,
                ]);

                $stmtReconcile->execute([
                    round($pPrice, 8),
                    round($landedPrice, 8),
                    $proID,
                    $stgID,
                ]);

                if ($sPercentage !== null && (float)$sPercentage > 0) {
                    $stmt6->execute([(float)$sPercentage, $proID]);
                }
            }
        } else {
            foreach ($records as $record) {
                $proID       = (int)($record['stkProduct']  ?? 0);
                $stgID       = (int)($record['stkStorage']  ?? 0);
                $qty         = (float)($record['stkQuantity'] ?? 0);
                $qtyInBatch  = (float)($record['stkQtyInBatch'] ?? 0);
                $discount    = (float)($record['stkDiscount']  ?? 0);
                $sPrice      = (float)($record['stkSalePrice'] ?? 0);

                $requestedItems = $qty * $qtyInBatch;

                try {
                    $alloc = fifoAllocate($conn, $proID, $stgID, $requestedItems);
                } catch (RuntimeException $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                    jsonOut([
                        'msg'      => 'not enough',
                        'specific' => "Product ID $proID: {$e->getMessage()}",
                    ], 400);
                }

                $discPerItem = $requestedItems > 0 ? $discount / $requestedItems : 0.0;
                $itemsPerBox = $qtyInBatch > 0 ? $qtyInBatch : 1;

                foreach ($alloc as $line) {
                    $boxesThisLine = $line['items'] / $itemsPerBox;

                    $stmt2->execute([
                        $ordID, $proID, $eType, $stgID,
                        $boxesThisLine,
                        round($line['pur'], 8),
                        round($line['landed'], 8),
                        $itemsPerBox,
                        round($discPerItem * $line['items'], 8),
                        round($sPrice, 8),
                    ]);
                    $outStockID = (int)$conn->lastInsertId();

                    $stmtBatchCons->execute([
                        $line['batchID'], $outStockID, $ordID, $line['items'],
                        round($line['pur'], 8),
                        round($line['landed'], 8),
                        $entryDateTime,
                    ]);
                    $stmtBatchDec->execute([$line['items'], $line['batchID']]);

                    $totalCogs += $line['items'] * $line['pur'];   // pure
                }
            }
            $inventoryLineAmount = $totalCogs;
        }

        $stmt4->execute([
            $trnRef, $defaultCcy, $branch, 10101011,
            $oName === 'Purchase' ? 'Dr' : 'Cr',
            round($inventoryLineAmount, 4),
            $oName === 'Purchase' ? $pRemark : $sRemark,
            $entryDateTime,
        ]);

        if ($oName === 'Sale') {
            $totalPurAmt = $totalCogs;

            foreach ($payments as $pym) {
                $entryDateTime = $now->format('Y-m-d H:i:s.v');
                $account       = (int)($pym['account']  ?? 0);
                $amount        = (float)($pym['amount'] ?? 0);
                $currency      = (string)($pym['currency'] ?? '');
                $narration     = ' @Rate: ' . ($pym['exRate'] ?? 1) . ' ' . ($pym['narration'] ?? '');

                if ($account > 40404030) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Dr', round($amount, 4), $sRemark . $narration, $entryDateTime]);
                } elseif ($account < 10000000 && $account > 499999 && $amount > 0) {
                    $accStatus = $value->getAccountDetails($account, 'actStatus');
                    $acclimit  = $value->checkForAccountLimit($account, $amount);
                    if ($accStatus === 0) {
                        $conn->rollBack();
                        jsonFail('Account is blocked', 400);
                    }
                    if ($acclimit != 1) {
                        $conn->rollBack();
                        jsonFail('Account over limit', 400);
                    }
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Dr', round($amount, 4), $sRemark . $narration, $entryDateTime]);
                } elseif ($account === 10101010 && $amount > 0) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Dr', round($amount, 4), $sRemark . $narration, $entryDateTime]);
                } elseif ($account === 10101018 && $amount > 0) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Cr', abs(round($amount, 4)), $sRemark . $narration, $entryDateTime]);
                }
            }

            if ($totalDiscount > 0) {
                $stmt4->execute([$trnRef, $defaultCcy, $branch, 40404053, 'Dr', round($totalDiscount, 4), $sRemark . ' Discount', $entryDateTime]);
            }
            if ($totalSalesAmt > $totalPurAmt) {
                $stmt4->execute([$trnRef, $defaultCcy, $branch, 30303031, 'Cr', round($totalSalesAmt - $totalPurAmt, 4), $sRemark . ' Sales Revenue', $entryDateTime]);
            } else {
                $stmt4->execute([$trnRef, $defaultCcy, $branch, 40404050, 'Dr', abs(round($totalSalesAmt - $totalPurAmt, 4)), $sRemark . ' Sales Loss', $entryDateTime]);
            }
        } else {
            foreach ($payments as $pym) {
                $entryDateTime = $now->format('Y-m-d H:i:s.v');
                $account       = (int)($pym['account']  ?? 0);
                $amount        = (float)($pym['amount'] ?? 0);
                $currency      = (string)($pym['currency'] ?? '');
                $narration     = ' @Rate: ' . ($pym['exRate'] ?? 1) . ' ' . ($pym['narration'] ?? '');

                if ($account > 40404030) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Dr', round($amount, 4), $pRemark . $narration, $entryDateTime]);
                    $stmt4->execute([$trnRef, $currency, $branch, 10101018, 'Cr', round($amount, 4), $pRemark . $narration, $entryDateTime]);
                } elseif ($account < 10000000 && $account > 499999 && $amount > 0) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Cr', round($amount, 4), $pRemark . $narration, $entryDateTime]);
                } elseif ($account === 10101010 && $amount > 0) {
                    $stmt4->execute([$trnRef, $currency, $branch, $account, 'Cr', round($amount, 4), $pRemark . $narration, $entryDateTime]);
                }
            }
        }

        $value->generateUserActivityLog(
            $user,
            $oName,
            "Updated Order ID: $ordID,\nTrnRef: $trnRef\nFrom Stakeholder: $perID,\nTotal Amount: $totalBill,\nxRef: $xRef"
        );

        $conn->commit();

        jsonOut([
            'msg'   => 'success',
            'ref'   => $trnRef,
            'ordID' => $ordID,
        ]);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        jsonFail($e->getMessage(), 500, [
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);
    }
}

/* ═══════════════════════════════════════════════════════════════════════════
 * DELETE
 * ═══════════════════════════════════════════════════════════════════════════ */
function delete_order(PDO $conn, DataValues $value, ?array $data): void
{
    if (!is_array($data) || $data === []) {
        jsonFail('Invalid or empty JSON', 400);
    }

    $user   = (string)($data['usrName'] ?? '');
    $ordID  = (int)($data['ordID'] ?? 0);
    $trnRef = $data['ordTrnRef'] ?? null;
    $oName  = (string)($data['ordName'] ?? '');

    if ($ordID <= 0) {
        jsonFail('ordID is required', 400);
    }

    if (empty($trnRef)) {
        $stmt = $conn->prepare('SELECT ordTrnRef FROM orders WHERE ordID = ? LIMIT 1');
        $stmt->execute([$ordID]);
        $trnRef = $stmt->fetchColumn();
        if (!$trnRef) {
            jsonFail("Order not found: $ordID", 404);
        }
    }
    if ($oName === '') {
        $stmt = $conn->prepare('SELECT ordName FROM orders WHERE ordID = ? LIMIT 1');
        $stmt->execute([$ordID]);
        $oName = (string)$stmt->fetchColumn();
    }

    if ($oName === 'Purchase') {
        assertPurchaseNotConsumed($conn, $ordID);
    }

    try {
        $conn->beginTransaction();

        if ($oName === 'Sale') {
            $conn->prepare(
                'UPDATE batch b
                   JOIN batch_consumption bc ON bc.bcnBatch = b.batID
                    SET b.batRemainingItems = b.batRemainingItems + bc.bcnItems
                  WHERE bc.bcnOrder = ?'
            )->execute([$ordID]);
            $conn->prepare('DELETE FROM batch_consumption WHERE bcnOrder = ?')->execute([$ordID]);
        } else {
            $conn->prepare('DELETE FROM batch WHERE batOrder = ?')->execute([$ordID]);
        }

        $conn->prepare('DELETE FROM trnDetails WHERE trdReference = ?')->execute([$trnRef]);
        $conn->prepare('DELETE FROM stock      WHERE stkOrder     = ?')->execute([$ordID]);
        $conn->prepare('DELETE FROM orders     WHERE ordID        = ?')->execute([$ordID]);
        $conn->prepare("UPDATE transactions SET trnStateText = 'Deleted', trnStatus = 1 WHERE trnReference = ?")
             ->execute([$trnRef]);

        $value->generateUserActivityLog(
            $user,
            $oName,
            "Deleted - Order ID: $ordID,\nTrn Reference: $trnRef"
        );

        $conn->commit();
        jsonOut(['msg' => 'success']);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        jsonFail($e->getMessage(), 500, [
            'line' => $e->getLine(),
            'file' => $e->getFile(),
        ]);
    }
}

// NOTE: no closing "?>"
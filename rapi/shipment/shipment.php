<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once "../db.php";
require_once "../functions.php";

// Initialize database connection exactly like your product API.
$db = new Database();
$conn = $db->getConnection();

// Initialize your DataValues helper.
$dv = new DataValues($conn);

// Validate connection.
if (!$conn instanceof PDO) {
    http_response_code(500);
    echo json_encode([
        "ok" => false,
        "msg" => "Database connection failed"
    ]);
    exit;
}

/* ------------------------------------------------------------------ *
 * Helpers
 * ------------------------------------------------------------------ */
function jsonOut($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function fail(string $msg, int $code = 400, array $extra = []): void {
    jsonOut(array_merge(['ok' => false, 'msg' => $msg], $extra), $code);
}
function ok(array $data = []): void {
    jsonOut(array_merge(['ok' => true], $data));
}
function body(): array {
    $raw = file_get_contents('php://input');
    $in  = json_decode($raw, true);
    return is_array($in) ? $in : ($_POST ?: []);
}
function need(array $in, array $keys): void {
    foreach ($keys as $k) {
        if (!isset($in[$k]) || $in[$k] === '' || $in[$k] === null) {
            fail("Missing required field: $k");
        }
    }
}

/* ------------------------------------------------------------------ *
 * Auth — key pass (Bearer token) using your current_user_id()
 * ------------------------------------------------------------------ */
/* ------------------------------------------------------------------ *
 * Read body once + route
 * ------------------------------------------------------------------ */
$in     = body();
$action = $_GET['action'] ?? ($in['action'] ?? '');
if ($action === '') fail('Missing action');

/* ------------------------------------------------------------------ *
 * Auth — username optional for GET, required for POST
 * ------------------------------------------------------------------ */

$username = trim((string)($_GET['usrName'] ?? $in['usrName'] ?? ''));

$uid = 0;
$currentUsername = '';
$branch = 1000;
$me = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($username === '') {
        fail('Missing usrName', 401);
    }

    $stmt = $conn->prepare(
        "SELECT usrID, usrName, usrBranch, usrRole, usrStatus
         FROM users
         WHERE usrName = ?
         LIMIT 1"
    );

    $stmt->execute([$username]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$me) fail('User not found', 401);
    if ((int)$me['usrStatus'] !== 1) fail('User is inactive', 403);

    $uid = (int)$me['usrID'];
    $currentUsername = $me['usrName'];
    $branch = (int)($me['usrBranch'] ?? 1000);
}

/* ------------------------------------------------------------------ *
 * Router
 * ------------------------------------------------------------------ */
$action = $_GET['action'] ?? (body()['action'] ?? '');
if ($action === '') fail('Missing action');

try {
    switch ($action) {

        /* ============================================================
         * LIST CONTAINERS
         * GET  shipment.php?action=list
         * Optional filters: status, containerNo, from, to, page, size
         * ============================================================ */
        
case 'list': {

    $where = ['1=1'];
    $params = [];

    // Default status: pending
    // Use status=all to show every status.
    $status = trim((string)($_GET['status'] ?? ''));

    if ($status === '') {
        $status = 'pending';
    }

    if ($status !== 'all') {
        $allowedStatuses = ['pending', 'shipped', 'delivered'];

        if (!in_array($status, $allowedStatuses, true)) {
            fail('Invalid shipment status');
        }

        $where[] = 's.status = :status';
        $params[':status'] = $status;
    }

    // General search: reference OR container number OR sequence number
    $search = trim((string)($_GET['search'] ?? ''));

    if ($search !== '') {
        $where[] = '(
            s.shpReference LIKE :searchRef
            OR s.containerNo LIKE :searchContainer
            OR s.sequenceNo LIKE :searchSequence
        )';

        $searchValue = '%' . $search . '%';

        $params[':searchRef'] = $searchValue;
        $params[':searchContainer'] = $searchValue;
        $params[':searchSequence'] = $searchValue;
    }

    // Search container number
    if (!empty($_GET['containerNo'])) {
        $where[] = 's.containerNo LIKE :cno';
        $params[':cno'] = '%' . trim($_GET['containerNo']) . '%';
    }

    // Search sequence number
    if (!empty($_GET['sequenceNo'])) {
        $where[] = 's.sequenceNo LIKE :seq';
        $params[':seq'] = '%' . trim($_GET['sequenceNo']) . '%';
    }

    // Loading date range
    if (!empty($_GET['from'])) {
        $where[] = 's.loadingDate >= :from';
        $params[':from'] = $_GET['from'];
    }

    if (!empty($_GET['to'])) {
        $where[] = 's.loadingDate <= :to';
        $params[':to'] = $_GET['to'];
    }

    // Pagination
    $page = max(1, (int)($_GET['page'] ?? 1));
    $size = min(200, max(1, (int)($_GET['size'] ?? 50)));
    $off  = ($page - 1) * $size;

    // Main query
    $sql = "
        SELECT
            s.shpId,
            s.shpReference,
            s.containerNo,
            s.entryDate,
            s.sequenceNo,
            s.loadingDate,
            s.unloadingDate,
            s.portName,
            s.description,
            s.status,
            s.createdBy,
            u.usrName AS createdByName,

            COUNT(i.shpItmId) AS totalItems,

            COALESCE(SUM(
                (COALESCE(i.cbm, 0) * COALESCE(i.ratePerCbm, 0))
                +
                (COALESCE(i.weight, 0) * COALESCE(i.ratePerWeight, 0))
                +
                COALESCE(i.extraCharges, 0)
            ), 0) AS totalRent

        FROM shipmentTbl s

        LEFT JOIN shipmentItemsTbl i
            ON i.shpId = s.shpId

        LEFT JOIN users u
            ON u.usrID = s.createdBy

        WHERE " . implode(' AND ', $where) . "

        GROUP BY s.shpId

        ORDER BY s.entryDate DESC

        LIMIT :lim OFFSET :off
    ";

    $stmt = $conn->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':lim', $size, PDO::PARAM_INT);
    $stmt->bindValue(':off', $off, PDO::PARAM_INT);

    $stmt->execute();

    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ok([
        'data' => $data,
        'page' => $page,
        'size' => $size,
        'statusFilter' => $status
    ]);
}


        /* ============================================================
         * GET ONE CONTAINER (with items)
         * GET  shipment.php?action=get&shpId=1
         * ============================================================ */
case 'get': {
    $shpId = (int)($_GET['shpId'] ?? 0);
    if ($shpId <= 0) fail('shpId required');

    // ── Container header ─────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT *
        FROM shipmentTbl
        WHERE shpId = ?
        LIMIT 1
    ");
    $stmt->execute([$shpId]);
    $shp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shp) fail('Container not found', 404);

    // ── Items (per-item computed fields) ─────────────────────────
    $stmt = $conn->prepare("
        SELECT
            i.*,

            p.perID,
            p.perCode,
            p.perName,
            p.perLastName,

            CONCAT_WS(
                ' ',
                NULLIF(TRIM(p.perName), ''),
                NULLIF(TRIM(p.perLastName), '')
            ) AS fullName,

            -- Per-item weight average = weight / cbm  (CORRECT as-is)
            ROUND(
                COALESCE(i.weight, 0) / NULLIF(i.cbm, 0),
                4
            ) AS weightAvg,

            -- Per-item rent
            ROUND(
                (COALESCE(i.cbm, 0)    * COALESCE(i.ratePerCbm, 0)) +
                (COALESCE(i.weight, 0) * COALESCE(i.ratePerWeight, 0)) +
                COALESCE(i.extraCharges, 0),
                2
            ) AS itemRent,

            COALESCE((
                SELECT SUM(m.quantity)
                FROM shipmentItemMovements m
                WHERE m.shpItmId = i.shpItmId
                  AND m.movementType = 'deliver'
            ), 0) AS delivered,

            COALESCE((
                SELECT SUM(m.quantity)
                FROM shipmentItemMovements m
                WHERE m.shpItmId = i.shpItmId
                  AND m.movementType = 'return'
            ), 0) AS returned,

            COALESCE((
                SELECT SUM(m.quantity)
                FROM shipmentItemMovements m
                WHERE m.shpItmId = i.shpItmId
                  AND m.movementType = 'unload'
            ), 0) AS unloaded

        FROM shipmentItemsTbl i

        LEFT JOIN accountDetails ad
            ON ad.actAccount = i.AccountNo

        LEFT JOIN personal p
            ON p.perID = ad.actSignatory

        WHERE i.shpId = ?

        ORDER BY i.shpItmId ASC
    ");
    $stmt->execute([$shpId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── Container-level totals ───────────────────────────────────
    // weightAvg at the CONTAINER level = SUM(weight) / SUM(cbm)   ← what you asked for
    $stmt = $conn->prepare("
        SELECT
            COUNT(*)                              AS totalItems,
            COALESCE(SUM(i.qty),    0)            AS totalQty,
            COALESCE(SUM(i.cbm),    0)            AS totalCbm,
            COALESCE(SUM(i.weight), 0)            AS totalWeight,
            COALESCE(SUM(
                (COALESCE(i.cbm, 0)    * COALESCE(i.ratePerCbm, 0)) +
                (COALESCE(i.weight, 0) * COALESCE(i.ratePerWeight, 0)) +
                COALESCE(i.extraCharges, 0)
            ), 0)                                 AS totalRent
        FROM shipmentItemsTbl i
        WHERE i.shpId = ?
    ");
    $stmt->execute([$shpId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC) ?: [
        'totalItems'  => 0,
        'totalQty'    => 0,
        'totalCbm'    => 0,
        'totalWeight' => 0,
        'totalRent'   => 0,
    ];

    $totalCbm    = (float)$totals['totalCbm'];
    $totalWeight = (float)$totals['totalWeight'];
    $containerWeightAvg = $totalCbm > 0
        ? round($totalWeight / $totalCbm, 4)
        : 0;

    ok([
        'shipment' => $shp,
        'items'    => $items,
        'totals'   => [
            'totalItems'        => (int)$totals['totalItems'],
            'totalQty'          => (float)$totals['totalQty'],
            'totalCbm'          => $totalCbm,
            'totalWeight'       => $totalWeight,
            'totalRent'         => round((float)$totals['totalRent'], 2),
            'containerWeightAvg'=> $containerWeightAvg, // SUM(weight)/SUM(cbm)
        ],
    ]);
}

        /* ============================================================
         * CREATE CONTAINER + TRPT transaction  (Option A)
         * POST shipment.php?action=create
         * body: { portName, containerNo, sequenceNo?, loadingDate,
         *         unloadingDate?, description? }
         * ============================================================ */
        case 'create': {
            $in = body();
            need($in, ['portName', 'containerNo', 'loadingDate']);

            $ref = $dv->generateTrnRef($branch, 'TRPT');
            if (!$ref) fail('Could not generate reference');

            $conn->beginTransaction();

            $conn->prepare(
                "INSERT INTO transactions
                    (trnReference, trnType, trnStatus, trnStateText,
                     trnMaker, trnAuthorizer, trnEntryDate)
                 VALUES (:r, 'TRPT', 1, 'Pending', :m, NULL, NOW())"
            )->execute([':r' => $ref, ':m' => $uid]);

            $conn->prepare(
                "INSERT INTO shipmentTbl
                    (shpReference, createdBy, portName, containerNo, sequenceNo,
                     loadingDate, unloadingDate, description, status)
                 VALUES (:r, :u, :p, :c, :s, :l, :un, :d, 'pending')"
            )->execute([
                ':r'  => $ref,
                ':u'  => $uid,
                ':p'  => $in['portName'],
                ':c'  => $in['containerNo'],
                ':s'  => $in['sequenceNo']    ?? null,
                ':l'  => $in['loadingDate'],
                ':un' => $in['unloadingDate'] ?? null,
                ':d'  => $in['description']   ?? null,
            ]);

            $shpId = (int)$conn->lastInsertId();
            $conn->commit();

            $dv->generateUserActivityLog($me['usrName'], 'Create Shipment',
                "Created container {$in['containerNo']} ref $ref");

            ok(['shpId' => $shpId, 'shpReference' => $ref]);
        }

        /* ============================================================
         * EDIT CONTAINER HEADER
         * POST shipment.php?action=update
         * body: { shpId, portName?, containerNo?, sequenceNo?,
         *         loadingDate?, unloadingDate?, description?, status? }
         * ============================================================ */
        case 'update': {
    $in = body();
    need($in, ['shpId']);
    $shpId = (int)$in['shpId'];

    // ── Load the current header so we can detect changes ────────────
    $stmt = $conn->prepare(
        "SELECT shpReference, containerNo FROM shipmentTbl WHERE shpId = ? LIMIT 1"
    );
    $stmt->execute([$shpId]);
    $before = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$before) fail('Container not found', 404);

    $fields = [];
    $params = [':shpId' => $shpId];
    foreach (['portName','containerNo','sequenceNo','loadingDate',
              'unloadingDate','description','status'] as $f) {
        if (array_key_exists($f, $in)) {
            $fields[] = "$f = :$f";
            $params[":$f"] = $in[$f] === '' ? null : $in[$f];
        }
    }
    if (!$fields) fail('Nothing to update');

    $sql = "UPDATE shipmentTbl SET " . implode(', ', $fields) . " WHERE shpId = :shpId";
    $conn->prepare($sql)->execute($params);

    // ── If anything that appears in the narration changed,
    //    re-sync every customer account on this shipment so
    //    their Dr and Cr narrations reflect the new values. ──
    $affectsNarration =
        array_key_exists('containerNo', $in) ||
        array_key_exists('sequenceNo',  $in);   // SEQ is also in the narration

    if ($affectsNarration) {
        // Re-read after update so syncAccountTrn uses the new containerNo
        $stmt = $conn->prepare(
            "SELECT shpReference, containerNo FROM shipmentTbl WHERE shpId = ? LIMIT 1"
        );
        $stmt->execute([$shpId]);
        $after = $stmt->fetch(PDO::FETCH_ASSOC);
        $ref         = $after['shpReference'];
        $containerNo = (string)$after['containerNo'];

        // Every distinct customer account that has billable items on this shipment
        $stmt = $conn->prepare(
            "SELECT DISTINCT i.AccountNo
               FROM shipmentItemsTbl i
              WHERE i.shpId = ?"
        );
        $stmt->execute([$shpId]);
        $accounts = $stmt->fetchAll(PDO::FETCH_COLUMN);

        foreach ($accounts as $acct) {
            syncAccountTrn($conn, $ref, $containerNo, (int)$acct, (int)$branch);
        }
    }

    $dv->generateUserActivityLog($me['usrName'], 'Update Shipment', "Updated shipment shpId=$shpId");
    ok();
}

    /* ============================================================
 * DELETE CONTAINER (only if no items yet)
 * POST shipment.php?action=delete
 * body: { shpId }
 *
 * Also removes the linked TRPT transaction (trnReference = shpReference)
 * so no orphan audit row is left behind once the container is gone.
 * ============================================================ */
case 'delete': {
    $in = body();
    need($in, ['shpId']);
    $shpId = (int)$in['shpId'];

    // 1) Must have no items
    $chk = $conn->prepare(
        "SELECT COUNT(*) FROM shipmentItemsTbl WHERE shpId = ?"
    );
    $chk->execute([$shpId]);
    if ((int)$chk->fetchColumn() > 0) {
        fail('Cannot delete: container already has items');
    }

    // 2) Fetch the reference BEFORE deleting (needed to remove the transaction)
    $refStmt = $conn->prepare(
        "SELECT shpReference FROM shipmentTbl WHERE shpId = ? LIMIT 1"
    );
    $refStmt->execute([$shpId]);
    $ref = $refStmt->fetchColumn();

    if (!$ref) {
        fail('Container not found', 404);
    }

    $conn->beginTransaction();
    try {
        // 3) Delete the container
        $conn->prepare(
            "DELETE FROM shipmentTbl WHERE shpId = ?"
        )->execute([$shpId]);

        // 4) Delete the linked transaction (audit row)
        $conn->prepare(
            "DELETE FROM transactions WHERE trnReference = ?"
        )->execute([$ref]);

        $conn->commit();
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        fail('Failed to delete container: ' . $e->getMessage(), 500);
    }

    $dv->generateUserActivityLog(
        $me['usrName'],
        'Delete Shipment',
        "Deleted shipment shpId=$shpId ref=$ref (and linked transaction)"
    );

    ok();
}

        /* ============================================================
         * ADD ITEM to a container (Stage B)
         * POST shipment.php?action=addItem
         * body: { shpId, AccountNo, itemDescription, qty,
         *         cbm?, weight?, ratePerCbm?, ratePerWeight?, extraCharges? }
         * ============================================================ */
case 'addItem': {
    $in = body();
    need($in, ['shpId', 'AccountNo', 'itemDescription', 'qty']);
    if ((float)$in['qty'] <= 0) fail('qty must be > 0');

    $shpId = (int)$in['shpId'];

    $stmt = $conn->prepare(
        "SELECT shpId, shpReference, containerNo, createdBy
           FROM shipmentTbl WHERE shpId = ? LIMIT 1"
    );
    $stmt->execute([$shpId]);
    $shp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shp) fail('Container not found', 404);

    $ref         = $shp['shpReference'];
    $containerNo = (string)$shp['containerNo'];

    // 1) Validate account + currency against base
    $account = loadAccount($conn, $in['AccountNo']);
    if (!$account) fail('Account not found: ' . (string)$in['AccountNo'], 404);

    $base = baseCurrency($conn);
    if (strcasecmp($account['accCcy'], $base) !== 0) {
        fail("Account currency ({$account['accCcy']}) does not match shipment base currency ($base)");
    }

    // 2) Insert the item row + sync the aggregated trnDetails
    $conn->beginTransaction();
    try {
        $conn->prepare(
            "INSERT INTO shipmentItemsTbl
                (shpId, AccountNo, itemDescription, qty, cbm, weight,
                 ratePerCbm, ratePerWeight, extraCharges, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $shpId,
            $account['accNumber'],
            $in['itemDescription'],
            $in['qty'],
            $in['cbm']           ?? null,
            $in['weight']        ?? null,
            $in['ratePerCbm']    ?? null,
            $in['ratePerWeight'] ?? null,
            $in['extraCharges']  ?? 0,
        ]);
        $itmId = (int)$conn->lastInsertId();

        // One Dr line per (customer account, ref), one Cr line per account for clearing.
        syncAccountTrn($conn, $ref, $containerNo, (int)$account['accNumber'], (int)$branch);

        $conn->commit();
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        fail('Failed to add item: ' . $e->getMessage(), 500);
    }

    $dv->generateUserActivityLog(
        $me['usrName'],
        'Add Shipment Item',
        "Added item $itmId to container $shpId (ref $ref)"
    );

    ok(['shpItmId' => $itmId]);
}
        /* ============================================================
         * UPDATE ITEM
         * POST shipment.php?action=updateItem
         * body: { shpItmId, AccountNo?, itemDescription?, qty?, cbm?,
         *         weight?, ratePerCbm?, ratePerWeight?, extraCharges? }
         * ============================================================ */
    case 'updateItem': {
    $in = body();
    need($in, ['shpItmId']);

    $itmId = (int)$in['shpItmId'];

    // Load current item + shipment
    $stmt = $conn->prepare(
        "SELECT i.shpItmId, i.shpId, i.AccountNo, i.qty,
                i.cbm, i.weight, i.ratePerCbm, i.ratePerWeight,
                i.extraCharges, i.itemDescription,
                s.shpReference, s.containerNo
           FROM shipmentItemsTbl i
           JOIN shipmentTbl s ON s.shpId = i.shpId
          WHERE i.shpItmId = ? LIMIT 1"
    );
    $stmt->execute([$itmId]);
    $cur = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$cur) fail('Item not found', 404);

    $ref         = $cur['shpReference'];
    $containerNo = (string)$cur['containerNo'];
    $oldAccount  = (int)$cur['AccountNo'];
    $newAccount  = $oldAccount;

    // If the account is being changed, validate + currency-check it
    if (array_key_exists('AccountNo', $in) && $in['AccountNo'] !== '') {
        $account = loadAccount($conn, $in['AccountNo']);
        if (!$account) {
            fail('Account not found: ' . (string)$in['AccountNo'], 404);
        }
        $base = baseCurrency($conn);
        if (strcasecmp($account['accCcy'], $base) !== 0) {
            fail("Account currency ({$account['accCcy']}) does not match shipment base currency ($base)");
        }
        $newAccount = (int)$account['accNumber'];
    }

    // Build item update
    $allowed = ['AccountNo','itemDescription','qty','cbm','weight',
                'ratePerCbm','ratePerWeight','extraCharges'];
    $fields = [];
    $params = [':id' => $itmId];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $in)) {
            $fields[] = "$f = :$f";
            $params[":$f"] = $in[$f] === '' ? null : $in[$f];
        }
    }
    if (!$fields) fail('Nothing to update');

    $conn->beginTransaction();
    try {
        $sql = "UPDATE shipmentItemsTbl SET "
             . implode(', ', $fields)
             . ", updatedAt = NOW() WHERE shpItmId = :id";
        $conn->prepare($sql)->execute($params);

        // Refresh aggregate for the old account (its totals decreased)
        if ($oldAccount !== $newAccount) {
            syncAccountTrn($conn, $ref, $containerNo, $oldAccount, (int)$branch);
        }
        // Refresh aggregate for the current account (its totals may have changed)
        syncAccountTrn($conn, $ref, $containerNo, $newAccount, (int)$branch);

        $conn->commit();
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        fail('Failed to update item: ' . $e->getMessage(), 500);
    }

    $dv->generateUserActivityLog(
        $me['usrName'],
        'Update Shipment Item',
        "Updated item $itmId (ref $ref)"
    );

    ok();
}

        /* ============================================================
         * DELETE ITEM
         * POST shipment.php?action=deleteItem
         * body: { shpItmId }
         * ============================================================ */
 case 'deleteItem': {
    $in = body();
    need($in, ['shpItmId']);

    $itmId = (int)$in['shpItmId'];

    $stmt = $conn->prepare(
        "SELECT i.shpItmId, i.shpId, i.AccountNo,
                s.shpReference, s.containerNo
           FROM shipmentItemsTbl i
           JOIN shipmentTbl s ON s.shpId = i.shpId
          WHERE i.shpItmId = ? LIMIT 1"
    );
    $stmt->execute([$itmId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) fail('Item not found', 404);

    $chk = $conn->prepare(
        "SELECT COUNT(*) FROM shipmentItemMovements WHERE shpItmId = ?"
    );
    $chk->execute([$itmId]);
    if ((int)$chk->fetchColumn() > 0) {
        fail('Cannot delete: item already has movements');
    }

    $ref         = $row['shpReference'];
    $containerNo = (string)$row['containerNo'];
    $account     = (int)$row['AccountNo'];

    $conn->beginTransaction();
    try {
        $conn->prepare(
            "DELETE FROM shipmentItemsTbl WHERE shpItmId = ?"
        )->execute([$itmId]);

        // If this was the last item for (ref, account), syncAccountTrn deletes both lines.
        syncAccountTrn($conn, $ref, $containerNo, $account, (int)$branch);

        $conn->commit();
    } catch (\Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        fail('Failed to delete item: ' . $e->getMessage(), 500);
    }

    $dv->generateUserActivityLog(
        $me['usrName'],
        'Delete Shipment Item',
        "Deleted item $itmId (ref $ref)"
    );

    ok();
}

        /* ============================================================
         * RECORD A MOVEMENT (unload / shift / deliver / return)
         * POST shipment.php?action=move
         * body: { shpItmId, movementType, fromStorage?, toStorage?,
         *         quantity, remarks? }
         * ============================================================ */
        case 'move': {
            $in = body();
            need($in, ['shpItmId', 'movementType', 'quantity']);

            $allowed = ['unload','shift','deliver','return'];
            if (!in_array($in['movementType'], $allowed, true)) {
                fail('Invalid movementType');
            }
            $qty = (float)$in['quantity'];
            if ($qty <= 0) fail('quantity must be > 0');

            $itmId = (int)$in['shpItmId'];

            // Load item + qty
            $stmt = $conn->prepare("SELECT qty FROM shipmentItemsTbl WHERE shpItmId = ? LIMIT 1");
            $stmt->execute([$itmId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) fail('Item not found', 404);
            $itemQty = (float)$row['qty'];

            // For deliver: ensure delivered + qty doesn't exceed available
            if ($in['movementType'] === 'deliver') {
                $stmt = $conn->prepare(
                    "SELECT
                        COALESCE(SUM(CASE WHEN movementType='deliver' THEN quantity END),0) AS del,
                        COALESCE(SUM(CASE WHEN movementType='return'  THEN quantity END),0) AS ret
                     FROM shipmentItemMovements WHERE shpItmId = ?"
                );
                $stmt->execute([$itmId]);
                $m = $stmt->fetch(PDO::FETCH_ASSOC);
                $available = $itemQty - (float)$m['del'] + (float)$m['ret'];
                if ($qty > $available + 0.0001) {
                    fail("Cannot deliver $qty — only $available available");
                }
            }

            $conn->prepare(
                "INSERT INTO shipmentItemMovements
                    (shpItmId, movementType, fromStorage, toStorage,
                     quantity, remarks, movedBy, movedAt)
                 VALUES (:i, :t, :f, :to, :q, :r, :u, NOW())"
            )->execute([
                ':i'  => $itmId,
                ':t'  => $in['movementType'],
                ':f'  => $in['fromStorage'] ?? null,
                ':to' => $in['toStorage']   ?? null,
                ':q'  => $qty,
                ':r'  => $in['remarks']     ?? null,
                ':u'  => $uid,
            ]);
            $mvId = (int)$conn->lastInsertId();

            // Refresh item status from movements
            recalcItemStatus($conn, $itmId);

            $dv->generateUserActivityLog($me['usrName'], 'Shipment Movement',
                "Item $itmId {$in['movementType']} qty $qty");
            ok(['shpItmMvId' => $mvId]);
        }

        /* ============================================================
         * UPDATE CONTAINER STATUS (manual override)
         * POST shipment.php?action=updateStatus
         * body: { shpId, status: 'pending'|'shipped'|'delivered' }
         * ============================================================ */
        case 'updateStatus': {
            $in = body();
            need($in, ['shpId', 'status']);
            $allowed = ['pending','shipped','delivered'];
            if (!in_array($in['status'], $allowed, true)) fail('Invalid status');

            $conn->prepare("UPDATE shipmentTbl SET status = ? WHERE shpId = ?")
                 ->execute([$in['status'], (int)$in['shpId']]);

            $dv->generateUserActivityLog($me['usrName'], 'Shipment Status',
                "Set shipment {$in['shpId']} → {$in['status']}");
            ok();
        }

        /* ============================================================
         * UPDATE ITEM DELIVERY STATUS (derived from movements)
         * POST shipment.php?action=updateItemStatus
         * body: { shpItmId }
         * — recomputes from movements and writes it back
         * ============================================================ */
        case 'updateItemStatus': {
            $in = body();
            need($in, ['shpItmId']);
            $itmId = (int)$in['shpItmId'];
            $st = recalcItemStatus($conn, $itmId);
            ok(['status' => $st]);
        }

        /* ============================================================
         * LIST MOVEMENTS FOR AN ITEM OR CONTAINER
         * GET shipment.php?action=movements&shpItmId=1
         * GET shipment.php?action=movements&shpId=1
         * ============================================================ */
        case 'movements': {
            $where = [];
            $params = [];
            if (!empty($_GET['shpItmId'])) { $where[] = 'm.shpItmId = :i'; $params[':i'] = (int)$_GET['shpItmId']; }
            if (!empty($_GET['shpId'])) {
                $where[] = 'it.shpId = :s'; $params[':s'] = (int)$_GET['shpId'];
            }
            if (!$where) fail('shpItmId or shpId required');

            $sql = "SELECT m.*, u.usrName AS movedByName
                      FROM shipmentItemMovements m
                      JOIN shipmentItemsTbl it ON it.shpItmId = m.shpItmId
                      LEFT JOIN users u        ON u.usrID    = m.movedBy
                      WHERE " . implode(' AND ', $where) . "
                      ORDER BY m.movedAt DESC";
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            ok(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

        /* ============================================================
         * ITEMS OF A CONTAINER (with derived delivered/returned/status)
         * GET shipment.php?action=items&shpId=1
         * ============================================================ */
                    case 'items': {
                        $shpId = (int)($_GET['shpId'] ?? 0);
                        if ($shpId <= 0) fail('shpId required');

                        $stmt = $conn->prepare(
                            "SELECT i.*,
                -- Customer information
                p.perID,
                p.perCode,
                TRIM(CONCAT_WS(' ',
                    NULLIF(p.perName, ''),
                    NULLIF(p.perLastName, '')
                )) AS customerName,

                -- Account information
                a.accNumber,
                a.accName,

                -- Account currency
                c.ccyCode AS accountCurrency,

                -- Average weight per CBM
                CASE
                    WHEN COALESCE(i.cbm, 0) > 0
                    THEN ROUND(i.weight / i.cbm, 3)
                    ELSE 0
                END AS weightAverage

            FROM shipmentItemsTbl i

            LEFT JOIN accounts a
                ON a.accNumber = i.AccountNo

            LEFT JOIN accountDetails ad
                ON ad.actAccount = i.AccountNo

            LEFT JOIN personal p
                ON p.perID = ad.actSignatory

            LEFT JOIN currency c
                ON c.ccyCode = ad.actCurrency

            WHERE i.shpId = ?

            ORDER BY i.shpItmId ASC"
            );
            $stmt->execute([$shpId]);
            ok(['data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        }

            default:
                fail('Unknown action: ' . $action, 404);
        }
        } catch (\Throwable $th) {
            if ($conn->inTransaction()) $conn->rollBack();
            fail($th->getMessage(), 500, ['line' => $th->getLine(), 'file' => $th->getFile()]);
        }

/* ------------------------------------------------------------------ *
 * Helper: recompute item status from its movements
 * ------------------------------------------------------------------ */
function recalcItemStatus(PDO $conn, int $itmId): string {
    $stmt = $conn->prepare(
        "SELECT i.qty,
                COALESCE(SUM(CASE WHEN m.movementType='deliver' THEN m.quantity END),0) AS del
           FROM shipmentItemsTbl i
           LEFT JOIN shipmentItemMovements m ON m.shpItmId = i.shpItmId
          WHERE i.shpItmId = ?
          GROUP BY i.shpItmId"
    );
    $stmt->execute([$itmId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) return 'pending';

    $qty = (float)$r['qty'];
    $del = (float)$r['del'];

    if ($del <= 0)            $st = 'pending';
    elseif ($del < $qty - 0.0001) $st = 'partiallyDelivered';
    else                       $st = 'delivered';

    $conn->prepare("UPDATE shipmentItemsTbl SET status = ?, updatedAt = NOW() WHERE shpItmId = ?")
         ->execute([$st, $itmId]);
    return $st;
}
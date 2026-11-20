<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once "../db.php";
require_once "../functions.php";

$db = new Database();
$conn = $db->getConnection();
$value = new DataValues($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(["msg" => "failed", "error" => "Method Not Allowed"]);
    exit;
}

get_transactions();

// ─────────────────────────────────────────────────────────────
// GET /transaction.php
//
// Query params (all optional):
//   status : all | pending | auth | unauth | reversed | deleted
//            default: all
//   from   : YYYY-MM-DD  (default: today)
//   to     : YYYY-MM-DD  (default: from)
//   type   : e.g. CHDP or "CHDP,CHWL,CRFX"
//   branch : int (trdBranch)
//   search : free text → reference / narration / maker / checker
//
// Response (success): { "data": [...], "total": N }
// Response (failure): { "msg": "failed", "error": "…" }
// ─────────────────────────────────────────────────────────────
function get_transactions() {
    global $conn, $value;

    $comTZ = $value->getCompanyAttributes('comTimeZone');
    if (empty($comTZ)) $comTZ = 'UTC';
    $timezone = new DateTimeZone($comTZ);
    $now      = new DateTime('now', $timezone);

    // ── Read filters
    $status = isset($_GET['status']) ? strtolower(trim((string)$_GET['status'])) : 'all';
    $from   = isset($_GET['from']) ? trim((string)$_GET['from']) : $now->format('Y-m-d');
    $to     = isset($_GET['to']) ? trim((string)$_GET['to']) : $from;
    $type   = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
    $branch = isset($_GET['branch']) ? (int)$_GET['branch'] : 0;
    $search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

    // Pending is always all-time — no date applied.
    $applyDateRange = ($status !== 'pending');

    // Validate only when the date range is actually used.
    if ($applyDateRange) {
        if (!validDate($from) || !validDate($to)) {
            http_response_code(400);
            echo json_encode(["msg" => "failed", "error" => "invalid_date_range"]);
            return;
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
    }

    $where  = [];
    $params = [];

    // ── Status
    switch ($status) {
        case 'pending':
            // Genuinely pending only — Deleted has trnStatus = 0 too.
            $where[] = "tr.trnStatus = 0 AND tr.trnStateText = 'Pending'";
            break;

        case 'auth':
        case 'authorized':
            $where[] = "tr.trnStatus = 1 AND tr.trnStateText = 'Authorized'";
            break;

        case 'unauth':
        case 'unauthorized':
            $where[] = "tr.trnStatus = 1 AND tr.trnStateText = 'Pending'";
            break;

        case 'reversed':
            $where[] = "tr.trnStateText = 'Reversed'";
            break;

        case 'deleted':
            $where[] = "tr.trnStateText = 'Deleted'";
            break;

        case 'all':
        default:
            // no additional status predicate — the date clause handles it
            break;
    }

    // ── Date range — pending rows always bypass it.
    //    - pending is already excluded above (applyDateRange === false).
    //    - for `all` / `auth` / `reversed` / `deleted`, non-pending rows
    //      obey the date, but any pending row is still visible even if
    //      it falls outside the range.
    if ($applyDateRange) {
        $where[] = "(
            (tr.trnStatus = 0 AND tr.trnStateText = 'Pending')
            OR DATE(tr.trnEntryDate) BETWEEN :from AND :to
        )";
        $params[':from'] = $from;
        $params[':to']   = $to;
    }

    // ── Type
    if ($type !== '') {
        $types = array_filter(array_map('trim', explode(',', $type)));
        if (!empty($types)) {
            $ph = [];
            foreach ($types as $i => $t) {
                $key = ":type$i";
                $ph[] = $key;
                $params[$key] = strtoupper($t);
            }
            $where[] = "tr.trnType IN (" . implode(',', $ph) . ")";
        }
    }

    // ── Branch
    if ($branch > 0) {
        $where[] = "EXISTS (
            SELECT 1 FROM trnDetails td_b
            WHERE td_b.trdReference = tr.trnReference
              AND td_b.trdBranch = :branch
        )";
        $params[':branch'] = $branch;
    }

    // ── Search
    if ($search !== '') {
        $where[] = "(
            tr.trnReference LIKE :search
            OR EXISTS (
                SELECT 1 FROM trnDetails td_s
                WHERE td_s.trdReference = tr.trnReference
                  AND td_s.trdNarration LIKE :search
            )
            OR mk.usrName LIKE :search
            OR ck.usrName LIKE :search
        )";
        $params[':search'] = "%$search%";
    }

    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    // ── Total
    try {
        $countSql = "
            SELECT COUNT(DISTINCT tr.trnReference) AS total
            FROM transactions tr
            JOIN users mk ON mk.usrID = tr.trnMaker
            LEFT JOIN users ck ON ck.usrID = tr.trnAuthorizer
            JOIN trnTypes tp ON tp.trntCode = tr.trnType
            $whereSql
        ";
        $countStmt = $conn->prepare($countSql);
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["msg" => "failed", "error" => $e->getMessage()]);
        return;
    }

    // ── Rows
    try {
        $sql = "
            SELECT
                tr.trnReference                 AS reference,
                tr.trnType                      AS typeCode,
                tp.trntName                     AS typeName,
                tr.trnStatus                    AS status,
                tr.trnStateText                 AS statusText,
                mk.usrName                      AS maker,
                ck.usrName                      AS checker,
                GROUP_CONCAT(DISTINCT td.trdCcy ORDER BY td.trdCcy SEPARATOR ', ') AS currencies,
                SUM(CASE WHEN td.trdDrCr = 'Dr' THEN td.trdAmount ELSE 0 END) AS totalDebit,
                SUM(CASE WHEN td.trdDrCr = 'Cr' THEN td.trdAmount ELSE 0 END) AS totalCredit,
                (
                    SELECT td2.trdNarration
                    FROM trnDetails td2
                    WHERE td2.trdReference = tr.trnReference
                      AND td2.trdNarration IS NOT NULL
                      AND td2.trdNarration <> ''
                    LIMIT 1
                )                               AS narration,
                tr.trnEntryDate                 AS entryDate
            FROM transactions tr
            JOIN users mk ON mk.usrID = tr.trnMaker
            LEFT JOIN users ck ON ck.usrID = tr.trnAuthorizer
            JOIN trnTypes tp ON tp.trntCode = tr.trnType
            LEFT JOIN trnDetails td ON td.trdReference = tr.trnReference
            $whereSql
            GROUP BY
                tr.trnReference, tr.trnType, tp.trntName,
                tr.trnStatus, tr.trnStateText,
                mk.usrName, ck.usrName, tr.trnEntryDate
            ORDER BY tr.trnEntryDate DESC
        ";
        $stmt = $conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            "data"  => $rows,
            "total" => $total,
        ], JSON_PRETTY_PRINT);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(["msg" => "failed", "error" => $e->getMessage()]);
    }
}

function validDate(string $d): bool {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}
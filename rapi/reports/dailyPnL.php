<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(["status" => "error", "msg" => "Method Not Allowed"]);
    exit;
}

require_once "../db.php";
require_once "../functions.php";

$db   = new Database();
$conn = $db->getConnection();

$currency = (string) $conn->query(
    "SELECT comLocalCcy FROM companyProfile LIMIT 1"
)->fetchColumn();
if ($currency === '') $currency = 'USD';

try {
    // ── daily revenue / expense / profit, last 30 days ─────────
    // Uses the recursive-CTE pattern so every day appears in the
    // result even when there are no transactions on that day.
    $sql = "
        WITH RECURSIVE days AS (
            SELECT CURDATE() - INTERVAL 29 DAY AS day
            UNION ALL
            SELECT day + INTERVAL 1 DAY FROM days WHERE day < CURDATE()
        ),
        rev AS (
            SELECT DATE(tr.trnEntryDate) AS day,
                   SUM(td.trdAmount * getRate(td.trdCcy, cp.comLocalCcy)) AS total
              FROM trnDetails td
              JOIN transactions  tr ON tr.trnReference = td.trdReference
              JOIN accounts      ac ON ac.accNumber    = td.trdAccount
              CROSS JOIN (SELECT comLocalCcy FROM companyProfile LIMIT 1) cp
             WHERE ac.accCategory IN (9, 10)
               AND td.trdDrCr = 'Cr'
               AND tr.trnStateText = 'Authorized'
               AND tr.trnEntryDate >= CURDATE() - INTERVAL 29 DAY
             GROUP BY DATE(tr.trnEntryDate)
        ),
        exp AS (
            SELECT DATE(tr.trnEntryDate) AS day,
                   SUM(td.trdAmount * getRate(td.trdCcy, cp.comLocalCcy)) AS total
              FROM trnDetails td
              JOIN transactions  tr ON tr.trnReference = td.trdReference
              JOIN accounts      ac ON ac.accNumber    = td.trdAccount
              CROSS JOIN (SELECT comLocalCcy FROM companyProfile LIMIT 1) cp
             WHERE ac.accCategory IN (11, 12)
               AND td.trdDrCr = 'Dr'
               AND tr.trnStateText = 'Authorized'
               AND tr.trnEntryDate >= CURDATE() - INTERVAL 29 DAY
             GROUP BY DATE(tr.trnEntryDate)
        )
        SELECT
            d.day,
            ROUND(COALESCE(r.total, 0), 2) AS revenue,
            ROUND(COALESCE(e.total, 0), 2) AS expense,
            ROUND(COALESCE(r.total, 0) - COALESCE(e.total, 0), 2) AS profit
          FROM days d
          LEFT JOIN rev r ON r.day = d.day
          LEFT JOIN exp e ON e.day = d.day
         ORDER BY d.day
    ";

    $stmt = $conn->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ── shape for the Flutter chart ────────────────────────────
    $series = [];
    $totals = ["revenue" => 0.0, "expense" => 0.0, "profit" => 0.0];

    foreach ($rows as $r) {
        $revenue = (float) $r["revenue"];
        $expense = (float) $r["expense"];
        $profit  = (float) $r["profit"];

        $series[] = [
            "day"     => $r["day"],          // "YYYY-MM-DD"
            "revenue" => $revenue,
            "expense" => $expense,
            "profit"  => $profit,
        ];

        $totals["revenue"] += $revenue;
        $totals["expense"] += $expense;
        $totals["profit"]  += $profit;
    }

    echo json_encode([
        "status"   => "success",
        "currency" => $currency,
        "from"     => $rows[0]["day"]  ?? null,
        "to"       => $rows[count($rows) - 1]["day"] ?? null,
        "series"   => $series,
        "totals"   => [
            "revenue" => round($totals["revenue"], 2),
            "expense" => round($totals["expense"], 2),
            "profit"  => round($totals["profit"],  2),
        ],
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "status"  => "error",
        "message" => $e->getMessage(),
        "line"    => $e->getLine(),
        "file"    => $e->getFile(),
    ]);
}
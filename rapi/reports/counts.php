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

$cashAccounts = "10101010, 10101018";

try {
    // ── counts ──────────────────────────────────────────────────
    $counts = [
        "accounts"     => (int) $conn->query("SELECT COUNT(*) FROM accounts")->fetchColumn(),
        "stakeholders" => (int) $conn->query("SELECT COUNT(*) FROM personal WHERE perID > 1")->fetchColumn(),
        "users"        => (int) $conn->query("SELECT COUNT(*) FROM users WHERE usrStatus = 1")->fetchColumn(),
        "employees"    => (int) $conn->query("SELECT COUNT(*) FROM employees WHERE empStatus = 1")->fetchColumn(),
        "branches"     => (int) $conn->query("SELECT COUNT(*) FROM branch")->fetchColumn(),
    ];

    // ── cash in/out for today (converted) ─────────────────────
    $cashIn = (float) $conn->query("
        SELECT COALESCE(SUM(td.trdAmount * getRate(td.trdCcy, '{$currency}')), 0)
          FROM trnDetails td
          JOIN transactions tr ON tr.trnReference = td.trdReference
         WHERE td.trdAccount  IN ($cashAccounts)
           AND td.trdDrCr     = 'Dr'
           AND tr.trnStateText = 'Authorized'
           AND DATE(tr.trnEntryDate) = CURDATE()
    ")->fetchColumn();

    $cashOut = (float) $conn->query("
        SELECT COALESCE(SUM(td.trdAmount * getRate(td.trdCcy, '{$currency}')), 0)
          FROM trnDetails td
          JOIN transactions tr ON tr.trnReference = td.trdReference
         WHERE td.trdAccount  IN ($cashAccounts)
           AND td.trdDrCr     = 'Cr'
           AND tr.trnStateText = 'Authorized'
           AND DATE(tr.trnEntryDate) = CURDATE()
    ")->fetchColumn();

    // ── finance in / expense / profit (converted) ─────────────
    $finIncome = (float) $conn->query("
        SELECT COALESCE(SUM(td.trdAmount * getRate(td.trdCcy, '{$currency}')), 0)
          FROM trnDetails td
          JOIN transactions    tr ON tr.trnReference = td.trdReference
          JOIN accounts        ac ON ac.accNumber    = td.trdAccount
         WHERE ac.accCategory IN (9, 10)
           AND td.trdDrCr      = 'Cr'
           AND tr.trnStateText = 'Authorized'
           AND DATE(tr.trnEntryDate) = CURDATE()
    ")->fetchColumn();

    $finExpense = (float) $conn->query("
        SELECT COALESCE(SUM(td.trdAmount * getRate(td.trdCcy, '{$currency}')), 0)
          FROM trnDetails td
          JOIN transactions    tr ON tr.trnReference = td.trdReference
          JOIN accounts        ac ON ac.accNumber    = td.trdAccount
         WHERE ac.accCategory IN (11, 12)
           AND td.trdDrCr      = 'Dr'
           AND tr.trnStateText = 'Authorized'
           AND DATE(tr.trnEntryDate) = CURDATE()
    ")->fetchColumn();

    // ── PURCHASE from stock only ──────────────────────────────
    // Sum of IN stock lines from Authorized purchase invoices today.
    // No trnDetails join → no fanout, no double-counting.
    $purchaseStats = $conn->query("
        SELECT
            COALESCE(SUM(sk.stkQuantity * sk.stkQtyInBatch * sk.stkPurPrice), 0) AS total,
            COUNT(DISTINCT o.ordID)                                              AS invoices
          FROM orders o
          JOIN stock        sk ON sk.stkOrder     = o.ordID
          JOIN transactions tr ON tr.trnReference = o.ordTrnRef
         WHERE o.ordName         = 'Purchase'
           AND sk.stkEntryType   = 'IN'
           AND tr.trnStateText   = 'Authorized'
           AND DATE(o.ordEntryDate) = CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);

    // ── SALES from stock only ─────────────────────────────────
    // Sum of OUT stock lines from Authorized sale invoices today.
    $salesStats = $conn->query("
        SELECT
            COALESCE(SUM(sk.stkQuantity * sk.stkQtyInBatch * sk.stkSalePrice), 0) AS total,
            COUNT(DISTINCT o.ordID)                                               AS invoices
          FROM orders o
          JOIN stock        sk ON sk.stkOrder     = o.ordID
          JOIN transactions tr ON tr.trnReference = o.ordTrnRef
         WHERE o.ordName         = 'Sale'
           AND sk.stkEntryType   = 'OUT'
           AND tr.trnStateText   = 'Authorized'
           AND DATE(o.ordEntryDate) = CURDATE()
    ")->fetch(PDO::FETCH_ASSOC);

    // ── response ────────────────────────────────────────────────
    echo json_encode([
        "status"   => "success",
        "counts"   => $counts,
        "cash"     => [
            "in"  => round($cashIn, 2),
            "out" => round($cashOut, 2),
            "net" => round($cashIn - $cashOut, 2),
        ],
        "finance"  => [
            "income"  => round($finIncome, 2),
            "expense" => round($finExpense, 2),
            "profit"  => round($finIncome - $finExpense, 2),
        ],
        "invoice"  => [
            "purchase"         => round((float) ($purchaseStats['total']    ?? 0), 2),
            "purchaseInvoices" => (int)  ($purchaseStats['invoices'] ?? 0),
            "sales"            => round((float) ($salesStats['total']    ?? 0), 2),
            "salesInvoices"    => (int)  ($salesStats['invoices'] ?? 0),
        ],
        "currency" => $currency,
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
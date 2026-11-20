<?php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once "../db.php";
require_once "../functions.php";

$db   = new Database();
$conn = $db->getConnection();

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["message" => "Method Not Allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true) ?: [];

$name        = trim($input['name']         ?? '');
$ccy         = strtoupper(trim($input['ccy'] ?? ''));
$type        = strtolower(trim($input['type'] ?? '')); // 'ar' | 'ap' | ''
$includeZero = (bool)($input['include_zero'] ?? false);

if (!in_array($type, ['ar', 'ap', ''], true)) {
    http_response_code(400);
    echo json_encode([
        "success" => false,
        "msg"     => "Invalid type. Use 'ar', 'ap', or leave empty for both."
    ]);
    exit;
}

try {
    $response = [
        "success" => true,
        "filters" => [
            "name"         => $name,
            "ccy"          => $ccy,
            "type"         => $type,
            "include_zero" => $includeZero,
        ],
    ];

    // ---- Receivables (stakeholders with a NEGATIVE balance owe us) ----
    if ($type === '' || $type === 'ar') {
        $response['receivables'] = fetch_stakeholders($conn, $name, $ccy, $includeZero, 'RECEIVABLE');
        $response['totals']['receivables'] = sum_by_currency($response['receivables']);
    }

    // ---- Payables (stakeholders with a POSITIVE balance we owe) ----
    if ($type === '' || $type === 'ap') {
        $response['payables'] = fetch_stakeholders($conn, $name, $ccy, $includeZero, 'PAYABLE');
        $response['totals']['payables'] = sum_by_currency($response['payables']);
    }

    echo json_encode($response, JSON_PRETTY_PRINT);

} catch (PDOException $th) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "msg"     => "failed",
        "error"   => $th->getMessage(),
        "line"    => $th->getLine(),
        "file"    => $th->getFile()
    ]);
}

/**
 *  RECEIVABLE => accBalance < 0   (or <= 0 when includeZero)  → they owe us
 *  PAYABLE    => accBalance > 0   (or >= 0 when includeZero)  → we owe them
 */
function fetch_stakeholders(
    PDO $conn,
    string $name,
    string $ccy,
    bool $includeZero,
    string $type
): array {
    $sql = "SELECT * FROM vw_personalAccounts WHERE 1=1";

    if ($type === 'RECEIVABLE') {
        // Negative balance = they owe us.
        $sql .= $includeZero ? " AND accBalance <= 0" : " AND accBalance < 0";
    } else { // PAYABLE
        // Positive balance = we owe them.
        $sql .= $includeZero ? " AND accBalance >= 0" : " AND accBalance > 0";
    }

    $params = [];

    if ($name !== '') {
        $sql .= " AND fullName LIKE :fName";
        $params[':fName'] = "%{$name}%";
    }

    if ($ccy !== '') {
        $sql .= " AND accCurrency = :ccy";
        $params[':ccy'] = $ccy;
    }

    $sql .= " ORDER BY ABS(accBalance) DESC, fullName ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Sum balances per currency, returned as positive numbers. */
function sum_by_currency(array $rows): array
{
    $out = [];
    foreach ($rows as $r) {
        $c = $r['accCurrency'] ?? 'UNK';
        $out[$c] = ($out[$c] ?? 0) + (float)$r['accBalance'];
    }
    foreach ($out as $k => $v) { $out[$k] = abs(round($v, 2)); }
    return $out;
}
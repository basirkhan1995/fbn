<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once "../../vendor/autoload.php";    // PhpSpreadsheet
require_once "../db.php";                    // Database class
require_once "../functions.php";             // DataValues (if you need it)

use PhpOffice\PhpSpreadsheet\IOFactory;

// ---------- Bootstrap ----------
$db = new Database();
$conn = $db->getConnection();

// ---------- Routing ----------
$request_method = $_SERVER["REQUEST_METHOD"];

if ($request_method !== 'POST') {
    http_response_code(405);
    echo json_encode(["msg" => "failed", "error" => "Method Not Allowed"]);
    exit;
}

if (!isset($_FILES['excelFile'])) {
    http_response_code(400);
    echo json_encode([
        "msg"   => "failed",
        "error" => "No file uploaded (field 'excelFile' missing)",
        "files" => array_keys($_FILES),
    ]);
    exit;
}

// ---------- Go ----------
add_product_excel($conn);

// ============================================================
//                    FUNCTION DEFINITION
// ============================================================
function add_product_excel($conn) {

    if ($_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            "msg"   => "failed",
            "error" => "Upload error",
            "code"  => $_FILES['excelFile']['error']
        ]);
        return;
    }

    $file = $_FILES['excelFile']['tmp_name'];
    $ext  = strtolower(pathinfo($_FILES['excelFile']['name'], PATHINFO_EXTENSION));

    if (!in_array($ext, ['xlsx', 'xls'])) {
        http_response_code(400);
        echo json_encode(["msg" => "failed", "error" => "Only .xlsx or .xls allowed"]);
        return;
    }

    try {
        $spreadsheet = IOFactory::load($file);
        $sheet = $spreadsheet->getActiveSheet();
        $rows  = $sheet->toArray(null, true, true, false);

        // ── Sheet must have at least a header + 1 data row ────────
        if (count($rows) < 2) {
            echo json_encode([
                "msg"   => "failed",
                "error" => "Excel file has no data rows (only header, or empty)",
                "empty" => true,
            ]);
            return;
        }

        // ---- Header row ----
        // Null-safe trim — avoids "trim(): Passing null" deprecation on empty header cells
        $rawHeader = array_map(
            fn($v) => trim((string)($v ?? '')),
            $rows[0]
        );
        $header    = array_map('normalize_header', $rawHeader);
        $colMap    = array_flip($header);

        $required = ['proName', 'proCategory'];
        foreach ($required as $r) {
            if (!isset($colMap[$r])) {
                echo json_encode([
                    "msg"   => "failed",
                    "error" => "Missing required column: $r",
                    "found" => $rawHeader
                ]);
                return;
            }
        }

        $inserted = 0;
        $skipped  = [];
        $errors   = [];

        $conn->beginTransaction();

        $checkStmt = $conn->prepare(
            "SELECT count(*) FROM product WHERE proCode = ? OR proName = ?"
        );
        $codeExistsStmt = $conn->prepare(
            "SELECT count(*) FROM product WHERE proCode = ?"
        );
        $insertStmt = $conn->prepare(
            "INSERT INTO product
                (proName, proCode, proUnit, proBrand, proMadeIn, proModel, proGrade,
                 proCategory, proDetails, proLSNQty, proColor, proStatus, proSPP)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];

            // Skip fully blank rows
            if (count(array_filter($row, fn($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            $get = function($key) use ($colMap, $row) {
                return isset($colMap[$key])
                    ? trim((string)($row[$colMap[$key]] ?? ''))
                    : '';
            };

            $name     = $get('proName');
            $category = $get('proCategory');
            $code     = $get('proCode');

            // Only proName and proCategory are required
            if ($name === '') {
                $skipped[] = ["row" => $i + 1, "reason" => "Missing proName"];
                continue;
            }
            if ($category === '') {
                $skipped[] = ["row" => $i + 1, "reason" => "Missing proCategory"];
                continue;
            }

            // Auto-generate proCode when empty
            if ($code === '') {
                $code = generate_product_code($codeExistsStmt);
            }

            // Duplicate check (same code OR same name)
            $checkStmt->execute([$code, $name]);
            if ($checkStmt->fetchColumn() > 0) {
                $skipped[] = [
                    "row" => $i + 1,
                    "reason" => "Duplicate code/name: $code / $name"
                ];
                continue;
            }

            try {
                $insertStmt->execute([
                    $name,
                    $code,
                    $get('proUnit'),
                    $get('proBrand'),
                    $get('proMadeIn'),
                    $get('proModel'),
                    $get('proGrade'),
                    $category,
                    $get('proDetails'),
                    $get('proLSNQty') !== '' ? $get('proLSNQty') : null,
                    $get('proColor'),
                    1,                                   // proStatus always 1 (active)
                    $get('proSPP')    !== '' ? $get('proSPP')    : null,
                ]);
                $inserted++;
            } catch (\Throwable $e) {
                $errors[] = ["row" => $i + 1, "error" => $e->getMessage()];
            }
        }

        // ── Nothing imported? ─────────────────────────────────────
        if ($inserted === 0 && empty($skipped) && empty($errors)) {
            $conn->rollBack();
            echo json_encode([
                "msg"   => "failed",
                "error" => "No data rows found in the Excel file",
                "empty" => true,
            ], JSON_PRETTY_PRINT);
            return;
        }

        $conn->commit();

        echo json_encode([
            "msg"      => "success",
            "inserted" => $inserted,
            "skipped"  => $skipped,
            "errors"   => $errors
        ], JSON_PRETTY_PRINT);

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

// ============================================================
//                    HEADER NORMALIZER
// ============================================================
/**
 * Maps user-friendly Excel column headers to internal proXXX keys.
 * Case-insensitive, ignores spaces / underscores / dashes.
 * Falls back to the original header if no alias matches —
 * so old templates with `proName` etc. still work.
 */
function normalize_header($raw) {
    $key = strtolower(str_replace([' ', '_', '-'], '', (string)$raw));

    $aliases = [
        // Product Name
        'productname'    => 'proName',
        'name'           => 'proName',
        'proname'        => 'proName',

        // Product Code
        'productcode'    => 'proCode',
        'code'           => 'proCode',
        'procode'        => 'proCode',
        'sku'            => 'proCode',

        // Unit
        'unit'           => 'proUnit',
        'prounit'        => 'proUnit',

        // Brand
        'brand'          => 'proBrand',
        'probrand'       => 'proBrand',

        // Made In
        'madein'         => 'proMadeIn',
        'origin'         => 'proMadeIn',
        'country'        => 'proMadeIn',
        'promadein'      => 'proMadeIn',

        // Model
        'model'          => 'proModel',
        'promodel'       => 'proModel',

        // Grade
        'grade'          => 'proGrade',
        'prograde'       => 'proGrade',

        // Category
        'category'       => 'proCategory',
        'categoryid'     => 'proCategory',
        'cat'            => 'proCategory',
        'procategory'    => 'proCategory',

        // Details / Description
        'details'        => 'proDetails',
        'description'    => 'proDetails',
        'desc'           => 'proDetails',
        'prodetails'     => 'proDetails',

        // Minimum Stock / Low Stock Notification Qty
        'minimumstock'   => 'proLSNQty',
        'minstock'       => 'proLSNQty',
        'minqty'         => 'proLSNQty',
        'alertqty'       => 'proLSNQty',
        'lowsockqty'     => 'proLSNQty',
        'prolsnqty'      => 'proLSNQty',

        // Color
        'color'          => 'proColor',
        'colour'         => 'proColor',
        'procolor'       => 'proColor',

        // Sale Price (SPP)
        'saleprice'      => 'proSPP',
        'price'          => 'proSPP',
        'spp'            => 'proSPP',
        'prospp'         => 'proSPP',
        'sellingprice'   => 'proSPP',
        'defaultprice'   => 'proSPP',
    ];

    return $aliases[$key] ?? $raw;   // fallback: keep original header
}

// ============================================================
//                    CODE GENERATOR
// ============================================================
/**
 * Generates a unique product code in Flutter's format: PRD-YYMM-XXX
 * Retries on collision. Uses only safe chars (no I, O, 0, 1).
 */
function generate_product_code($codeExistsStmt) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $date  = date('ym'); // YYMM

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $batch = '';
        for ($i = 0; $i < 3; $i++) {
            $batch .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $code = "PRD-$date-$batch";

        $codeExistsStmt->execute([$code]);
        if ($codeExistsStmt->fetchColumn() == 0) {
            return $code;
        }
    }

    // Extremely unlikely fallback
    return "PRD-$date-" . strtoupper(substr(md5(uniqid('', true)), 0, 3));
}
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
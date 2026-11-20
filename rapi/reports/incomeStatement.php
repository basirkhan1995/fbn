<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once "../db.php";
require_once "../functions.php";

$db = new Database();
$conn = $db->getConnection();
$data = json_decode(file_get_contents("php://input"), true);

if (!isset($data['startDate']) || !isset($data['endDate'])) {
    http_response_code(400);
    echo json_encode(["error" => "startDate and endDate required"]);
    exit;
}

$startDate = $data['startDate'];
$endDate = $data['endDate'];

try {
    // Using the procedure name: sp_income_statement
    $stmt = $conn->prepare("CALL sp_income_statement(?, ?)");
    $stmt->execute([$startDate, $endDate]);
    
    $incomeStatement = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $stmt->closeCursor();
    
    // Clean the data - remove null/empty values
    $cleanStatement = array_map(function($item) {
        return array_filter($item, function($value) {
            return $value !== null && $value !== '';
        });
    }, $incomeStatement);
    
    echo json_encode($cleanStatement, JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
    
} catch (PDOException $th) {
    http_response_code(500);
    echo json_encode([
        "status" => "error",
        "message" => "Failed to generate income statement",
        "error" => $th->getMessage()
    ]);
}
<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once "../db.php";

function backup_database_pure_php() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        if (!$conn) {
            throw new Exception("Database connection failed");
        }
        
        $dbName = "u825845895_fbndatabase";
        
        // Get all tables AND views with their types
        $stmt = $conn->query("SELECT TABLE_NAME, TABLE_TYPE 
                              FROM information_schema.TABLES 
                              WHERE TABLE_SCHEMA = '$dbName' 
                              ORDER BY TABLE_TYPE, TABLE_NAME");
        $allObjects = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($allObjects)) {
            throw new Exception("No tables or views found in database");
        }
        
        // Separate tables and views
        $tables = [];
        $views = [];
        foreach ($allObjects as $obj) {
            if ($obj['TABLE_TYPE'] === 'BASE TABLE') {
                $tables[] = $obj['TABLE_NAME'];
            } elseif ($obj['TABLE_TYPE'] === 'VIEW') {
                $views[] = $obj['TABLE_NAME'];
            }
        }
        
        $sql = "-- Database backup: {$dbName}\n";
        $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- Tables: " . (count($tables) > 0 ? implode(', ', $tables) : 'None') . "\n";
        $sql .= "-- Views: " . (count($views) > 0 ? implode(', ', $views) : 'None') . "\n\n";
        $sql .= "SET FOREIGN_KEY_CHECKS=0;\n";
        $sql .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $sql .= "START TRANSACTION;\n\n";
        
        // Backup TABLES (structure + data)
        if (!empty($tables)) {
            $sql .= "-- ============================================\n";
            $sql .= "-- TABLES STRUCTURE & DATA\n";
            $sql .= "-- ============================================\n\n";
            
            foreach ($tables as $table) {
                // Get create table statement
                $stmt = $conn->query("SHOW CREATE TABLE `{$table}`");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $sql .= "-- Table structure for `{$table}`\n";
                $sql .= "DROP TABLE IF EXISTS `{$table}`;\n";
                $sql .= $row['Create Table'] . ";\n\n";
                
                // Get table data
                $stmt = $conn->query("SELECT * FROM `{$table}`");
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($rows)) {
                    $sql .= "-- Dumping data for `{$table}`\n";
                    $sql .= "LOCK TABLES `{$table}` WRITE;\n";
                    $sql .= "ALTER TABLE `{$table}` DISABLE KEYS;\n";
                    
                    foreach ($rows as $row) {
                        $values = array_map(function($value) use ($conn) {
                            if ($value === null) {
                                return 'NULL';
                            }
                            return $conn->quote($value);
                        }, array_values($row));
                        
                        $sql .= "INSERT INTO `{$table}` VALUES (" . implode(', ', $values) . ");\n";
                    }
                    
                    $sql .= "ALTER TABLE `{$table}` ENABLE KEYS;\n";
                    $sql .= "UNLOCK TABLES;\n\n";
                } else {
                    $sql .= "-- Table `{$table}` has no data\n\n";
                }
            }
        }
        
        // Backup VIEWS (structure only - no data)
        if (!empty($views)) {
            $sql .= "-- ============================================\n";
            $sql .= "-- VIEWS STRUCTURE\n";
            $sql .= "-- ============================================\n\n";
            
            foreach ($views as $view) {
                $stmt = $conn->query("SHOW CREATE VIEW `{$view}`");
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $sql .= "-- View structure for `{$view}`\n";
                $sql .= "DROP VIEW IF EXISTS `{$view}`;\n";
                $sql .= $row['Create View'] . ";\n\n";
            }
        }
        
        $sql .= "SET FOREIGN_KEY_CHECKS=1;\n";
        $sql .= "COMMIT;\n";
        
        // Generate filename
        $timestamp = date("Ymd_His");
        $filename = "backup_{$dbName}_data_{$timestamp}.sql";
        
        // Send as download
        header("Content-Description: File Transfer");
        header("Content-Type: application/octet-stream");
        header("Content-Disposition: attachment; filename=\"{$filename}\"");
        header("Content-Transfer-Encoding: binary");
        header("Content-Length: " . strlen($sql));
        header("Cache-Control: must-revalidate");
        header("Pragma: public");
        header("Expires: 0");
        
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        echo $sql;
        exit;
        
    } catch (Exception $e) {
        header("Content-Type: application/json; charset=UTF-8");
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => $e->getMessage()
        ]);
        exit;
    }
}

backup_database_pure_php();
?>
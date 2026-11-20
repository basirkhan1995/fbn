<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once "../db.php";
$db = new Database();
$conn = $db->getConnection();
$request_method = $_SERVER["REQUEST_METHOD"];

switch ($request_method) {
    case 'POST':
        upload_productImage();
        break;
    default:
        http_response_code(405);
        echo json_encode(array("message" => "Method Not Allowed"));
        break;
}

function upload_productImage() {
    global $conn;

    try {
        // Validate product ID
        if (!isset($_POST['proID']) || empty($_POST['proID'])) {
            http_response_code(400);
            echo json_encode([
                "status" => "error", 
                "message" => "Product ID is required"
            ]);
            return;
        }

        $proID = intval($_POST['proID']);

        // Check if files are uploaded
        if (!isset($_FILES['images'])) {
            http_response_code(400);
            echo json_encode([
                "status" => "error", 
                "message" => "No images uploaded"
            ]);
            return;
        }

        // Normalize the files array
        $files = normalizeFiles($_FILES['images']);
        
        if (empty($files)) {
            http_response_code(400);
            echo json_encode([
                "status" => "error", 
                "message" => "No valid files uploaded"
            ]);
            return;
        }

        // Check current image count for this product (max 5)
        $countStmt = $conn->prepare("SELECT COUNT(*) as total FROM productImages WHERE piProID = ?");
        $countStmt->execute([$proID]);
        $currentCount = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        $maxImages = 5;
        $remainingSlots = $maxImages - $currentCount;
        
        if ($remainingSlots <= 0) {
            http_response_code(400);
            echo json_encode([
                "status" => "error", 
                "message" => "Maximum 5 images allowed per product"
            ]);
            return;
        }

        // Setup upload directory
        $uploadDir = realpath(__DIR__ . '/../../images/product');
        if (!$uploadDir) {
            $uploadDir = __DIR__ . '/../../images/product';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            $uploadDir = realpath($uploadDir);
        }

        if (!$uploadDir || !is_writable($uploadDir)) {
            throw new Exception("Upload directory is not writable");
        }

        $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $maxFileSize = 5 * 1024 * 1024; // 5MB

        // Prepare insert statement
        $stmt = $conn->prepare("INSERT INTO productImages (piProID, piName) VALUES (?, ?)");
        
        $uploaded = [];
        $failed = [];

        // Limit upload to remaining slots
        $uploadCount = min(count($files), $remainingSlots);

        for ($i = 0; $i < $uploadCount; $i++) {
            // Check for upload errors
            if ($files[$i]['error'] !== UPLOAD_ERR_OK) {
                $failed[] = [
                    'file' => $files[$i]['name'],
                    'error' => uploadErrorMessage($files[$i]['error'])
                ];
                continue;
            }

            // Check file size
            if ($files[$i]['size'] > $maxFileSize) {
                $failed[] = [
                    'file' => $files[$i]['name'],
                    'error' => "File size exceeds 5MB limit"
                ];
                continue;
            }

            // Validate file extension
            $ext = strtolower(pathinfo($files[$i]['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt)) {
                $failed[] = [
                    'file' => $files[$i]['name'],
                    'error' => "Invalid file type. Allowed: " . implode(', ', $allowedExt)
                ];
                continue;
            }

            // Validate MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mimeType = finfo_file($finfo, $files[$i]['tmp_name']);
            finfo_close($finfo);
            
            $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
            if (!in_array($mimeType, $allowedMimes)) {
                $failed[] = [
                    'file' => $files[$i]['name'],
                    'error' => "Invalid MIME type: " . $mimeType
                ];
                continue;
            }

            // Generate unique filename
            $newName = time() . '_' . uniqid() . '.' . $ext;
            $uploadPath = $uploadDir . '/' . $newName;

            // Move uploaded file
            if (move_uploaded_file($files[$i]['tmp_name'], $uploadPath)) {
                try {
                    $stmt->execute([$proID, $newName]);
                    $uploaded[] = [
                        'original_name' => $files[$i]['name'],
                        'filename' => $newName,
                        'path' => 'images/product/' . $newName
                    ];
                } catch (PDOException $e) {
                    unlink($uploadPath);
                    $failed[] = [
                        'file' => $files[$i]['name'],
                        'error' => "Database error: " . $e->getMessage()
                    ];
                }
            } else {
                $failed[] = [
                    'file' => $files[$i]['name'],
                    'error' => "Failed to move uploaded file"
                ];
            }
        }

        // Prepare response
        if (count($uploaded) > 0) {
            $response = [
                "status" => "success",
                "message" => count($uploaded) . " image(s) uploaded successfully",
                "data" => [
                    "uploaded" => $uploaded,
                    "total_uploaded" => $currentCount + count($uploaded),
                    "remaining_slots" => $maxImages - ($currentCount + count($uploaded))
                ]
            ];

            if (!empty($failed)) {
                $response["status"] = "partial";
                $response["message"] = count($uploaded) . " image(s) uploaded, " . count($failed) . " failed";
                $response["data"]["failed"] = $failed;
            }

            http_response_code(200);
            echo json_encode($response);
        } else {
            http_response_code(400);
            echo json_encode([
                "status" => "error",
                "message" => "No files uploaded",
                "data" => [
                    "failed" => $failed
                ]
            ]);
        }

    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode([
            "status" => "error",
            "message" => "Server error: " . $th->getMessage()
        ]);
    }
}

// Helper function to normalize $_FILES array
function normalizeFiles($fileData) {
    $files = [];
    
    if (!isset($fileData['name'])) {
        return $files;
    }

    // Check if it's an array of files
    if (is_array($fileData['name'])) {
        foreach ($fileData['name'] as $index => $name) {
            if (empty($name) && $fileData['error'][$index] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            
            $files[] = [
                'name' => $name,
                'type' => $fileData['type'][$index] ?? '',
                'tmp_name' => $fileData['tmp_name'][$index] ?? '',
                'error' => $fileData['error'][$index] ?? UPLOAD_ERR_NO_FILE,
                'size' => $fileData['size'][$index] ?? 0
            ];
        }
    } else {
        // Single file upload
        if (!empty($fileData['name']) && $fileData['error'] !== UPLOAD_ERR_NO_FILE) {
            $files[] = [
                'name' => $fileData['name'],
                'type' => $fileData['type'],
                'tmp_name' => $fileData['tmp_name'],
                'error' => $fileData['error'],
                'size' => $fileData['size']
            ];
        }
    }
    
    return $files;
}

function uploadErrorMessage($errorCode) {
    switch ($errorCode) {
        case UPLOAD_ERR_INI_SIZE:
            return "File exceeds server upload limit";
        case UPLOAD_ERR_FORM_SIZE:
            return "File exceeds form upload limit";
        case UPLOAD_ERR_PARTIAL:
            return "File was only partially uploaded";
        case UPLOAD_ERR_NO_FILE:
            return "No file was uploaded";
        case UPLOAD_ERR_NO_TMP_DIR:
            return "Missing temporary folder";
        case UPLOAD_ERR_CANT_WRITE:
            return "Failed to write file to disk";
        case UPLOAD_ERR_EXTENSION:
            return "File upload stopped by extension";
        default:
            return "Unknown upload error";
    }
}
?>
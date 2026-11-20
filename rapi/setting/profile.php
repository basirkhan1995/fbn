<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, PUT, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../functions.php';

$db     = new Database();
$conn   = $db->getConnection();
$value  = new DataValues($conn);

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

switch ($method) {
    case 'GET':
        get_userProfile();
        break;

    case 'PUT':
        // JSON PUT — update text fields / address / password
        update_userProfile();
        break;

    case 'POST':
        // multipart POST — photo upload only
        if ($action === 'photo') {
            upload_userPhoto();
        } else {
            http_response_code(400);
            echo json_encode([
                "msg"   => "failed",
                "error" => "unknown_post_action",
            ]);
        }
        break;

    default:
        http_response_code(405);
        header('Allow: GET, PUT, POST, OPTIONS');
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

// ═════════════════════════════════════════════════════════════
// GET /setting/profile.php?usrName=<username>
//
//   Returns:
//     {
//       "msg": "success",
//       "user":     { usrID, usrName, usrEmail, usrStatus, … },
//       "personal": { perID, perName, …, perPhoto: "1793.jpg" | null },
//       "address":  { addID, … } | null
//     }
//
//   perPhoto is the FILENAME only (never base64, never blob).
// ═════════════════════════════════════════════════════════════
function get_userProfile() {
    global $conn;

    $usrName = $_GET['usrName'] ?? null;
    if (!$usrName) {
        http_response_code(400);
        echo json_encode(["msg" => "failed", "error" => "missing_usrName"]);
        return;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT
                u.usrID, u.usrName, u.usrEmail, u.usrStatus, u.usrFCP,
                u.usrToken, u.usrEntryDate, u.usrOwner AS perID,
                p.perName, p.perLastName, p.perGender, p.perDoB,
                p.perENIDNo, p.perAddress, p.perPhone,
                p.perEmail AS perEmail, p.perPhoto
             FROM users u
             JOIN personal p ON p.perID = u.usrOwner
             WHERE u.usrName = ?"
        );
        $stmt->execute([$usrName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(["msg" => "failed", "error" => "not_found"]);
            return;
        }

        // Address
        $address = null;
        if (!empty($row['perAddress'])) {
            $stmt2 = $conn->prepare(
                "SELECT addID, addName, addCity, addProvince,
                        addCountry, addZipCode, addMailing
                 FROM address
                 WHERE addID = ?"
            );
            $stmt2->execute([$row['perAddress']]);
            $address = $stmt2->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        // Photo — return FILENAME only. If the column somehow contains
        // something that looks like binary/base64 (legacy data), blank it.
        $photoName = null;
        if (!empty($row['perPhoto'])) {
            $raw = $row['perPhoto'];
            // A normal filename is short and matches the safe pattern.
            if (
                is_string($raw)
                && strlen($raw) < 512
                && preg_match('/^[\w\-. ]+$/', $raw)
            ) {
                $photoName = $raw;
            }
            // else: corrupted row — return null so the client uses placeholder
        }

        $userBlock = [
            "usrID"        => (int)$row['usrID'],
            "usrName"      => $row['usrName'],
            "usrEmail"     => $row['usrEmail'],
            "usrStatus"    => (int)$row['usrStatus'],
            "usrFCP"       => (int)$row['usrFCP'],
            "usrToken"     => $row['usrToken'],
            "usrEntryDate" => $row['usrEntryDate'],
        ];

        $personalBlock = [
            "perID"       => (int)$row['perID'],
            "perName"     => $row['perName'],
            "perLastName" => $row['perLastName'],
            "perGender"   => $row['perGender'],
            "perDoB"      => $row['perDoB'],
            "perENIDNo"   => $row['perENIDNo'],
            "perPhone"    => $row['perPhone'],
            "perEmail"    => $row['perEmail'],
            "perPhoto"    => $photoName,   // ← filename only, e.g. "1793.jpg"
        ];

        echo json_encode([
            "msg"      => "success",
            "user"     => $userBlock,
            "personal" => $personalBlock,
            "address"  => $address,
        ], JSON_PRETTY_PRINT);

    } catch (PDOException $th) {
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ═════════════════════════════════════════════════════════════
// PUT /setting/profile.php
//
//   Body (JSON) — all fields optional except usrName:
//   {
//     "usrName": "basir",
//     "perName": "Basir", "perLastName": "Ahmad",
//     "perPhone": "...", "perEmail": "...",
//     "perGender": "Male", "perDoB": "1990-05-14",
//     "perENIDNo": "...",
//     "oldPassword": "...", "newPassword": "...",
//     "address": { "addID": 3, "addName": "...", … }
//   }
//
//   NOTE: photo is NOT updated here — use POST ?action=photo.
// ═════════════════════════════════════════════════════════════
function update_userProfile() {
    global $conn, $value;

    $data = json_decode(file_get_contents("php://input"), true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["msg" => "failed", "error" => "invalid_json"]);
        return;
    }

    $usrName     = $data['usrName']     ?? null;
    $perName     = $data['perName']     ?? null;
    $perLastName = $data['perLastName'] ?? null;
    $perPhone    = $data['perPhone']    ?? null;
    $perEmail    = $data['perEmail']    ?? null;
    $perGender   = $data['perGender']   ?? null;
    $perDoB      = $data['perDoB']      ?? null;
    $perENIDNo   = $data['perENIDNo']   ?? null;
    $oldPassword = $data['oldPassword'] ?? null;
    $newPassword = $data['newPassword'] ?? null;
    $address     = $data['address']     ?? null;

    if (empty($usrName)) {
        http_response_code(400);
        echo json_encode(["msg" => "failed", "error" => "missing_usrName"]);
        return;
    }

    try {
        $stmt0 = $conn->prepare(
            "SELECT usrID, usrPass, usrOwner AS perID
             FROM users WHERE usrName = ?"
        );
        $stmt0->execute([$usrName]);
        $current = $stmt0->fetch(PDO::FETCH_ASSOC);

        if (!$current) {
            http_response_code(404);
            echo json_encode(["msg" => "failed", "error" => "not_found"]);
            return;
        }

        $usrID = (int)$current['usrID'];
        $perID = (int)$current['perID'];

        // ── Password change validation ────────────────────
        $passChanged = false;
        if (!empty($newPassword)) {
            if (empty($oldPassword)) {
                http_response_code(400);
                echo json_encode([
                    "msg"   => "failed",
                    "error" => "old_password_required",
                ]);
                return;
            }
            if (!password_verify($oldPassword, $current['usrPass'])) {
                http_response_code(400);
                echo json_encode([
                    "msg"   => "failed",
                    "error" => "incorrect_old_password",
                ]);
                return;
            }
            if (strlen($newPassword) < 6) {
                http_response_code(400);
                echo json_encode([
                    "msg"   => "failed",
                    "error" => "new_password_too_short",
                ]);
                return;
            }
            $passChanged = true;
        }

        $conn->beginTransaction();

        // ── Personal (text fields only — no photo) ────────
        $sets   = [];
        $params = [];

        if ($perName !== null)     { $sets[] = "perName = ?";     $params[] = $perName; }
        if ($perLastName !== null) { $sets[] = "perLastName = ?"; $params[] = $perLastName; }
        if ($perGender !== null)   { $sets[] = "perGender = ?";   $params[] = $perGender; }
        if ($perDoB !== null)      { $sets[] = "perDoB = ?";      $params[] = $perDoB; }
        if ($perENIDNo !== null)   { $sets[] = "perENIDNo = ?";   $params[] = $perENIDNo; }
        if ($perPhone !== null)    { $sets[] = "perPhone = ?";    $params[] = $perPhone; }
        if ($perEmail !== null)    { $sets[] = "perEmail = ?";    $params[] = $perEmail; }

        if (!empty($sets)) {
            $params[] = $perID;
            $conn->prepare(
                "UPDATE personal SET " . implode(', ', $sets) . " WHERE perID = ?"
            )->execute($params);
        }

        // ── Address ──────────────────────────────────────
        if (is_array($address) && !empty($address['addID'])) {
            $conn->prepare(
                "UPDATE address
                    SET addName = ?, addCity = ?, addProvince = ?,
                        addCountry = ?, addZipCode = ?, addMailing = ?
                  WHERE addID = ?"
            )->execute([
                $address['addName']     ?? '',
                $address['addCity']     ?? '',
                $address['addProvince'] ?? '',
                $address['addCountry']  ?? '',
                $address['addZipCode']  ?? '',
                isset($address['addMailing']) ? (int)$address['addMailing'] : 0,
                (int)$address['addID'],
            ]);
        }

        // ── Password ──────────────────────────────────────
        if ($passChanged) {
            $newHash = password_hash($newPassword, PASSWORD_ARGON2ID);
            $conn->prepare(
                "UPDATE users SET usrPass = ?, usrFCP = 0 WHERE usrID = ?"
            )->execute([$newHash, $usrID]);

            $value->generateUserActivityLog(
                $usrName, 'Profile Update',
                "Password changed from Profile page"
            );
        }

        $conn->commit();

        if (!empty($sets) || is_array($address)) {
            $value->generateUserActivityLog(
                $usrName, 'Profile Update',
                "Personal info / address updated"
            );
        }

        // Return fresh profile
        $_GET['usrName'] = $usrName;
        get_userProfile();

    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ═════════════════════════════════════════════════════════════
// POST /setting/profile.php?action=photo
//
//   multipart/form-data:
//     usrName : the username
//     image   : the file
//
//   Saves the file to public_html/images/personal/<name>.<ext>
//   and stores ONLY the filename in personal.perPhoto.
// ═════════════════════════════════════════════════════════════
function upload_userPhoto() {
    global $conn, $value;

    if (empty($_POST['usrName']) || !isset($_FILES['image'])) {
        http_response_code(400);
        echo json_encode([
            "msg"   => "failed",
            "error" => "usrName_or_file_missing",
        ]);
        return;
    }

    $usrName = $_POST['usrName'];
    $file    = $_FILES['image'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(["msg" => "failed", "error" => "upload_error",
                          "code" => $file['error']]);
        return;
    }
    if ($file['size'] === 0) {
        echo json_encode(["msg" => "failed", "error" => "empty_file"]);
        return;
    }
    if ($file['size'] > 4 * 1024 * 1024) {
        echo json_encode(["msg" => "failed", "error" => "file_too_large"]);
        return;
    }

    $mime = mime_content_type($file['tmp_name']) ?: '';
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(["msg" => "failed", "error" => "invalid_image_type",
                          "mime" => $mime]);
        return;
    }
    $ext = $allowed[$mime];

    // public_html/images/personal  (two levels up from rapi/setting)
    $uploadDir = realpath(__DIR__ . '/../../images/personal');
    if ($uploadDir === false || !is_dir($uploadDir)) {
        echo json_encode(["msg" => "failed", "error" => "upload_dir_not_found"]);
        return;
    }
    if (!is_writable($uploadDir)) {
        echo json_encode(["msg" => "failed", "error" => "upload_dir_not_writable"]);
        return;
    }

    try {
        // Look up the personal row for this user
        $stmt = $conn->prepare(
            "SELECT u.usrOwner AS perID, p.perPhoto
             FROM users u
             JOIN personal p ON p.perID = u.usrOwner
             WHERE u.usrName = ?"
        );
        $stmt->execute([$usrName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo json_encode(["msg" => "failed", "error" => "not_found"]);
            return;
        }

        $perID   = (int)$row['perID'];
        $oldName = $row['perPhoto'];

        // Save the new file
        $newName = time() . '_' . $perID . '.' . $ext;
        $target  = $uploadDir . '/' . $newName;

        if (!move_uploaded_file($file['tmp_name'], $target)) {
            echo json_encode(["msg" => "failed", "error" => "move_failed"]);
            return;
        }

        // Store FILENAME ONLY in perPhoto
        $conn->prepare(
            "UPDATE personal SET perPhoto = ? WHERE perID = ?"
        )->execute([$newName, $perID]);

        // Best-effort delete of the old file
        if (!empty($oldName)) {
            $oldPath = $uploadDir . '/' . $oldName;
            if (is_file($oldPath)) @unlink($oldPath);
        }

        $value->generateUserActivityLog(
            $usrName, 'Profile Update', 'Photo changed'
        );

        echo json_encode([
            "msg"   => "success",
            "photo" => $newName,   // ← filename only, e.g. "1700123456_2.jpg"
        ]);

    } catch (\Throwable $th) {
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}
<?php

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(["ok" => false, "msg" => "Method Not Allowed"]);
    exit;
}

require_once "../db.php";

$db   = new Database();
$conn = $db->getConnection();

$input = json_decode(file_get_contents("php://input"), true) ?: [];

$perID    = isset($input['perID'])    ? (int)    $input['perID']    : 0;
$username = isset($input['username']) ? trim((string)$input['username']) : '';
$password = isset($input['password']) ? (string) $input['password'] : '';

if ($perID <= 0) {
    echo json_encode(["ok" => false, "msg" => "perID is required."]);
    exit;
}
if ($username === '' || $password === '') {
    echo json_encode(["ok" => false, "msg" => "Username and password are required."]);
    exit;
}

try {
    // 1) Stakeholder exists?
    $stk = $conn->prepare("SELECT perLock FROM personal WHERE perID = :id");
    $stk->execute([':id' => $perID]);
    $row = $stk->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(["ok" => false, "msg" => "Stakeholder not found.", "error" => "not_found"]);
        exit;
    }

    // 2) Verify admin credentials
    $usr = $conn->prepare(
        "SELECT u.usrID, u.usrName, u.usrPass, u.usrStatus, r.rolName
           FROM users u
           JOIN roles r ON r.rolID = u.usrRole
          WHERE u.usrName = :u
          LIMIT 1"
    );
    $usr->execute([':u' => $username]);
    $user = $usr->fetch(PDO::FETCH_ASSOC);

    if (!$user
        || (int)$user['usrStatus'] !== 1
        || !password_verify($password, $user['usrPass'])) {
        // Log failure
        try {
            $log = $conn->prepare(
                "INSERT INTO userActivityLog
                    (ualUser, ualType, ualDetails, ualIP, ualDevice)
                 VALUES (NULL, 'Stakeholder Unlock FAILED', :d, :ip, :dev)"
            );
            $log->execute([
                ':d'   => "Failed unlock attempt on perID $perID as '$username'",
                ':ip'  => $_SERVER['REMOTE_ADDR'] ?? '',
                ':dev' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
            ]);
        } catch (\Throwable $ignored) {}

        // NOTE: HTTP 200 + ok:false. This is a *logical* result, not a
        // transport failure, so the client can read the message.
        echo json_encode([
            "ok"      => false,
            "error"   => "wrong_password",
            "msg"     => "Invalid username or password.",
        ]);
        exit;
    }

    // 3) Role policy
    $allowedRoles = ['Super', 'Admin', 'CEO'];
    if (!in_array($user['rolName'], $allowedRoles, true)) {
        echo json_encode([
            "ok"    => false,
            "error" => "wrong_role",
            "msg"   => "Your role ({$user['rolName']}) is not allowed to modify stakeholder locks.",
        ]);
        exit;
    }

    // 4) Success — log and return
    try {
        $log = $conn->prepare(
            "INSERT INTO userActivityLog
                (ualUser, ualType, ualDetails, ualIP, ualDevice)
             VALUES (:uid, 'Stakeholder Unlock OK', :d, :ip, :dev)"
        );
        $log->execute([
            ':uid' => (int)$user['usrID'],
            ':d'   => "Verified to modify lock on perID $perID",
            ':ip'  => $_SERVER['REMOTE_ADDR'] ?? '',
            ':dev' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250),
        ]);
    } catch (\Throwable $ignored) {}

    echo json_encode([
        "ok"        => true,
        "msg"       => "Verified",
        "unlockFor" => 300,
        "by"        => $user['usrName'],
        "role"      => $user['rolName'],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "ok"    => false,
        "error" => "server_error",
        "msg"   => "Server error.",
    ]);
}
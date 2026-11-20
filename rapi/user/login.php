<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once "../db.php";
require_once "../functions.php";
require_once __DIR__ . '/../../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

$db    = new Database();
$conn  = $db->getConnection();
$value = new DataValues($conn);

switch ($_SERVER["REQUEST_METHOD"]) {
    case 'GET':
        get_login();
        break;
    case 'POST':
        login();
        break;
    default:
        http_response_code(405);
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

/* =====================================================================
 *  BEARER TOKEN VALIDATION
 *  Returns the authenticated usrID, or exits with 401.
 * ===================================================================== */
function require_auth(PDO $conn): int
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';

    if (!preg_match('/^Bearer\s+([A-Fa-f0-9]{64})$/', $header, $m)) {
        http_response_code(401);
        echo json_encode(['msg' => 'unauthenticated']);
        exit;
    }

    try {
        $stmt = $conn->prepare(
            "SELECT utkUserID FROM userTokens
             WHERE utkTokenHash = ? AND utkExpiresAt > NOW()
             LIMIT 1"
        );
        $stmt->execute([hash('sha256', $m[1])]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['msg' => 'auth check failed']);
        exit;
    }

    if (!$row) {
        http_response_code(401);
        echo json_encode(['msg' => 'unauthenticated']);
        exit;
    }

    // Best-effort last-used touch — never fails the request.
    try {
        $conn->prepare("UPDATE userTokens SET utkLastUsed = NOW() WHERE utkUserID = ?")
             ->execute([$row['utkUserID']]);
    } catch (\Throwable $e) {
        // ignore
    }

    return (int)$row['utkUserID'];
}

/* =====================================================================
 *  SHARED PAYLOAD BUILDER
 *  Used by BOTH login() and get_login() so the JSON is identical.
 * ===================================================================== */
function buildLoginResponse(string $username): ?array
{
    global $conn;

    // ---- User core profile ----
    $stmt1 = $conn->prepare("
        SELECT usrName, usrID,
               CONCAT(perName, ' ', perLastName) AS usrFullName,
               perPhoto, rolName AS usrRole, usrEmail, perPhone,
               usrEntryDate, usrBranch, brcName
        FROM users
        JOIN branch   ON branch.brcID  = users.usrBranch
        JOIN personal ON personal.perID = users.usrOwner
        JOIN roles rl ON rl.rolID      = users.usrRole
        WHERE users.usrName = ? OR users.usrEmail = ?
        LIMIT 1
    ");
    $stmt1->execute([$username, $username]);
    $data1 = $stmt1->fetch(PDO::FETCH_ASSOC);

    if (!$data1) {
        return null;
    }

    // ---- Permissions ----
    $stmt2 = $conn->prepare("
        SELECT userPermissions.uprRole, userPermissions.uprStatus, roleSubGroup.rsgName
        FROM userPermissions
        JOIN users        ON users.usrID        = userPermissions.uprUserID
        JOIN roleSubGroup ON roleSubGroup.rsgID = userPermissions.uprRole
        WHERE users.usrName = ? OR users.usrEmail = ?
        ORDER BY userPermissions.uprRole ASC
    ");
    $stmt2->execute([$username, $username]);
    $data1['permissions'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);

    // ---- Company ----
    $stmt3 = $conn->prepare("
        SELECT comID, comName, comLicenseNo, comSlogan, comDetails, comPhone,
               comEmail, comWebsite, comLogo, comOwner, comFB, comInsta,
               comWhatsapp, comVerify, comLocalCcy, comTimeZone,
               CONCAT(a.addName, ', ', a.addCity, ', ', a.addProvince, ', ', a.addCountry) AS comAddress
        FROM companyProfile c
        JOIN branch  b ON b.brcCompany = c.comID
        JOIN users   u ON u.usrBranch  = b.brcID
        JOIN address a ON a.addID      = c.comAddress
        WHERE u.usrName = ? OR u.usrEmail = ?
        LIMIT 1
    ");
    $stmt3->execute([$username, $username]);
    $company = $stmt3->fetch(PDO::FETCH_ASSOC);

    // ---- Logo → data: URL so Flutter's Image.network caches it ----
    if ($company && !empty($company['comLogo'])) {
        $company['comLogo'] =
            'data:image/png;base64,' . base64_encode($company['comLogo']);
    }
    $data1['company'] = $company ?: null;

    return $data1;
}

/* =====================================================================
 *  GET /user/login.php?username=<name>
 *  Requires:  Authorization: Bearer <token>
 *  The token's user must match the requested username.
 * ===================================================================== */
function get_login()
{
    global $conn;

    // ---- Bearer token required ----
    $authUserID = require_auth($conn);

    $username = $_GET['username'] ?? null;
    if (!$username) {
        http_response_code(400);
        echo json_encode(["msg" => "username required"]);
        return;
    }

    // ---- Token's owner must match the requested username ----
    try {
        $stmt = $conn->prepare("SELECT usrName FROM users WHERE usrID = ? LIMIT 1");
        $stmt->execute([$authUserID]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(["msg" => $e->getMessage()]);
        return;
    }

    if (!$row || $row['usrName'] !== $username) {
        http_response_code(403);
        echo json_encode(["msg" => "forbidden"]);
        return;
    }

    try {
        $payload = buildLoginResponse($username);
        if (!$payload) {
            http_response_code(404);
            echo json_encode(["msg" => "incorrect"]);
            return;
        }
        // Never re-issue a token here — the client already has one.
        echo json_encode($payload, JSON_PRETTY_PRINT);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(["msg" => $e->getMessage()]);
    }
}

/* =====================================================================
 *  POST /user/login.php
 *  Body: { "usrName": "...", "usrPass": "..." }
 *  On success: returns full profile + `token` (6-day bearer).
 * ===================================================================== */
function login()
{
    global $conn, $value;

    $data     = json_decode(file_get_contents("php://input"), true) ?: [];
    $username = $data['usrName'] ?? null;
    $password = $data['usrPass'] ?? null;

    if (!$username || !$password) {
        echo json_encode(["msg" => "incorrect"]);
        return;
    }

    try {
        date_default_timezone_set($value->getCompanyAttributes('comTimeZone') ?: 'UTC');

        $stmt = $conn->prepare("SELECT * FROM users WHERE usrName = ? OR usrEmail = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            echo json_encode(["msg" => "incorrect"]);
            return;
        }

        $status       = (int)($row['usrStatus'] ?? 0);
        $fcp          = (int)($row['usrFCP']    ?? 0);
        $verification = strtolower((string)($row['usrToken'] ?? ''));
        $role         = (int)($row['usrRole']   ?? 0);
        $usrID        = (int)$row['usrID'];

        // ---- Password ----
        if (!password_verify($password, $row['usrPass'])) {
            $value->generateUserActivityLog(
                $username, 'User Login',
                "Failed: Attempting login with incorrect password"
            );
            echo json_encode(["msg" => "incorrect"]);
            return;
        }

        // ---- Status ----
        if ($status === 0) {
            $value->generateUserActivityLog(
                $username, 'User Login',
                "Not Allowed: Attempting login while user is blocked"
            );
            echo json_encode(["msg" => "blocked"]);
            return;
        }

        // ---- Verification ----
        if ($verification !== 'verified') {
            $value->generateUserActivityLog(
                $username, 'User Login',
                "Unverified: Attempting login while user is not verified"
            );
            echo json_encode(["msg" => "unverified"]);
            return;
        }

        // ---- Force change password ----
        if ($fcp === 1) {
            $value->generateUserActivityLog(
                $username, 'User Login',
                "FCP: Login but user forced to change password"
            );
            echo json_encode(["msg" => "fcp"]);
            return;
        }

        // ============================================================
        //  SUBSCRIPTION CHECK — DISABLED ON PURPOSE
        //  Uncomment the block below when you are ready to enforce it.
        // ============================================================
        /*
        if ($role !== 1) {
            $subKey      = trim($_ENV['SUB_KEY'] ?? getenv('SUB_KEY') ?: '', " \t\n\r\0\x0B\"'");
            $currentDate = date("Y-m-d");

            $stmtSub = $conn->prepare("SELECT subKey, subExpireDate FROM subscription ORDER BY subID DESC LIMIT 1");
            $stmtSub->execute();
            $sub = $stmtSub->fetch(PDO::FETCH_ASSOC);

            $subOk = $sub
                && password_verify($subKey, $sub['subKey'])
                && $sub['subExpireDate'] > $currentDate;

            if (!$subOk) {
                echo json_encode(["msg" => "nosub"]);
                return;
            }
        }
        */

        // ============================================================
        //  ISSUE BEARER TOKEN (6-day window)
        // ============================================================
        $rawToken  = bin2hex(random_bytes(32));       // 64 hex chars — sent to client
        $tokenHash = hash('sha256', $rawToken);       // stored in DB, never sent
        $expiresAt = date('Y-m-d H:i:s', strtotime('+6 days'));

        $stmtTok = $conn->prepare(
            "REPLACE INTO userTokens (utkUserID, utkTokenHash, utkExpiresAt)
             VALUES (?, ?, ?)"
        );
        $stmtTok->execute([$usrID, $tokenHash, $expiresAt]);

        // ---- Full payload (same shape get_login() returns) ----
        $payload = buildLoginResponse($username);
        if (!$payload) {
            echo json_encode(["msg" => "incorrect"]);
            return;
        }

        // Attach the raw token — the ONLY place it ever appears in plaintext.
        $payload['token'] = $rawToken;

        $value->generateUserActivityLog(
            $username, 'User Login',
            "Success: User Successfully logged in with correct credentials"
        );

        echo json_encode($payload, JSON_PRETTY_PRINT);

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
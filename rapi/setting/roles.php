<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST");
header("Access-Control-Allow-Headers: Content-Type");

require_once "../db.php";
require_once "../functions.php";

/** Maximum number of non-Super roles that may exist. */
const MAX_ROLES = 7;

$db = new Database();
$conn = $db->getConnection();
$value = new DataValues($conn);
$data = json_decode(file_get_contents("php://input"), true);
$request_method = $_SERVER["REQUEST_METHOD"];

switch ($request_method) {
    case 'GET':
        get_role();
        break;
    case 'POST':
        add_role();
        break;
    case 'PUT':
        update_role();
        break;
    case 'DELETE':
        delete_role();
        break;
    default:
        http_response_code(405);
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

/** Count roles (excluding Super). */
function count_non_super_roles($conn): int {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) FROM roles WHERE rolName <> 'Super'"
    );
    $stmt->execute();
    return (int)$stmt->fetchColumn();
}

function get_role() {
    global $conn;
    try {
        if (isset($_GET['role']) && !empty($_GET['role'])) {
            $sql = "SELECT * FROM roles WHERE rolID = :id AND rolName <> 'Super'";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $_GET['role'], PDO::PARAM_INT);
        } else {
            $sql = "SELECT * FROM roles WHERE rolName <> 'Super'";
            $stmt = $conn->prepare($sql);
        }
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode($data, JSON_PRETTY_PRINT);
    } catch (PDOException $th) {
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile()
        ]);
    }
}

function add_role() {
    global $conn, $value;
    $data = json_decode(file_get_contents("php://input"), true);

    $user = $data['usrName'] ?? '';
    $role = trim($data['rolName'] ?? '');

    if ($role === '') {
        echo json_encode(["msg" => "empty"]);
        return;
    }

    try {
        // Serialize concurrent adds so the count check is race-free.
        $conn->exec("SET TRANSACTION ISOLATION LEVEL SERIALIZABLE");
        $conn->beginTransaction();

        // Hard cap. The count is inside the transaction.
        $current = count_non_super_roles($conn);
        if ($current >= MAX_ROLES) {
            $conn->rollBack();
            echo json_encode([
                "msg"     => "limit",
                "limit"   => MAX_ROLES,
                "current" => $current,
            ]);
            return;
        }

        // Duplicate name check (also inside the transaction).
        $stmt = $conn->prepare("SELECT COUNT(*) FROM roles WHERE rolName = ?");
        $stmt->execute([$role]);
        if ((int)$stmt->fetchColumn() > 0) {
            $conn->rollBack();
            echo json_encode(["msg" => "exist"]);
            return;
        }

        // Insert the role.
        $stmt1 = $conn->prepare("INSERT INTO roles (rolName) VALUES (?)");
        $stmt1->execute([$role]);
        $roleID = (int)$conn->lastInsertId();

        // Seed permissions.
        $stmt3 = $conn->prepare(
            "INSERT INTO rolePermission (rpRoleID, rpRoleNameID, rpStatus)
             SELECT ?, rsgID, 0 FROM roleSubGroup"
        );
        $stmt3->execute([$roleID]);

        $value->generateUserActivityLog(
            $user,
            "User Role",
            "Create New Role: $role"
        );

        $conn->commit();
        echo json_encode(["msg" => "success"], JSON_PRETTY_PRINT);
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

function update_role() {
    global $conn, $value;
    $data = json_decode(file_get_contents("php://input"), true);

    $user       = $data['usrName'] ?? '';
    $roleID     = $data['rolID'] ?? null;
    $roleName   = trim($data['rolName'] ?? '');
    $roleStatus = $data['rolStatus'] ?? 1;

    if ($roleID === null || $roleName === '') {
        echo json_encode(["msg" => "empty"]);
        return;
    }

    try {
        $conn->beginTransaction();

        // Refuse renames that collide with another role.
        $dup = $conn->prepare(
            "SELECT COUNT(*) FROM roles WHERE rolName = ? AND rolID <> ?"
        );
        $dup->execute([$roleName, $roleID]);
        if ((int)$dup->fetchColumn() > 0) {
            $conn->rollBack();
            echo json_encode(["msg" => "exist"]);
            return;
        }

        $stmt1 = $conn->prepare(
            "UPDATE roles SET rolName = ?, rolStatus = ? WHERE rolID = ?"
        );
        $stmt1->execute([$roleName, $roleStatus, $roleID]);

        $value->generateUserActivityLog(
            $user,
            "User Role",
            "Updated Role: $roleName Role ID: $roleID"
        );

        $conn->commit();
        echo json_encode(["msg" => "success"], JSON_PRETTY_PRINT);
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

function delete_role() {
    global $conn, $value;
    $data = json_decode(file_get_contents("php://input"), true);

    $roleID = $data['rolID'] ?? null;

    if ($roleID === null) {
        echo json_encode(["msg" => "empty"]);
        return;
    }

    try {
        // Refuse if any user still references this role.
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM users WHERE usrRole = ?"
        );
        $stmt->execute([$roleID]);
        if ((int)$stmt->fetchColumn() > 0) {
            echo json_encode(["msg" => "exist"]);
            return;
        }

        $conn->beginTransaction();

        $stmt1 = $conn->prepare(
            "DELETE FROM rolePermission WHERE rpRoleID = ?"
        );
        $stmt1->execute([$roleID]);

        $stmt2 = $conn->prepare("DELETE FROM roles WHERE rolID = ?");
        $stmt2->execute([$roleID]);

        $conn->commit();
        echo json_encode(["msg" => "success"]);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
            "trace" => $th->getTrace()
        ]);
    }
}
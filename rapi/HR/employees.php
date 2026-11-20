<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once "../db.php";
require_once "../functions.php";

$db = new Database();
$conn = $db->getConnection();
$value = new DataValues($conn);

$request_method = $_SERVER["REQUEST_METHOD"];

switch ($request_method) {
    case 'GET':
        get_employee();
        break;
    case 'POST':
        add_employee();
        break;
    case 'PUT':
        update_employee();
        break;
    case 'DELETE':
        delete_employee();
        break;
    default:
        http_response_code(405);
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

// ═══════════════════════════════════════════════════════════════════
// GET
// ═══════════════════════════════════════════════════════════════════
function get_employee() {
    global $conn;
    try {
        if (isset($_GET['empID']) && !empty($_GET['empID'])) {
            $sql = "SELECT * FROM employees emp
                    JOIN personal pr ON pr.perID = emp.empPersonal
                    WHERE emp.empID = :id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $_GET['empID'], PDO::PARAM_INT);
        } elseif (isset($_GET['cat']) && !empty($_GET['cat'])) {
            $sql = "SELECT * FROM employees emp
                    JOIN personal pr ON pr.perID = emp.empPersonal
                    WHERE emp.empPosition = :cat";
            $stmt = $conn->prepare($sql);
            $stmt->bindValue(':cat', $_GET['cat']);
        } else {
            $sql = "SELECT * FROM employees emp
                    JOIN personal pr ON pr.perID = emp.empPersonal
                    ORDER BY emp.empID DESC";
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
            "file"  => $th->getFile(),
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// POST (add)
// ═══════════════════════════════════════════════════════════════════
function add_employee() {
    global $conn;
    $data = json_decode(file_get_contents("php://input"), true);

    $personal       = $data['empPersonal']      ?? null;
    $account        = $data['empSalAccount']    ?? null;
    $email          = $data['empEmail']         ?? null;
    $hiredDate      = $data['empHireDate']      ?? null;
    $department     = $data['empDepartment']    ?? null;
    $position       = $data['empPosition']      ?? null;
    $salCalcBase    = $data['empSalCalcBase']   ?? null;
    $paymentMethod  = $data['empPmntMethod']    ?? null;
    $salaryAmount   = $data['empSalary']        ?? null;
    $TIN            = $data['empTaxInfo']       ?? null;
    $status         = 1;

    if (empty($personal) || empty($account) || empty($position)) {
        echo json_encode(["msg" => "empty"]);
        return;
    }

    try {
        $stmt0 = $conn->prepare("SELECT COUNT(*) FROM employees WHERE empPersonal = ?");
        $stmt0->execute([$personal]);
        if ($stmt0->fetchColumn() > 0) {
            echo json_encode(["msg" => "exist"]);
            return;
        }

        $conn->beginTransaction();

        $stmt1 = $conn->prepare(
            "INSERT INTO employees
                (empPersonal, empSalAccount, empEmail, empHireDate,
                 empDepartment, empPosition, empSalCalcBase, empPmntMethod,
                 empSalary, empTaxInfo, empStatus)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt1->execute([
            $personal, $account, $email, $hiredDate, $department,
            $position, $salCalcBase, $paymentMethod, $salaryAmount,
            $TIN, $status,
        ]);

        $conn->commit();
        echo json_encode(["msg" => "success"], JSON_PRETTY_PRINT);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// PUT (update)
// ═══════════════════════════════════════════════════════════════════
function update_employee() {
    global $conn;
    $data = json_decode(file_get_contents("php://input"), true);

    $empID          = $data['empID']            ?? null;
    $account        = $data['empSalAccount']    ?? null;
    $email          = $data['empEmail']         ?? null;
    $hiredDate      = $data['empHireDate']      ?? null;
    $department     = $data['empDepartment']    ?? null;
    $position       = $data['empPosition']      ?? null;
    $salCalcBase    = $data['empSalCalcBase']   ?? null;
    $paymentMethod  = $data['empPmntMethod']    ?? null;
    $fingerPrint    = $data['empFingerprint']   ?? null;
    $salaryAmount   = $data['empSalary']        ?? null;
    $TIN            = $data['empTaxInfo']       ?? null;
    $status         = $data['empStatus']        ?? 1;
    $endDate        = $data['empEndDate']       ?? null;

    if (empty($empID)) {
        echo json_encode(["msg" => "empty"]);
        return;
    }

    if ($status == 1) {
        $endDate = "0000-00-00";
    }

    try {
        $conn->beginTransaction();

        // Only overwrite fingerprint if a non-empty value was sent —
        // the new form doesn't send it, so we must not wipe the existing one.
        if (!empty($fingerPrint)) {
            $stmt = $conn->prepare(
                "UPDATE employees
                    SET empSalAccount = ?, empEmail = ?, empHireDate = ?,
                        empDepartment = ?, empPosition = ?, empSalCalcBase = ?,
                        empPmntMethod = ?, empFingerprint = ?, empSalary = ?,
                        empTaxInfo = ?, empStatus = ?, empEndDate = ?
                  WHERE empID = ?"
            );
            $stmt->execute([
                $account, $email, $hiredDate, $department, $position,
                $salCalcBase, $paymentMethod, $fingerPrint, $salaryAmount,
                $TIN, $status, $endDate, $empID,
            ]);
        } else {
            $stmt = $conn->prepare(
                "UPDATE employees
                    SET empSalAccount = ?, empEmail = ?, empHireDate = ?,
                        empDepartment = ?, empPosition = ?, empSalCalcBase = ?,
                        empPmntMethod = ?, empSalary = ?, empTaxInfo = ?,
                        empStatus = ?, empEndDate = ?
                  WHERE empID = ?"
            );
            $stmt->execute([
                $account, $email, $hiredDate, $department, $position,
                $salCalcBase, $paymentMethod, $salaryAmount, $TIN,
                $status, $endDate, $empID,
            ]);
        }

        $conn->commit();
        echo json_encode(["msg" => "success"]);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// DELETE
// ═══════════════════════════════════════════════════════════════════
function delete_employee() {
    global $conn;
    $data = json_decode(file_get_contents("php://input"), true);

    // Accept empID from body OR from ?empID=
    $empID = $data['empID'] ?? ($_GET['empID'] ?? null);

    if (empty($empID) || !is_numeric($empID)) {
        echo json_encode(["msg" => "empty"]);
        return;
    }
    $empID = (int)$empID;

    try {
        // ── 1. Existence check ──────────────────────────────────────
        $check = $conn->prepare("SELECT COUNT(*) FROM employees WHERE empID = ?");
        $check->execute([$empID]);
        if ($check->fetchColumn() == 0) {
            echo json_encode(["msg" => "notfound"]);
            return;
        }

        // ── 2. Dependency checks ────────────────────────────────────
        // Collect all blockers so the client can show one combined message.
        $dependencies = [];

        // Attendance
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM employeeAttendence WHERE emaEmployee = ?"
        );
        $stmt->execute([$empID]);
        $attendanceCount = (int)$stmt->fetchColumn();
        if ($attendanceCount > 0) {
            $dependencies[] = [
                "table"  => "employeeAttendence",
                "count"  => $attendanceCount,
                "reason" => "attendance",
            ];
        }

        // Payroll
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM payroll WHERE prlEmployee = ?"
        );
        $stmt->execute([$empID]);
        $payrollCount = (int)$stmt->fetchColumn();
        if ($payrollCount > 0) {
            $dependencies[] = [
                "table"  => "payroll",
                "count"  => $payrollCount,
                "reason" => "payroll",
            ];
        }

        // Add more dependents here as your schema grows, e.g.:
        // $stmt = $conn->prepare("SELECT COUNT(*) FROM salarySlips WHERE ... = ?");
        // ...

        // ── 3. Blocked if any dependency exists ─────────────────────
        if (!empty($dependencies)) {
            echo json_encode([
                "msg"          => "inuse",
                "dependencies" => $dependencies,
                // Human-readable fallback the client can show if it doesn't
                // want to format the dependencies itself.
                "detail"       => "Employee has related records: "
                                . implode(", ", array_map(
                                    fn($d) => "{$d['reason']} ({$d['count']})",
                                    $dependencies
                                  )),
            ], JSON_PRETTY_PRINT);
            return;
        }

        // ── 4. Delete ───────────────────────────────────────────────
        $conn->beginTransaction();
        $stmt = $conn->prepare("DELETE FROM employees WHERE empID = ?");
        $stmt->execute([$empID]);
        $conn->commit();

        echo json_encode(["msg" => "success"], JSON_PRETTY_PRINT);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
            "file"  => $th->getFile(),
        ]);
    }
}
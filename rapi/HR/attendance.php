<?php

// ── Do NOT let PHP print warnings into the JSON body ─────────────
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Log them to a file instead so you can still see them
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/attendance_error.log');

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

// Force every PHP warning/notice into an exception so the catch blocks
// return a clean JSON error instead of corrupting the body.
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once "../db.php";
require_once "../functions.php";

$db = new Database();
$conn = $db->getConnection();
$value = new DataValues($conn);

// ═══════════════════════════════════════════════════════════════════
// METHOD OVERRIDE — bypasses ModSecurity blocking DELETE
// ═══════════════════════════════════════════════════════════════════
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'POST') {
    $rawBody = file_get_contents('php://input');
    $parsed  = json_decode($rawBody, true);
    if (is_array($parsed) && isset($parsed['_method'])) {
        $override = strtoupper((string)$parsed['_method']);
        if (in_array($override, ['DELETE', 'PUT'], true)) {
            $method = $override;
        }
    }
}

switch ($method) {
    case 'GET':    get_attendance();    break;
    case 'POST':   post_attendance();   break;
    case 'PUT':    update_attendance(); break;
    case 'DELETE': delete_attendance(); break;
    default:
        http_response_code(405);
        echo json_encode(["message" => "Method Not Allowed"]);
        break;
}

// ═══════════════════════════════════════════════════════════════════
// GET (unchanged)
// ═══════════════════════════════════════════════════════════════════
function get_attendance() {
    global $conn;
    try {
        $base = "SELECT ea.emaID, ea.emaEmployee, em.empPosition,
                        CONCAT(pr.perName, ' ', pr.perLastName) AS fullName,
                        ea.emaDate, ea.emaCheckedIn, ea.emaCheckedOut,
                        ea.emaWorkHours, ea.emaStatus, ea.emaCompleted,
                        ea.emaEntryTime
                 FROM employeeAttendence ea
                 JOIN employees em  ON em.empID = ea.emaEmployee
                 JOIN personal  pr  ON pr.perID = em.empPersonal";

        if (isset($_GET['emaID']) && !empty($_GET['emaID'])) {
            $stmt = $conn->prepare($base . " WHERE ea.emaID = :id");
            $stmt->bindParam(':id', $_GET['emaID'], PDO::PARAM_INT);
        } elseif (isset($_GET['date']) && !empty($_GET['date'])) {
            $stmt = $conn->prepare($base . " WHERE ea.emaDate = :dt");
            $stmt->bindValue(':dt', $_GET['date']);
        } elseif (isset($_GET['month']) && !empty($_GET['month'])) {
            $stmt = $conn->prepare($base . " WHERE DATE_FORMAT(ea.emaDate, '%Y-%m') = :m");
            $stmt->bindValue(':m', $_GET['month']);
        } else {
            $stmt = $conn->prepare($base . " ORDER BY ea.emaDate DESC, ea.emaID DESC LIMIT 200");
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Normalize types so Dart gets real ints / floats / bools
        foreach ($rows as &$row) {
            $row['emaID']        = $row['emaID']        !== null ? (int)   $row['emaID']        : null;
            $row['emaEmployee']  = $row['emaEmployee']  !== null ? (int)   $row['emaEmployee']  : null;
            $row['emaWorkHours'] = $row['emaWorkHours'] !== null ? (float) $row['emaWorkHours'] : null;
            $row['emaCompleted'] = (int) $row['emaCompleted'] === 1;
        }
        unset($row);

        echo json_encode($rows, JSON_PRETTY_PRINT);
    } catch (PDOException $th) {
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// POST (unchanged)
// ═══════════════════════════════════════════════════════════════════
function post_attendance() {
    global $conn, $value;
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $mode = strtolower($data['mode'] ?? '');
    $user = $data['usrName'] ?? null;
    $dt   = $data['emaDate'] ?? null;

    if (!in_array($mode, ['single', 'multiple', 'all'], true)) {
        echo json_encode(["msg" => "invalid_mode"]);
        return;
    }
    if (empty($dt)) {
        echo json_encode(["msg" => "empty", "field" => "emaDate"]);
        return;
    }

    try {
        if ($mode === 'single') {
            $records = [[
                'emaEmployee'   => $data['emaEmployee']   ?? null,
                'emaCheckedIn'  => $data['emaCheckedIn']  ?? null,
                'emaCheckedOut' => $data['emaCheckedOut'] ?? null,
                'emaStatus'     => $data['emaStatus']     ?? 'Present',
            ]];
        } elseif ($mode === 'multiple') {
            $records = $data['records'] ?? [];
        } else { // 'all'
            $records = [[
                'emaEmployee'   => '*all*',
                'emaCheckedIn'  => $data['emaCheckedIn']  ?? null,
                'emaCheckedOut' => $data['emaCheckedOut'] ?? null,
                'emaStatus'     => $data['emaStatus']     ?? 'Present',
            ]];
        }

        if (!is_array($records) || count($records) === 0) {
            echo json_encode(["msg" => "empty", "field" => "records"]);
            return;
        }

        $summary = insert_attendance_rows($conn, $dt, $records);

        $value->generateUserActivityLog(
            $user,
            "Attendence",
            "Add {$mode} - Date: $dt, inserted: {$summary['inserted']}, "
            . "skipped: {$summary['skipped']}"
        );

        // Human-friendly message used by the toast
        $msg = "Inserted {$summary['inserted']} row(s).";
        if ($summary['skipped'] > 0) {
            $msg .= " Skipped {$summary['skipped']} (already exists).";
        }
        if ($summary['inserted'] === 0 && $summary['skipped'] > 0) {
            $msg = "Attendance already exists for this date. "
                 . "Nothing was inserted.";
        }

        echo json_encode([
            "msg"        => "success",
            "message"    => $msg,
            "inserted"   => $summary['inserted'],
            "skipped"    => $summary['skipped'],
            "skippedIds" => $summary['skippedIds'],
        ], JSON_PRETTY_PRINT);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
            "line"  => $th->getLine(),
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// PUT (unchanged)
// ═══════════════════════════════════════════════════════════════════
function update_attendance() {
    global $conn, $value;
    $data    = json_decode(file_get_contents("php://input"), true) ?? [];
    $user    = $data['usrName'] ?? null;
    $records = $data['records'] ?? [];

    if (empty($records)) {
        echo json_encode(["msg" => "empty", "field" => "records"]);
        return;
    }

    try {
        $conn->beginTransaction();

        $stmt = $conn->prepare(
            "UPDATE employeeAttendence
                SET emaCheckedIn  = ?,
                    emaCheckedOut = ?,
                    emaWorkHours  = ?,
                    emaStatus     = ?,
                    emaCompleted  = ?
              WHERE emaID = ?"
        );

        foreach ($records as $rec) {
            $status = $rec['emaStatus'] ?? 'Present';

            if ($status === 'Absent') {
                $in = null; $out = null; $hours = 0.0; $complete = 1;
            } else {
                $in = $rec['emaCheckedIn'] ?? null;
                if (empty($in) || $in === '00:00:00') {
                    throw new \RuntimeException(
                        "Check-in is required for a non-absent record."
                    );
                }

                $out = $rec['emaCheckedOut'] ?? null;
                if (empty($out) || $out === '00:00:00') {
                    $out = null; $hours = null; $complete = 0;   // still open
                } else {
                    $hours = hours_between($in, $out);
                    $complete = 1;                                // closed
                }
            }

            $stmt->execute([
                $in, $out, $hours, $status, $complete, $rec['emaID'],
            ]);
        }

        $conn->commit();
        $value->generateUserActivityLog($user, "Attendence", "Modify");
        echo json_encode(["msg" => "success", "message" => "Attendance updated."]);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(400);
        echo json_encode([
            "msg"   => "failed",
            "error" => $th->getMessage(),
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// DELETE (unchanged)
// ═══════════════════════════════════════════════════════════════════
function delete_attendance() {
    global $conn, $value;
    $data  = json_decode(file_get_contents("php://input"), true) ?? [];
    $emaID = $data['emaID'] ?? ($_GET['emaID'] ?? null);
    $user  = $data['usrName'] ?? null;

    if (empty($emaID) || !is_numeric($emaID)) {
        echo json_encode(["msg" => "empty", "field" => "emaID"]);
        return;
    }
    $emaID = (int) $emaID;

    try {
        $check = $conn->prepare("SELECT emaID FROM employeeAttendence WHERE emaID = ?");
        $check->execute([$emaID]);
        if ($check->fetchColumn() === false) {
            echo json_encode(["msg" => "notfound"]);
            return;
        }

        $conn->beginTransaction();
        $conn->prepare("DELETE FROM employeeAttendence WHERE emaID = ?")
             ->execute([$emaID]);
        $conn->commit();

        $value->generateUserActivityLog($user, "Attendence", "Delete - emaID: $emaID");

        echo json_encode([
            "msg"     => "success",
            "message" => "Attendance record #$emaID deleted.",
        ], JSON_PRETTY_PRINT);
    } catch (\Throwable $th) {
        if ($conn->inTransaction()) $conn->rollBack();
        http_response_code(500);
        echo json_encode(["msg" => "failed", "error" => $th->getMessage()]);
    }
}

// ═══════════════════════════════════════════════════════════════════
// Helpers (unchanged)
// ═══════════════════════════════════════════════════════════════════
function insert_attendance_rows(PDO $conn, string $dt, array $records): array {
    // 1. Which employees already have a row for this date?
    $existing = $conn->prepare(
        "SELECT emaEmployee FROM employeeAttendence WHERE emaDate = ?"
    );
    $existing->execute([$dt]);
    $alreadyPresent = array_map('intval', $existing->fetchAll(PDO::FETCH_COLUMN));

    // 2. Expand 'all' to every active employee
    $isAll = count($records) === 1
          && ($records[0]['emaEmployee'] ?? null) === '*all*';

    if ($isAll) {
        $active = $conn->query(
            "SELECT empID FROM employees WHERE empStatus = 1"
        )->fetchAll(PDO::FETCH_COLUMN);

        $template = $records[0];
        $records  = [];
        foreach ($active as $empId) {
            $records[] = [
                'emaEmployee'   => (int) $empId,
                'emaCheckedIn'  => $template['emaCheckedIn'],
                'emaCheckedOut' => $template['emaCheckedOut'],
                'emaStatus'     => $template['emaStatus'],
            ];
        }
    }

    $stmt = $conn->prepare(
        "INSERT INTO employeeAttendence
            (emaEmployee, emaDate, emaCheckedIn, emaCheckedOut,
             emaWorkHours, emaStatus, emaCompleted, emaEntryTime)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );

    $conn->beginTransaction();
    $inserted   = 0;
    $skipped    = 0;
    $skippedIds = [];

    foreach ($records as $r) {
        $empId = $r['emaEmployee'] ?? null;
        if (empty($empId)) { $skipped++; continue; }

        if (in_array((int) $empId, $alreadyPresent, true)) {
            $skipped++;
            $skippedIds[] = (int) $empId;
            continue;
        }

        $status = $r['emaStatus'] ?? 'Present';

        // ── Rule: absent → both times NULL, completed = 1 ──────────
        if ($status === 'Absent') {
            $in       = null;
            $out      = null;
            $hours    = 0.0;
            $complete = 1;
        } else {
            // A non-absent row must have a check-in
            $in = $r['emaCheckedIn'] ?? null;
            if (empty($in) || $in === '00:00:00') {
                $skipped++;              // refuse: no check-in → cannot insert
                $skippedIds[] = (int) $empId;
                continue;
            }

            $out = $r['emaCheckedOut'] ?? null;
            if (empty($out) || $out === '00:00:00') {
                // ── Open shift: checked in, not yet checked out ───
                $out      = null;
                $hours    = null;
                $complete = 0;
            } else {
                // ── Full shift ─────────────────────────────────────
                $hours    = hours_between($in, $out);
                $complete = 1;
            }
        }

        $stmt->execute([$empId, $dt, $in, $out, $hours, $status, $complete]);
        $inserted++;
        $alreadyPresent[] = (int) $empId;
    }

    $conn->commit();
    return [
        'inserted'   => $inserted,
        'skipped'    => $skipped,
        'skippedIds' => $skippedIds,
    ];
}

function hours_between(?string $in, ?string $out): float {
    if (empty($in) || empty($out)) return 0.0;
    $s = strtotime("1970-01-01 $in");
    $e = strtotime("1970-01-01 $out");
    if ($s === false || $e === false || $e <= $s) return 0.0;
    return round(($e - $s) / 3600, 1);
}
<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if (!headers_sent()) {
            header("Content-Type: application/json; charset=UTF-8");
            http_response_code(200);
            echo json_encode([
                "msg"   => "failed",
                "error" => $e['message'],
                "line"  => $e['line'],
                "file"  => $e['file'],
            ]);
        }
    }
});

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(["msg" => "Method Not Allowed"]);
    exit;
}

require_once "../db.php";
require_once "../functions.php";

$db   = new Database();
$conn = $db->getConnection();

$input = json_decode(file_get_contents("php://input"), true) ?: [];
$account = isset($input['account'])  ? (int)    $input['account']  : 0;
$from    = isset($input['fromDate']) ? (string) $input['fromDate'] : '';
$to      = isset($input['toDate'])   ? (string) $input['toDate']   : '';

if ($account <= 0 || $from === '' || $to === '') {
    echo json_encode(["msg" => "account, fromDate, and toDate are required."]);
    exit;
}

try {
    $lk = $conn->prepare("
        SELECT ad.actSignatory AS perID, p.perLock
          FROM accountDetails ad
          JOIN personal p ON p.perID = ad.actSignatory
         WHERE ad.actAccount = :acc
         LIMIT 1
    ");
    $lk->execute([':acc' => $account]);
    $row = $lk->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(["msg" => "Account not found."]);
        exit;
    }

    $perID    = (int) $row['perID'];
    $isLocked = (int) $row['perLock'];

    if ($isLocked === 1) {
        $unlockFlag = ($input['unlock'] ?? false) === true;
        if (!$unlockFlag || !_has_recent_unlock($conn, $perID)) {
            echo json_encode([
                "msg"     => "locked",
                "error"   => "stakeholder_locked",
                "message" => "This account's owner is locked. Ask an admin to unlock.",
                "perID"   => $perID,
                "account" => $account,
            ]);
            exit;
        }
    }

    // ── Header query ────────────────────────────────────────────
    $stmt = $conn->prepare("
        SELECT a.accNumber, a.accName,
               CONCAT(p.perName, ' ', p.perLastName) AS signatory,
               p.perPhone, p.perEmail,
               CONCAT(ad.addName, ', ', ad.addCity, ', ', ad.addProvince,
                      ', ', ad.addCountry, ', ', ad.addZipCode) AS address,
               adt.actCurrency, c.ccySymbol, adt.actCreditLimit,
               COALESCE(b.curBalance, 0) AS curBalance,
               COALESCE(b.avilBalance, 0) AS avilBalance,
               adt.actStatus,
               p.perID  AS perID,
               p.perLock AS perLock
          FROM accounts a
          JOIN accountDetails adt ON adt.actAccount = a.accNumber
          JOIN personal p ON p.perID = adt.actSignatory
          LEFT JOIN address ad ON ad.addID = p.perAddress
          JOIN currency c ON c.ccyCode = adt.actCurrency
          LEFT JOIN (
              SELECT td.trdAccount,
                     SUM(CASE WHEN td.trdDrCr='Cr' THEN td.trdAmount
                              WHEN td.trdDrCr='Dr' THEN -td.trdAmount END) AS curBalance,
                     SUM(CASE WHEN t.trnStatus = 1 AND td.trdDrCr='Cr' THEN td.trdAmount
                              WHEN t.trnStatus = 1 AND td.trdDrCr='Dr' THEN -td.trdAmount
                              ELSE 0 END) AS avilBalance
                FROM trnDetails td
                JOIN transactions t ON t.trnReference = td.trdReference
               GROUP BY td.trdAccount
          ) b ON b.trdAccount = a.accNumber
         WHERE a.accNumber = ?
    ");
    $stmt->execute([$account]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$data) {
        echo json_encode(["msg" => "Account not found."]);
        exit;
    }

    // ── Period / running balance query ──────────────────────────
    $stmt1 = $conn->prepare("
        WITH
        opening AS (
            SELECT COALESCE(SUM(CASE WHEN td.trdDrCr='Cr' THEN td.trdAmount
                                     ELSE -td.trdAmount END), 0) AS opening_balance
              FROM trnDetails td
              JOIN transactions tr ON tr.trnReference = td.trdReference
             WHERE td.trdAccount = ? AND tr.trnEntryDate < ?
        ),
        period AS (
            SELECT tr.trnEntryDate, tr.trnReference, td.trdNarration,
                   CASE WHEN td.trdDrCr='Dr' THEN td.trdAmount ELSE 0 END AS debit,
                   CASE WHEN td.trdDrCr='Cr' THEN td.trdAmount ELSE 0 END AS credit,
                   (CASE WHEN td.trdDrCr='Cr' THEN td.trdAmount
                         ELSE -td.trdAmount END) AS amount,
                   CASE WHEN tr.trnStatus = 1 THEN '' ELSE '*' END AS status
              FROM trnDetails td
              JOIN transactions tr ON tr.trnReference = td.trdReference
             WHERE td.trdAccount = ?
               AND tr.trnEntryDate >= ?
               AND tr.trnEntryDate <= ?
        ),
        running AS (
            SELECT *,
                   (SELECT opening_balance FROM opening) +
                   SUM(amount) OVER (ORDER BY trnEntryDate, trnReference
                                     ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) AS total
              FROM period
        ),
        closing AS (
            SELECT (SELECT opening_balance FROM opening) +
                   COALESCE(SUM(amount), 0) AS closing_balance
              FROM period
        )
        SELECT 1 AS sort_order, ? AS trnEntryDate, '' AS trnReference,
               'Opening Balance' AS trdNarration, 0 AS debit, 0 AS credit,
               opening_balance AS total, '' AS status
          FROM opening
        UNION ALL
        SELECT 2 AS sort_order, trnEntryDate, trnReference, trdNarration,
               debit, credit, total, status
          FROM running
        UNION ALL
        SELECT 3 AS sort_order, ? AS trnEntryDate, '' AS trnReference,
               'Closing Balance' AS trdNarration, 0 AS debit, 0 AS credit,
               closing_balance AS total, '' AS status
          FROM closing
        ORDER BY sort_order, trnEntryDate
    ");

    $stmt1->execute([
        $account, $from,
        $account, $from, $to,
        $from,
        $to,
    ]);
    $data['records'] = $stmt1->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($data, JSON_PRETTY_PRINT);
} catch (Throwable $th) {
    echo json_encode([
        "msg"   => "failed",
        "error" => $th->getMessage(),
        "line"  => $th->getLine(),
        "file"  => $th->getFile(),
    ]);
}

/**
 * Returns true if a successful admin verification for `$perID` was
 * logged within the last 5 minutes.
 *
 * Two acceptance paths:
 *   1. Strict: a "Stakeholder Unlock OK" row by the *current* user
 *      (`current_user_id()` resolved from the bearer token).
 *   2. Relaxed: a "Stakeholder Unlock OK" row for this perID by *any*
 *      user (or with NULL ualUser) within the window. This is safe
 *      because the row itself is only written by verify_lock.php
 *      after a successful admin password verification, and the
 *      window is short (5 minutes).
 */
function _has_recent_unlock(PDO $conn, int $perID): bool
{
    $uid = current_user_id();

    // 1) Strict — log by the current user.
    if ($uid !== null) {
        $q = $conn->prepare(
            "SELECT 1 FROM userActivityLog
              WHERE ualUser = :uid
                AND ualType = 'Stakeholder Unlock OK'
                AND ualDetails LIKE :m
                AND ualTiming >= NOW() - INTERVAL 5 MINUTE
              ORDER BY ualID DESC LIMIT 1"
        );
        $q->execute([':uid' => $uid, ':m' => '%perID ' . $perID . '%']);
        if ($q->fetchColumn()) return true;
    }

    // 2) Relaxed fallback — the log itself proves an admin was
    //    verified recently; ualUser may be NULL (see verify_lock.php
    //    when the admin's session can't resolve to a usrID).
    $q2 = $conn->prepare(
        "SELECT 1 FROM userActivityLog
          WHERE ualType = 'Stakeholder Unlock OK'
            AND ualDetails LIKE :m
            AND ualTiming >= NOW() - INTERVAL 5 MINUTE
          ORDER BY ualID DESC LIMIT 1"
    );
    $q2->execute([':m' => '%perID ' . $perID . '%']);
    return (bool) $q2->fetchColumn();
}

if (!function_exists('current_user_id')) {
    function current_user_id(): ?int {
        static $uid = null;
        static $resolved = false;
        if ($resolved) return $uid;
        $resolved = true;

        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $auth = $headers['Authorization']
            ?? $headers['authorization']
            ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if (!preg_match('/Bearer\s+(\S+)/i', $auth, $m)) return null;

        global $conn;
        if (!($conn instanceof PDO)) return null;

        $hash = hash('sha256', $m[1]);
        $stmt = $conn->prepare(
            "SELECT utkUserID FROM userTokens
              WHERE utkTokenHash = :h AND utkExpiresAt > NOW() LIMIT 1"
        );
        $stmt->execute([':h' => $hash]);
        $id = $stmt->fetchColumn();
        $uid = $id ? (int) $id : null;
        return $uid;
    }
}
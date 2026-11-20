<?php
/* ==================================================================
 * shipment_expense.php  —  Expense API for container shipments
 *
 *   POST ?action=addExpense      { usrName, shpReference | shpId |
 *                                  containerNo | sequenceNo,
 *                                  AccountNo, amount, narration }
 *   POST ?action=updateExpense   { usrName, shpReference, expId,
 *                                  AccountNo?, amount?, narration? }
 *   POST ?action=deleteExpense   { usrName, shpReference, expId }
 *
 *   GET  ?action=listExpenses    &shpReference=... | &shpId=... |
 *                                &containerNo=... | &sequenceNo=...
 *   GET  ?action=getExpense      &shpReference=...&expId=1
 *
 * Rules enforced:
 *   - Dr side is ALWAYS 20202028 (TRANSPORT_CLEARING_ACCOUNT).
 *   - Cr side is the caller-supplied AccountNo.
 *   - Both sides must be in the company base currency.
 *   - Each expense = exactly two trnDetails rows tagged
 *       [exp:<id>]                 on Dr
 *       [exp:<id>][acct:<acctNo>]  on Cr
 *     so edit/delete are surgical even when a reference carries many
 *     expenses and many customer accounts.
 *   - expId is scoped per shpReference (1, 2, 3… per container).
 * ================================================================== */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once "../db.php";
require_once "../functions.php";

$db   = new Database();
$conn = $db->getConnection();
$dv   = new DataValues($conn);

if (!$conn instanceof PDO) {
    http_response_code(500);
    echo json_encode(["ok" => false, "msg" => "Database connection failed"]);
    exit;
}

/* ─────────────────────────── helpers ─────────────────────────── */
function jsonOut($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}
function fail(string $msg, int $code = 400, array $extra = []): void {
    jsonOut(array_merge(['ok' => false, 'msg' => $msg], $extra), $code);
}
function ok(array $data = []): void {
    jsonOut(array_merge(['ok' => true], $data));
}
function body(): array {
    $raw = file_get_contents('php://input');
    $in  = json_decode($raw, true);
    return is_array($in) ? $in : ($_POST ?: []);
}
function need(array $in, array $keys): void {
    foreach ($keys as $k) {
        if (!isset($in[$k]) || $in[$k] === '' || $in[$k] === null) {
            fail("Missing required field: $k");
        }
    }
}


/* ─────────────────────── shared data lookups ─────────────────── */

/**
 * Resolve a shipment by ANY of: shpReference, shpId, containerNo, sequenceNo.
 * Returns a row with shpId, shpReference, containerNo, sequenceNo, loadingDate.
 * Order of precedence: shpReference → shpId → containerNo → sequenceNo.
 */
function loadShipment(PDO $conn, array $hints): ?array {
    $select = "SELECT shpId, shpReference, containerNo, sequenceNo, loadingDate
                 FROM shipmentTbl";

    if (!empty($hints['shpReference'])) {
        $stmt = $conn->prepare("$select WHERE shpReference = ? LIMIT 1");
        $stmt->execute([$hints['shpReference']]);
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) return $r;
    }
    if (!empty($hints['shpId'])) {
        $stmt = $conn->prepare("$select WHERE shpId = ? LIMIT 1");
        $stmt->execute([(int)$hints['shpId']]);
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) return $r;
    }
    if (!empty($hints['containerNo'])) {
        $stmt = $conn->prepare("$select WHERE containerNo = ? ORDER BY shpId DESC LIMIT 1");
        $stmt->execute([$hints['containerNo']]);
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) return $r;
    }
    if (!empty($hints['sequenceNo'])) {
        $stmt = $conn->prepare("$select WHERE sequenceNo = ? ORDER BY shpId DESC LIMIT 1");
        $stmt->execute([$hints['sequenceNo']]);
        if ($r = $stmt->fetch(PDO::FETCH_ASSOC)) return $r;
    }
    return null;
}

/**
 * Load an active account in the company base currency.
 * Returns ['accNumber','accCcy','accName', '__wrongCcy'?] or null.
 */
function loadBaseCcyAccount(PDO $conn, $accNumber): ?array {
    $base = baseCurrency($conn);

    $stmt = $conn->prepare(
        "SELECT ad.actAccount AS accNumber,
                ad.actCurrency AS accCcy,
                a.accName      AS accName
           FROM accountDetails ad
           JOIN accounts a ON a.accNumber = ad.actAccount
          WHERE ad.actAccount = ?
            AND ad.actStatus  = 1
          LIMIT 1"
    );
    $stmt->execute([(int)$accNumber]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;

    if (strcasecmp($row['accCcy'], $base) !== 0) {
        $row['__wrongCcy'] = true;
    }
    return $row;
}

/**
 * The [acct:<n>] suffix that disambiguates the Cr row for a given customer
 * account on a given reference. Mirrors syncAccountTrn()'s convention.
 */
function crAccountTag(int $account): string {
    return sprintf('[acct:%d]', $account);
}

/**
 * Narration tag used to locate expense rows for a given expId.
 * Dr: "[exp:N]"
 * Cr: "[exp:N][acct:<acct>]"
 */
function expenseTag(int $expId, ?int $account = null): string {
    return $account === null
        ? sprintf('[exp:%d]', $expId)
        : sprintf('[exp:%d]%s', $expId, crAccountTag($account));
}

/**
 * Human-facing narration body:
 *   Cont No. : ABC123, SEQ: 12 | <user text>
 * The tag is appended separately by the caller.
 */
function buildExpenseNarration(
    string $containerNo,
    ?string $sequenceNo,
    string $userNarration
): string {
    $seq = trim((string)($sequenceNo ?? ''));
    $head = sprintf('Cont No. : %s, SEQ: %s', $containerNo, $seq !== '' ? $seq : '—');
    return $userNarration !== '' ? $head . ' | ' . trim($userNarration) : $head;
}

/**
 * Next expId, scoped to a specific reference.
 * Starts at 1 and never collides across references.
 */
function nextExpenseId(PDO $conn, string $trnRef): int {
    $stmt = $conn->prepare(
        "SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(
                    SUBSTRING_INDEX(trdNarration, '[exp:', -1), ']', 1
                ) AS UNSIGNED)), 0)
           FROM trnDetails
          WHERE trdReference = ?
            AND trdNarration LIKE '%[exp:%'"
    );
    $stmt->execute([$trnRef]);
    return (int)$stmt->fetchColumn() + 1;
}

/**
 * Fetch the pair of trnDetails rows for (reference, expId).
 * Returns ['dr'=>row, 'cr'=>row] or null.
 *
 * The Dr row must be 20202028 / Dr / [exp:N]
 * The Cr row must be non-20202028 / Cr / [exp:N][acct:*]
 *   (or, defensively, just Cr / [exp:N] if no [acct:] suffix is present —
 *    this handles legacy rows and also the case where the customer account
 *    happens to equal 20202028, which we should never allow but must not
 *    crash on.)
 */
function loadExpenseRows(PDO $conn, string $trnRef, int $expId): ?array {
    $like = '%' . expenseTag($expId) . '%';

    $stmt = $conn->prepare(
        "SELECT trdID, trdReference, trdCcy, trdBranch, trdAccount,
                trdDrCr, trdAmount, trdNarration, trdEntryDate
           FROM trnDetails
          WHERE trdReference = ?
            AND trdNarration LIKE ?
          ORDER BY trdID ASC"
    );
    $stmt->execute([$trnRef, $like]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dr = null; $cr = null;
    foreach ($rows as $r) {
        if ($r['trdDrCr'] === 'Dr' && (int)$r['trdAccount'] === TRANSPORT_CLEARING_ACCOUNT) {
            $dr = $r;
        } elseif ($r['trdDrCr'] === 'Cr' && (int)$r['trdAccount'] !== TRANSPORT_CLEARING_ACCOUNT) {
            $cr = $r;
        }
    }
    if (!$dr || !$cr) return null;
    return ['dr' => $dr, 'cr' => $cr];
}

/**
 * Extract the user-typed narration (the part after "| " and before " [exp:").
 */
function extractUserNarration(string $narration): string {
    if (preg_match('/\|\s*(.*?)\s*\[exp:\d+\]/', $narration, $m)) {
        return trim($m[1]);
    }
    // No user text — the head only
    if (preg_match('/^Cont No\. : .*?SEQ: \S+\s*(.*)$/', $narration, $m)) {
        return trim($m[1]);
    }
    return '';
}

/* ─────────────────────── auth (same pattern) ─────────────────── */
$in     = body();
$action = $_GET['action'] ?? ($in['action'] ?? '');
if ($action === '') fail('Missing action');

$username = trim((string)($_GET['usrName'] ?? $in['usrName'] ?? ''));
$uid = 0; $me = null; $branch = 1000;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($username === '') fail('Missing usrName', 401);

    $stmt = $conn->prepare(
        "SELECT usrID, usrName, usrBranch, usrRole, usrStatus
           FROM users WHERE usrName = ? LIMIT 1"
    );
    $stmt->execute([$username]);
    $me = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$me) fail('User not found', 401);
    if ((int)$me['usrStatus'] !== 1) fail('User is inactive', 403);

    $uid    = (int)$me['usrID'];
    $branch = (int)($me['usrBranch'] ?? 1000);
}

/* ─────────────────────────── router ──────────────────────────── */
try {
    switch ($action) {

     /* ============================================================
     * ADD EXPENSE
     * POST ?action=addExpense
     * body: { usrName, shpReference | shpId | containerNo | sequenceNo,
     *         AccountNo, amount, narration }
     *
     *   Dr 20202028 (transport clearing)   amount
     *   Cr <AccountNo>                     amount
     *
     * Validation:
     *   - Container resolved by any of shpReference / shpId / containerNo / sequenceNo.
     *   - Customer (Cr) account must exist in accountDetails, be active, and be
     *     in the company base currency.
     *   - Clearing (Dr) account 20202028 must exist in `accounts` only — it is a
     *     GL control account and has no accountDetails / currency row.
     *   - Customer account must not equal the clearing account.
     *   - amount > 0.
     * ============================================================ */
    case 'addExpense': {
        $in = body();
        need($in, ['usrName', 'AccountNo', 'amount']);

        // ── 1. Resolve the container ────────────────────────────────
        $shp = loadShipment($conn, $in);
        if (!$shp) {
            fail('Container not found for the supplied reference/id/number', 404);
        }

        $trnRef      = (string)$shp['shpReference'];
        $containerNo = (string)$shp['containerNo'];
        $sequenceNo  = $shp['sequenceNo'] ?? null;

        // ── 2. Validate amount ──────────────────────────────────────
        $amount = (float)$in['amount'];
        if ($amount <= 0) {
            fail('amount must be > 0');
        }

        // ── 3. Customer (Cr) side — accountDetails + base currency ──
        $stake = loadBaseCcyAccount($conn, $in['AccountNo']);
        if (!$stake) {
            fail('Account not found: ' . $in['AccountNo'], 404);
        }
        if (!empty($stake['__wrongCcy'])) {
            fail(
                "Account {$stake['accNumber']} is in {$stake['accCcy']}, " .
                "but only " . baseCurrency($conn) . " accounts are allowed for expenses."
            );
        }
        if ((int)$stake['accNumber'] === TRANSPORT_CLEARING_ACCOUNT) {
            fail('Cannot post an expense against the clearing account as the credit side');
        }

        // ── 4. Clearing (Dr) side — GL control account ─────────────
        // Only needs to exist in `accounts`. No accountDetails row.
        $clearing = loadGlAccount($conn, TRANSPORT_CLEARING_ACCOUNT);
        if (!$clearing) {
            fail('Transport clearing account (20202028) not found in chart of accounts', 500);
        }

        $userNar = trim((string)($in['narration'] ?? ''));
        $ccy     = baseCurrency($conn);

        // ── 5. Post ─────────────────────────────────────────────────
        $conn->beginTransaction();
        try {
            $expId = nextExpenseId($conn, $trnRef);

            $head  = buildExpenseNarration($containerNo, $sequenceNo, $userNar);
            $drNar = $head . ' ' . expenseTag($expId);
            $crNar = $head . ' ' . expenseTag($expId, (int)$stake['accNumber']);

            // Dr — clearing (GL account, no accountDetails row)
            $conn->prepare(
                "INSERT INTO trnDetails
                    (trdReference, trdCcy, trdBranch, trdAccount,
                     trdDrCr, trdAmount, trdNarration, trdEntryDate)
                 VALUES (?, ?, ?, ?, 'Dr', ?, ?, NOW(3))"
            )->execute([
                $trnRef, $ccy, $branch, TRANSPORT_CLEARING_ACCOUNT,
                $amount, $drNar
            ]);

            // Cr — customer / stakeholder account
            $conn->prepare(
                "INSERT INTO trnDetails
                    (trdReference, trdCcy, trdBranch, trdAccount,
                     trdDrCr, trdAmount, trdNarration, trdEntryDate)
                 VALUES (?, ?, ?, ?, 'Cr', ?, ?, NOW(3))"
            )->execute([
                $trnRef, $ccy, $branch, (int)$stake['accNumber'],
                $amount, $crNar
            ]);

            $conn->commit();

            $dv->generateUserActivityLog(
                $me['usrName'], 'Add Shipment Expense',
                "Added expense $expId on ref $trnRef (container $containerNo) " .
                "amount $amount {$ccy} → acct {$stake['accNumber']}"
            );

            ok([
                'expId'         => $expId,
                'shpId'         => (int)$shp['shpId'],
                'shpReference'  => $trnRef,
                'containerNo'   => $containerNo,
                'sequenceNo'    => $sequenceNo,
                'amount'        => $amount,
                'ccy'           => $ccy,
                'drAccount'     => TRANSPORT_CLEARING_ACCOUNT,
                'crAccount'     => (int)$stake['accNumber'],
                'crAccountName' => $stake['accName'],
                'narration'     => $head,
            ]);
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            fail('Failed to add expense: ' . $e->getMessage(), 500);
        }
    }

        /* ============================================================
     * UPDATE EXPENSE
     * POST ?action=updateExpense
     * body: { usrName, shpReference, expId,
     *         AccountNo?, amount?, narration? }
     *
     * Reference is REQUIRED so we can never touch an expense on a
     * different container that happens to share the same expId.
     *
     * Rules:
     *   - shpReference + expId locate the pair — never trust trdID from client.
     *   - Only the fields supplied are changed; omitted fields keep old values.
     *   - Customer (Cr) account is re-validated against accountDetails +
     *     base currency if AccountNo is supplied (or if unchanged, the existing
     *     Cr account is re-validated anyway to be safe).
     *   - Dr row must still be on the clearing account — refuse otherwise.
     * ============================================================ */
    case 'updateExpense': {
        $in = body();
        need($in, ['usrName', 'shpReference', 'expId']);

        $trnRef = (string)$in['shpReference'];
        $expId  = (int)$in['expId'];
        if ($expId <= 0) {
            fail('expId required');
        }

        // ── 1. Resolve the container ────────────────────────────────
        $shp = loadShipment($conn, ['shpReference' => $trnRef]);
        if (!$shp) {
            fail('Container not found', 404);
        }

        // ── 2. Load the existing Dr / Cr pair ──────────────────────
        $rows = loadExpenseRows($conn, $trnRef, $expId);
        if (!$rows) {
            fail('Expense not found on this reference', 404);
        }

        $dr = $rows['dr'];
        $cr = $rows['cr'];

        // Sanity: Dr row must still be the clearing account
        if ((int)$dr['trdAccount'] !== TRANSPORT_CLEARING_ACCOUNT) {
            fail('Expense Dr row is not on the clearing account — refusing to edit', 500);
        }

        // ── 3. New values (fall back to current) ───────────────────
        $newAmount = array_key_exists('amount', $in)
            ? (float)$in['amount']
            : (float)$cr['trdAmount'];
        if ($newAmount <= 0) {
            fail('amount must be > 0');
        }

        $newCrAcc = (array_key_exists('AccountNo', $in) && $in['AccountNo'] !== '')
            ? (int)$in['AccountNo']
            : (int)$cr['trdAccount'];

        // ── 4. Validate the Cr account ─────────────────────────────
        $stake = loadBaseCcyAccount($conn, $newCrAcc);
        if (!$stake) {
            fail('Account not found: ' . $newCrAcc, 404);
        }
        if (!empty($stake['__wrongCcy'])) {
            fail(
                "Account {$stake['accNumber']} is in {$stake['accCcy']}, " .
                "but only " . baseCurrency($conn) . " accounts are allowed."
            );
        }
        if ((int)$stake['accNumber'] === TRANSPORT_CLEARING_ACCOUNT) {
            fail('Cannot use the clearing account as the credit side');
        }

        // ── 5. Clearing (Dr) side — re-validate GL account ─────────
        // (optional but cheap — catches someone deleting 20202028 from chart of accounts)
        $clearing = loadGlAccount($conn, TRANSPORT_CLEARING_ACCOUNT);
        if (!$clearing) {
            fail('Transport clearing account (20202028) not found in chart of accounts', 500);
        }

        // ── 6. Narration ───────────────────────────────────────────
        $userNar = array_key_exists('narration', $in)
            ? trim((string)$in['narration'])
            : extractUserNarration((string)$cr['trdNarration']);

        $head  = buildExpenseNarration(
            (string)$shp['containerNo'],
            $shp['sequenceNo'] ?? null,
            $userNar
        );
        $drNar = $head . ' ' . expenseTag($expId);
        $crNar = $head . ' ' . expenseTag($expId, (int)$stake['accNumber']);

        $ccy = baseCurrency($conn);

        // ── 7. Write ────────────────────────────────────────────────
        $conn->beginTransaction();
        try {
            $conn->prepare(
                "UPDATE trnDetails
                    SET trdAmount = ?, trdNarration = ?, trdCcy = ?, trdBranch = ?
                  WHERE trdID = ?"
            )->execute([
                $newAmount, $drNar, $ccy, $branch, (int)$dr['trdID']
            ]);

            $conn->prepare(
                "UPDATE trnDetails
                    SET trdAmount = ?, trdNarration = ?, trdCcy = ?, trdBranch = ?,
                        trdAccount = ?
                  WHERE trdID = ?"
            )->execute([
                $newAmount, $crNar, $ccy, $branch,
                (int)$stake['accNumber'], (int)$cr['trdID']
            ]);

            $conn->commit();

            $dv->generateUserActivityLog(
                $me['usrName'], 'Update Shipment Expense',
                "Updated expense $expId on ref $trnRef (amount $newAmount {$ccy})"
            );

            ok([
                'expId'         => $expId,
                'shpId'         => (int)$shp['shpId'],
                'shpReference'  => $trnRef,
                'amount'        => $newAmount,
                'ccy'           => $ccy,
                'drAccount'     => TRANSPORT_CLEARING_ACCOUNT,
                'crAccount'     => (int)$stake['accNumber'],
                'crAccountName' => $stake['accName'],
                'narration'     => $head,
            ]);
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            fail('Failed to update expense: ' . $e->getMessage(), 500);
        }
    }

    /* ============================================================
     * DELETE EXPENSE
     * POST ?action=deleteExpense  body: { usrName, shpReference, expId }
     * ============================================================ */
    case 'deleteExpense': {
        $in = body();
        need($in, ['usrName', 'shpReference', 'expId']);

        $trnRef = (string)$in['shpReference'];
        $expId  = (int)$in['expId'];
        if ($expId <= 0) fail('expId required');

        $rows = loadExpenseRows($conn, $trnRef, $expId);
        if (!$rows) fail('Expense not found on this reference', 404);

        $conn->beginTransaction();
        try {
            $conn->prepare(
                "DELETE FROM trnDetails WHERE trdID IN (?, ?)"
            )->execute([(int)$rows['dr']['trdID'], (int)$rows['cr']['trdID']]);

            $conn->commit();

            $dv->generateUserActivityLog(
                $me['usrName'], 'Delete Shipment Expense',
                "Deleted expense $expId on ref $trnRef"
            );

            ok(['expId' => $expId, 'shpReference' => $trnRef]);
        } catch (\Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            fail('Failed to delete expense: ' . $e->getMessage(), 500);
        }
    }

    /* ============================================================
     * LIST EXPENSES for a container
     * GET ?action=listExpenses
     *   &shpReference=...  OR  &shpId=1
     *   &containerNo=...   OR  &sequenceNo=...
     * ============================================================ */
    case 'listExpenses': {
        $shp = loadShipment($conn, [
            'shpReference' => $_GET['shpReference'] ?? null,
            'shpId'        => $_GET['shpId']        ?? null,
            'containerNo'  => $_GET['containerNo']  ?? null,
            'sequenceNo'   => $_GET['sequenceNo']   ?? null,
        ]);
        if (!$shp) fail('Container not found', 404);

        $trnRef = (string)$shp['shpReference'];

        // Every expense-tagged row on this reference
        $stmt = $conn->prepare(
            "SELECT trdID, trdReference, trdCcy, trdAccount, trdDrCr,
                    trdAmount, trdNarration, trdEntryDate
               FROM trnDetails
              WHERE trdReference = ?
                AND trdNarration LIKE '%[exp:%'
              ORDER BY trdID ASC"
        );
        $stmt->execute([$trnRef]);
        $all = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by expId, keeping only well-formed Dr/Cr pairs
        $groups = [];
        foreach ($all as $r) {
            if (!preg_match('/\[exp:(\d+)\]/', (string)$r['trdNarration'], $m)) continue;
            $id = (int)$m[1];
            $groups[$id] ??= ['dr' => null, 'cr' => null];

            $acc = (int)$r['trdAccount'];
            if ($r['trdDrCr'] === 'Dr' && $acc === TRANSPORT_CLEARING_ACCOUNT) {
                $groups[$id]['dr'] = $r;
            } elseif ($r['trdDrCr'] === 'Cr' && $acc !== TRANSPORT_CLEARING_ACCOUNT) {
                $groups[$id]['cr'] = $r;
            }
        }

        // Fetch account names in one shot
        $accIds = [];
        foreach ($groups as $g) {
            if ($g['cr']) $accIds[] = (int)$g['cr']['trdAccount'];
        }
        $accNames = [];
        if ($accIds) {
            $ph = implode(',', array_fill(0, count($accIds), '?'));
            $st = $conn->prepare("SELECT accNumber, accName FROM accounts WHERE accNumber IN ($ph)");
            $st->execute($accIds);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
                $accNames[(int)$a['accNumber']] = $a['accName'];
            }
        }

        $out = [];
        ksort($groups);
        foreach ($groups as $id => $g) {
            if (!$g['dr'] || !$g['cr']) continue;   // skip half-pairs
            $out[] = [
                'expId'         => $id,
                'shpId'         => (int)$shp['shpId'],
                'shpReference'  => $trnRef,
                'containerNo'   => $shp['containerNo'],
                'sequenceNo'    => $shp['sequenceNo'],
                'amount'        => (float)$g['cr']['trdAmount'],
                'ccy'           => $g['cr']['trdCcy'],
                'drAccount'     => TRANSPORT_CLEARING_ACCOUNT,
                'crAccount'     => (int)$g['cr']['trdAccount'],
                'crAccountName' => $accNames[(int)$g['cr']['trdAccount']] ?? '',
                'narration'     => extractUserNarration((string)$g['cr']['trdNarration']),
                'entryDate'     => $g['cr']['trdEntryDate'],
            ];
        }

        ok([
            'shpId'        => (int)$shp['shpId'],
            'shpReference' => $trnRef,
            'containerNo'  => $shp['containerNo'],
            'sequenceNo'   => $shp['sequenceNo'],
            'data'         => $out,
            'count'        => count($out),
        ]);
    }

    /* ============================================================
     * GET one expense
     * GET ?action=getExpense&shpReference=...&expId=1
     * ============================================================ */
    case 'getExpense': {
        $trnRef = trim((string)($_GET['shpReference'] ?? ''));
        $expId  = (int)($_GET['expId'] ?? 0);
        if ($trnRef === '') fail('shpReference required');
        if ($expId <= 0)    fail('expId required');

        $shp = loadShipment($conn, ['shpReference' => $trnRef]);
        if (!$shp) fail('Container not found', 404);

        $rows = loadExpenseRows($conn, $trnRef, $expId);
        if (!$rows) fail('Expense not found on this reference', 404);

        $cr = $rows['cr'];
        $accName = '';
        $st = $conn->prepare("SELECT accName FROM accounts WHERE accNumber = ? LIMIT 1");
        $st->execute([(int)$cr['trdAccount']]);
        $accName = (string)($st->fetchColumn() ?: '');

        ok([
            'expId'         => $expId,
            'shpId'         => (int)$shp['shpId'],
            'shpReference'  => $trnRef,
            'containerNo'   => $shp['containerNo'],
            'sequenceNo'    => $shp['sequenceNo'],
            'amount'        => (float)$cr['trdAmount'],
            'ccy'           => $cr['trdCcy'],
            'drAccount'     => TRANSPORT_CLEARING_ACCOUNT,
            'crAccount'     => (int)$cr['trdAccount'],
            'crAccountName' => $accName,
            'narration'     => extractUserNarration((string)$cr['trdNarration']),
            'entryDate'     => $cr['trdEntryDate'],
        ]);
    }

    default:
        fail('Unknown action: ' . $action, 404);
    }
} catch (\Throwable $th) {
    if ($conn->inTransaction()) $conn->rollBack();
    fail($th->getMessage(), 500, ['line' => $th->getLine(), 'file' => $th->getFile()]);
}
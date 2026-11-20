<?php
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

// ── Shutdown handler — no blank 500s, ever ─────────────────────
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array(
        $err['type'],
        [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR],
        true
    )) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'status'  => 'error',
            'message' => 'fatal: ' . $err['message'],
            'file'    => $err['file'],
            'line'    => $err['line'],
        ]);
    }
});

// ── Load Composer + .env + db.php ──────────────────────────────
require_once __DIR__ . '/../../vendor/autoload.php';

// safeLoad returns an array, never a loader — call it once, do
// not store the result. It populates $_ENV / $_SERVER / getenv().
Dotenv\Dotenv::createImmutable(__DIR__ . '/../..')->safeLoad();

require_once __DIR__ . '/../db.php';

// ── Token ──────────────────────────────────────────────────────
$restoreToken = $_ENV['RESTORE_API_TOKEN']
    ?? $_SERVER['RESTORE_API_TOKEN']
    ?? getenv('RESTORE_API_TOKEN')
    ?: '';

if ($restoreToken === '') {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Restore authentication is not configured.',
    ]);
    exit;
}

// ── Configuration ──────────────────────────────────────────────
$maxFileSize = 200 * 1024 * 1024; // 200 MB

// ── JSON response helper ───────────────────────────────────────
function respond($status, $message, $httpCode = 200)
{
    http_response_code($httpCode);
    echo json_encode([
        'status'  => $status,
        'message' => $message,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── SQL parser ─────────────────────────────────────────────────
// Splits SQL at semicolons outside strings and comments.
// Returns the raw statements (comments still attached); the caller
// strips leading comments and unwraps /*!...*/ blocks before use.
function splitSqlStatements($sql)
{
    $statements = [];
    $buffer = '';

    $length = strlen($sql);

    $quote = null;
    $lineComment = false;
    $blockComment = false;

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $next = $i + 1 < $length ? $sql[$i + 1] : '';

        // Inside line comment
        if ($lineComment) {
            $buffer .= $char;
            if ($char === "\n") {
                $lineComment = false;
            }
            continue;
        }

        // Inside block comment
        if ($blockComment) {
            $buffer .= $char;
            if ($char === '*' && $next === '/') {
                $buffer .= '/';
                $i++;
                $blockComment = false;
            }
            continue;
        }

        // Inside quoted string or identifier
        if ($quote !== null) {
            $buffer .= $char;

            if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                $buffer .= $sql[++$i];
                continue;
            }

            if ($char === $quote) {
                if ($next === $quote) {
                    $buffer .= $sql[++$i];
                } else {
                    $quote = null;
                }
            }
            continue;
        }

        // Start of quoted string or identifier
        if ($char === "'" || $char === '"' || $char === '`') {
            $quote = $char;
            $buffer .= $char;
            continue;
        }

        // Start of comments
        if (
            $char === '#' ||
            ($char === '-' && $next === '-' &&
                ($i + 2 >= $length || ord($sql[$i + 2]) <= 32))
        ) {
            $lineComment = true;
            $buffer .= $char;
            if ($char === '-') {
                $buffer .= $sql[++$i];
            }
            continue;
        }

        if ($char === '/' && $next === '*') {
            $blockComment = true;
            $buffer .= '/*';
            $i++;
            continue;
        }

        // Statement separator
        if ($char === ';') {
            $statement = trim($buffer);
            if ($statement !== '') {
                $statements[] = $statement;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    if ($quote !== null || $blockComment) {
        throw new Exception('Invalid SQL file: unfinished quoted string or comment.');
    }

    $last = trim($buffer);
    if ($last !== '') {
        $statements[] = $last;
    }

    return $statements;
}

// Strip leading -- comments and /* ... */ comments from a statement.
function stripLeadingComments($statement)
{
    // Line comments: -- ... \n   (also: # ... \n)
    $statement = preg_replace(
        '/^(?:\s*(?:--|#)[^\n]*(?:\n|$))+/m',
        '',
        $statement
    );

    // Block comments: /* ... */   (non-greedy, multiline)
    $statement = preg_replace(
        '/^(?:\s*\/\*(?!!).*?\*\/\s*)+/s',
        '',
        $statement
    );

    // Note: '/*!' (conditional comment) is intentionally NOT
    // stripped here — it's unwrapped separately in unwrapConditionalComment().
    return trim($statement);
}

// Unwrap MySQL conditional comments: /*!NNNNN <sql> */ → <sql>
// If the statement is a mix like `/*!40101 SET x=1 */ /* other */;`
// this unwraps the /*!...*/ part and leaves the rest.
function unwrapConditionalComment($statement)
{
    // Pure /*!...*/ block:
    if (preg_match('/^\/\*!\d*\s*(.*?)\s*\*\/\s*;?\s*$/s', $statement, $m)) {
        return trim($m[1]);
    }

    // /*!...*/ followed by normal SQL (rare but happens in dumps):
    if (preg_match('/^\/\*!\d*\s*(.*?)\s*\*\/\s*(.*)$/s', $statement, $m)) {
        $inner = trim($m[1]);
        $rest  = trim($m[2]);
        if ($rest === '') {
            return $inner;
        }
        return $inner . ' ' . $rest;
    }

    return $statement;
}

// Remove DEFINER from view creation statements.
function removeViewDefiner($sql)
{
    return preg_replace(
        '/\s+DEFINER\s*=\s*(?:`[^`]*`|\'[^\']*\'|[^\s@]+)\s*@\s*(?:`[^`]*`|\'[^\']*\'|[^\s]+)\s*/i',
        ' ',
        $sql
    );
}

// ── Main restore process ───────────────────────────────────────
try {

    // POST only
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        respond('error', 'POST method required.', 405);
    }

    // Require configured token
    if ($restoreToken === '') {
        respond('error', 'Restore authentication is not configured.', 500);
    }

    // Authorization
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authorization = $headers['Authorization']
        ?? $headers['authorization']
        ?? '';

    if (!preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches)) {
        respond('error', 'Unauthorized.', 401);
    }

    if (!hash_equals($restoreToken, trim($matches[1]))) {
        respond('error', 'Invalid authentication token.', 401);
    }

    // Check uploaded file
    if (!isset($_FILES['backup'])) {
        respond('error', 'Backup file is required.', 400);
    }

    $file = $_FILES['backup'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        respond('error', 'File upload failed. Error code: ' . $file['error'], 400);
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        respond('error', 'Invalid uploaded file.', 400);
    }

    if ($file['size'] <= 0 || $file['size'] > $maxFileSize) {
        respond('error', 'Backup file is empty or exceeds the configured size limit.', 413);
    }

    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($extension !== 'sql') {
        respond('error', 'Only .sql backup files are supported.', 400);
    }

    // Connect to Hostinger database
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        throw new Exception('Database connection failed.');
    }

    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Read SQL backup
    $sql = file_get_contents($file['tmp_name']);
    if ($sql === false || trim($sql) === '') {
        throw new Exception('Unable to read SQL backup file.');
    }

    // Split SQL
    $statements = splitSqlStatements($sql);
    if (empty($statements)) {
        throw new Exception('No SQL statements found.');
    }

    $total = count($statements);
    $executed = 0;
    $skipped = 0;

    foreach ($statements as $statement) {

        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }

        // 1. Strip leading comments (-- ... and /* ... */).
        $statement = stripLeadingComments($statement);
        if ($statement === '') {
            continue;
        }

        // 2. Unwrap /*!NNNNN ... */ conditional comments (mysqldump).
        $statement = unwrapConditionalComment($statement);
        if ($statement === '') {
            continue;
        }

        // 3. Skip dump-only commands that require privileges the
        //    web user doesn't have (LOCK TABLES / KEYS toggle).
        if (
            preg_match('/^LOCK\s+TABLES\b/i', $statement) ||
            preg_match('/^UNLOCK\s+TABLES\b/i', $statement) ||
            preg_match('/^ALTER\s+TABLE\b.*\b(DISABLE|ENABLE)\s+KEYS\b/i', $statement)
        ) {
            $skipped++;
            continue;
        }

        // 4. `USE <db>;` is a no-op — the connection is already on
        //    the target database. Skip it.
        if (preg_match('/^USE\b/i', $statement)) {
            $skipped++;
            continue;
        }

        // 5. Whitelist check.
        $allowed =
            preg_match('/^SET\b/i', $statement) ||
            preg_match('/^START\s+TRANSACTION\b/i', $statement) ||
            preg_match('/^COMMIT\b/i', $statement) ||
            preg_match('/^ROLLBACK\b/i', $statement) ||
            preg_match('/^DROP\s+(TABLE|VIEW)\b/i', $statement) ||
            preg_match('/^INSERT\s+INTO\b/i', $statement) ||
            preg_match('/^CREATE\b/i', $statement) ||
            preg_match('/^ALTER\s+TABLE\b/i', $statement);   // FK constraints from dumps

        if (!$allowed) {
            throw new Exception(
                'Unsupported SQL statement at statement number ' .
                ($executed + 1) . ' (starts with: "' .
                mb_substr($statement, 0, 80) . '…")'
            );
        }

        // 6. CREATE statements must create tables or views only.
        if (preg_match('/^CREATE\b/i', $statement)) {
            if (!preg_match('/\b(TABLE|VIEW)\b/i', $statement)) {
                throw new Exception(
                    'Unsupported CREATE statement (starts with: "' .
                    mb_substr($statement, 0, 80) . '…")'
                );
            }
        }

        // 7. Remove DEFINER from view creation.
        if (preg_match('/^CREATE\b.*\bVIEW\b/is', $statement)) {
            $statement = removeViewDefiner($statement);
        }

        // 8. Execute.
        try {
            $conn->exec($statement);
            $executed++;
        } catch (PDOException $e) {
            throw new Exception(
                'Restore failed at SQL statement ' .
                ($executed + 1) . ': ' . $e->getMessage() .
                ' — starts with: "' . mb_substr($statement, 0, 80) . '…"'
            );
        }
    }

    respond('success', 'Database restored successfully.', 200);

} catch (Throwable $e) {
    error_log('Database restore error: ' . $e->getMessage());
    respond('error', $e->getMessage(), 500);
}
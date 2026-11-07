<?php
ob_start();
error_reporting(E_ALL);
$debugErrors = in_array(strtolower((string) getenv('APP_ENV')), ['development', 'local'], true)
    || filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
ini_set('display_errors', $debugErrors ? '1' : '0');
ini_set('display_startup_errors', $debugErrors ? '1' : '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');
$responseSent = false;
function clearOutputBuffers()
{
    while (ob_get_level() > 0) {
        ob_clean();
        if (!ob_end_clean()) {
            break;
        }
    }
}

function sendJsonErrorResponse($message)
{
    global $responseSent;
    if ($responseSent) {
        exit();
    }

    clearOutputBuffers();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    $responseSent = true;
    $json = json_encode(
        ['success' => false, 'message' => $message],
        JSON_INVALID_UTF8_SUBSTITUTE
    );
    echo $json === false
        ? '{"success":false,"message":"The queue service encountered an unexpected server error. Please try again later."}'
        : $json;
    exit();
}

set_exception_handler(static function (Throwable $error) {
    error_log(sprintf(
        'Uncaught Queue API exception (%s, code %s): %s in %s:%d',
        get_class($error),
        (string) $error->getCode(),
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
    ));
    sendJsonErrorResponse('The queue service encountered an unexpected server error. Please try again later.');
});
set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }

    throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(static function () use (&$responseSent) {
    $error = error_get_last();
    if (
        $responseSent ||
        !$error ||
        !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
    ) {
        return;
    }

    error_log(sprintf(
        'Fatal Queue API error (%s): %s in %s:%d',
        (string) $error['type'],
        $error['message'],
        $error['file'],
        $error['line']
    ));
    sendJsonErrorResponse('The queue service encountered an unexpected server error. Please try again later.');
});

function respond($status, $payload)
{
    global $responseSent;
    clearOutputBuffers();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    http_response_code($status);
    $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    $responseSent = true;
    echo $json === false
        ? '{"success":false,"message":"Failed to encode API response"}'
        : $json;
    exit();
}

function executeQuery(PDOStatement $statement, array $parameters = [])
{
    global $lastFailedQuery;
    $lastFailedQuery = null;
    try {
        return $statement->execute($parameters);
    } catch (PDOException $error) {
        $lastFailedQuery = [
            'sql' => $statement->queryString,
            'parameters' => $parameters,
        ];
        throw $error;
    }
}

function prepareQuery(PDO $connection, string $sql)
{
    global $lastFailedQuery;
    $lastFailedQuery = ['sql' => $sql, 'parameters' => []];
    $statement = $connection->prepare($sql);
    $lastFailedQuery = null;
    return $statement;
}

function isDevelopmentMode()
{
    return in_array(strtolower((string) getenv('APP_ENV')), ['development', 'local'], true)
        || filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
}

function handleApiException(Throwable $error)
{
    global $conn, $requestId, $lastFailedQuery;

    if (($conn ?? null) instanceof PDO && $conn->inTransaction()) {
        try {
            $conn->rollBack();
        } catch (Throwable $rollbackError) {
            error_log(sprintf(
                'Queue API rollback failure [%s]: %s',
                $requestId ?? 'unavailable',
                $rollbackError->getMessage()
            ));
        }
    }

    $sqlState = $error instanceof PDOException
        ? (string) ($error->errorInfo[0] ?? $error->getCode())
        : (string) $error->getCode();
    $diagnostic = [
        'request_id' => $requestId ?? 'unavailable',
        'exception' => get_class($error),
        'sqlstate' => $sqlState,
        'message' => $error->getMessage(),
        'file' => $error->getFile(),
        'line' => $error->getLine(),
    ];
    if ($error instanceof PDOException && $lastFailedQuery !== null) {
        $diagnostic['sql'] = $lastFailedQuery['sql'];
        $diagnostic['parameter_count'] = count($lastFailedQuery['parameters']);
        if (isDevelopmentMode()) {
            $diagnostic['parameters'] = $lastFailedQuery['parameters'];
        }
    }
    error_log('Queue API error: ' . json_encode($diagnostic, JSON_INVALID_UTF8_SUBSTITUTE));

    $isConnectionFailure = $error instanceof PDOException
        && (str_starts_with($sqlState, '08') || in_array($sqlState, ['57P01', '57P02', '57P03'], true));
    $payload = [
        'success' => false,
        'code' => $isConnectionFailure ? 'DATABASE_UNAVAILABLE' : 'INTERNAL_SERVER_ERROR',
        'request_id' => $requestId ?? null,
        'message' => $isConnectionFailure
            ? 'The database is temporarily unavailable. Please try again shortly.'
            : 'The queue service encountered a server or database error. Please try again later.',
    ];
    if (isDevelopmentMode()) {
        $payload['message'] = $error instanceof PDOException
            ? 'Database error: ' . $error->getMessage()
            : 'Server error: ' . $error->getMessage();
        $payload['debug'] = [
            'exception' => get_class($error),
            'sqlstate' => $sqlState,
            'sql' => $lastFailedQuery['sql'] ?? null,
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ];
    }

    respond($isConnectionFailure ? 503 : 500, $payload);
}

function requestString($input, $key, $maximumLength = 500)
{
    $value = $input[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) {
        respond(400, ['success' => false, 'message' => "Invalid value for $key"]);
    }

    $value = trim((string) $value);
    if (strlen($value) > $maximumLength) {
        respond(400, ['success' => false, 'message' => "$key exceeds the allowed length"]);
    }

    return $value;
}

function emptyPaymentActivityPayload($pageSize = 50)
{
    $today = new DateTimeImmutable('today', new DateTimeZone(date_default_timezone_get()));
    $activity = [];
    for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
        $activityDate = $today->modify("-$daysAgo days")->format('Y-m-d');
        $activity[] = [
            'activity_date' => $activityDate,
            'records' => 0,
        ];
    }

    return [
        'configured' => true,
        'success' => true,
        'status' => 'success',
        'data' => [],
        'total_records' => 0,
        'today_records' => 0,
        'pagination' => [
            'page' => 1,
            'page_size' => $pageSize,
            'total' => 0,
            'pages' => 1,
        ],
        'metrics' => [
            'total_records' => 0,
            'today_records' => 0,
            'active_queue_count' => 0,
        ],
        'statuses' => [],
        'activity' => $activity,
    ];
}

function verifyRequestOrigin()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $requestHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    $originPort = parse_url($origin, PHP_URL_PORT);
    $expectedHost = preg_replace('/:\d+$/', '', $requestHost);
    $requestPort = parse_url(
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
            . '://' . $requestHost,
        PHP_URL_PORT
    );

    if (
        $origin === '' ||
        !in_array(parse_url($origin, PHP_URL_SCHEME), ['http', 'https'], true) ||
        $originHost !== $expectedHost ||
        ($originPort !== null && $requestPort !== null && $originPort !== $requestPort)
    ) {
        respond(403, ['success' => false, 'message' => 'Request origin is not authorized']);
    }
}

function requireRole($role, $touchActivity = true)
{
    if (
        isset($_SESSION['last_activity']) &&
        time() - (int) $_SESSION['last_activity'] > 1800
    ) {
        $_SESSION = [];
        session_destroy();
        respond(401, ['success' => false, 'message' => 'Your session expired. Sign in again.']);
    }
    if (($_SESSION['role'] ?? '') !== $role) {
        respond(401, ['success' => false, 'message' => 'Authentication required']);
    }
    if ($touchActivity) {
        $_SESSION['last_activity'] = time();
    }
}

function requireCsrfToken()
{
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) {
        respond(403, ['success' => false, 'message' => 'Your session expired. Sign in again and retry.']);
    }
}

function issueSession($role, $identity)
{
    session_regenerate_id(true);
    $_SESSION = [
        'role' => $role,
        'identity' => $identity,
        'csrf_token' => bin2hex(random_bytes(32)),
        'created_at' => time(),
        'last_activity' => time(),
    ];
}

function restrictTicketForRole($ticket, $role, $studentId = '', $hasActiveQueue = true)
{
    if (($ticket['transaction_type'] ?? '') !== 'Tuition Payment') {
        foreach (['payment_description', 'payment_period'] as $field) {
            $ticket[$field] = null;
        }
    }

    if ($role === 'student' && (($ticket['student_id'] ?? '') !== $studentId || !$hasActiveQueue)) {
        foreach (['student_name', 'student_id', 'course', 'address', 'year_level', 'payment_description', 'payment_period', 'notes'] as $field) {
            $ticket[$field] = null;
        }
    }

    return $ticket;
}

try {
    $requestId = bin2hex(random_bytes(8));
    $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    session_name('CCDI_QUEUE_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    verifyRequestOrigin();

    $requestTimezone = getenv('APP_TIMEZONE');
    date_default_timezone_set($requestTimezone !== false && $requestTimezone !== '' ? $requestTimezone : 'Asia/Manila');
    $today = date('Y-m-d');
    $conn = null;

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';

    if ($method === 'POST') {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 16384) {
            respond(413, ['success' => false, 'message' => 'Request body is too large']);
        }
        $requestBody = file_get_contents('php://input');
        if ($requestBody === false || trim($requestBody) === '') {
            respond(400, ['success' => false, 'message' => 'Request body must be valid JSON']);
        }
        try {
            $input = json_decode($requestBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            respond(400, ['success' => false, 'message' => 'Request body must be valid JSON']);
        }
        if (!is_array($input)) {
            respond(400, ['success' => false, 'message' => 'Request body must be a JSON object']);
        }
        $action = $input['action'] ?? '';
    } elseif ($method !== 'GET') {
        respond(405, ['success' => false, 'message' => 'Method not allowed']);
    }
    if (!is_string($action)) {
        respond(400, ['success' => false, 'message' => 'Invalid action']);
    }
    if ($method === 'GET' && $action === 'get_c_status-all') {
        $action = 'get_cashier_payment_activity_records';
    }

    $routes = [
        'GET' => ['auth_status', 'get_queue_state', 'get_state', 'get_cashier_payment_activity_records'],
        'POST' => [
            'cashier_login',
            'student_login',
            'logout',
            'create_ticket',
            'call_next',
            'update_status',
            'complete_ticket',
            'recall_ticket',
            'reset_day',
        ],
    ];
    if (!in_array($action, $routes[$method], true)) {
        $knownActions = array_merge(...array_values($routes));
        if (in_array($action, $knownActions, true)) {
            respond(405, ['success' => false, 'message' => 'Action is not supported for this request method']);
        }
        respond(400, ['success' => false, 'message' => 'Unknown action']);
    }

    $conn = null;
    if (
        !in_array($action, ['logout', 'auth_status'], true) ||
        ($action === 'auth_status' && ($_SESSION['role'] ?? '') === 'student')
    ) {
        try {
            require_once __DIR__ . '/db.php';
        } catch (PDOException $error) {
            error_log(sprintf(
                'Queue API database connection failure [%s]: %s',
                $requestId,
                $error->getMessage()
            ));
            respond(503, [
                'success' => false,
                'code' => 'DATABASE_UNAVAILABLE',
                'request_id' => $requestId,
                'message' => 'The database is temporarily unavailable. Please try again shortly.',
            ]);
        } catch (RuntimeException $error) {
            error_log(sprintf(
                'Queue API database configuration failure [%s]: %s',
                $requestId,
                $error->getMessage()
            ));
            respond(503, [
                'success' => false,
                'code' => 'DATABASE_CONFIGURATION_INVALID',
                'request_id' => $requestId,
                'message' => 'The database is not configured. Set the required PostgreSQL connection settings and try again.',
            ]);
        }
    }

    if ($method === 'POST' && $action === 'cashier_login') {
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        if (!is_string($username) || !is_string($password)) {
            respond(400, ['success' => false, 'message' => 'Mangyaring ilagay ang Username at Password.']);
        }

        $username = trim($username);
        $password = trim($password);
        if ($username === '' || $password === '') {
            respond(400, ['success' => false, 'message' => 'Mangyaring ilagay ang Username at Password.']);
        }

        $stmt = prepareQuery($conn,
            'SELECT * FROM public.system_users WHERE username = ? AND is_active = TRUE'
        );
        executeQuery($stmt, [$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && ($password === $user['password'] || password_verify($password, $user['password']))) {
            $role = strtolower(trim((string) ($user['role'] ?? '')));
            if ($role !== 'cashier') {
                respond(403, ['success' => false, 'message' => 'This account is not authorized for the cashier portal.']);
            }
            issueSession('cashier', ['staff_id' => $username]);
            respond(200, [
                'success' => true,
                'message' => 'Matagumpay na nakapag-log in!',
                'role' => $role,
                'csrf_token' => $_SESSION['csrf_token'],
            ]);
        }

        respond(401, ['success' => false, 'message' => 'Mali ang Username o Password.']);
    }

    if ($method === 'POST' && $action === 'student_login') {
        $studentId = requestString($input, 'studentId', 64);
        $studentName = requestString($input, 'studentName', 120);
        if ($studentId === '' || $studentName === '') {
            respond(400, [
                'success' => false,
                'code' => 'STUDENT_DETAILS_REQUIRED',
                'fields' => ['studentId', 'studentName'],
                'message' => 'Enter both the Student ID and full name.',
            ]);
        }

        $student = prepareQuery($conn,
            "SELECT student_number,
                    student_number AS student_id,
                    concat_ws(' ', NULLIF(btrim(first_name), ''), NULLIF(btrim(last_name), '')) AS full_name,
                    NULL AS course, NULL AS address, NULL AS year_level
             FROM public.student_profiles
             WHERE student_number = ?
               AND lower(concat_ws(' ', btrim(first_name), btrim(last_name))) = lower(btrim(?))
             LIMIT 1"
        );
        executeQuery($student, [$studentId, $studentName]);
        $profile = $student->fetch();
        if (!$profile) {
            respond(403, [
                'success' => false,
                'code' => 'STUDENT_RECORD_MISMATCH',
                'fields' => ['studentId', 'studentName'],
                'message' => 'The Student ID and full name do not match an active CCDI student record. Check the ID and spelling, and use the full name on the record.',
            ]);
        }

        issueSession('student', ['student_id' => $profile['student_number']]);
        $_SESSION['student_id'] = $profile['student_number'];
        respond(200, [
            'success' => true,
            'role' => 'student',
            'csrf_token' => $_SESSION['csrf_token'],
            'student' => $profile,
        ]);
    }

    if ($method === 'GET' && $action === 'auth_status') {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, ['student', 'cashier'], true)) {
            respond(200, ['success' => true, 'authenticated' => false]);
        }
        if (time() - (int) ($_SESSION['last_activity'] ?? 0) > 1800) {
            $_SESSION = [];
            session_destroy();
            respond(200, ['success' => true, 'authenticated' => false]);
        }
        $_SESSION['last_activity'] = time();

        $identity = $_SESSION['identity'] ?? [];
        $response = [
            'success' => true,
            'authenticated' => true,
            'role' => $role,
            'csrf_token' => $_SESSION['csrf_token'],
        ];
        if ($role === 'student') {
            $student = prepareQuery($conn,
                "SELECT student_number AS student_id,
                        btrim(first_name) || ' ' || btrim(last_name) AS full_name,
                NULL AS course, NULL AS address, NULL AS year_level
                 FROM public.student_profiles WHERE student_number = ? LIMIT 1"
            );
            executeQuery($student, [$identity['student_id'] ?? '']);
            $profile = $student->fetch();
            if (!$profile) {
                session_destroy();
                respond(401, ['success' => false, 'message' => 'Student record is no longer available. Sign in again.']);
            }
            $response['student'] = $profile;
        }
        respond(200, $response);
    }

    if ($method === 'POST' && $action === 'logout') {
        requireCsrfToken();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict',
            ]);
        }
        session_destroy();
        respond(200, ['success' => true]);
    }

    if ($method === 'GET' && in_array($action, ['get_queue_state', 'get_state'], true)) {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, ['student', 'cashier'], true)) {
            respond(401, ['success' => false, 'message' => 'Sign in to view the queue']);
        }
        requireRole($role, false);
        $studentId = $role === 'student' ? (string) ($_SESSION['identity']['student_id'] ?? '') : '';
        $hasActiveQueue = true;
        if ($role === 'student' && $studentId !== '') {
            $actCheck = prepareQuery($conn,
                "SELECT 1 FROM public.queue_tickets
                 WHERE service_date = ? AND student_id = ?
                   AND status IN ('waiting', 'serving', 'completed')
                 LIMIT 1"
            );
            executeQuery($actCheck, [$today, $studentId]);
            $hasActiveQueue = (bool) $actCheck->fetchColumn();
        }
        $stmt = prepareQuery($conn,
            'SELECT * FROM public.queue_tickets WHERE service_date = ? ORDER BY created_at ASC, ticket_id ASC'
        );
        executeQuery($stmt, [$today]);
        $tickets = array_map(
            static fn($ticket) => restrictTicketForRole($ticket, $role, $studentId, $hasActiveQueue),
            $stmt->fetchAll()
        );
        respond(200, ['success' => true, 'data' => $tickets, 'has_active_queue' => $hasActiveQueue]);
    }

    if ($method === 'GET' && $action === 'get_cashier_payment_activity_records') {
        requireRole('cashier');

        $sourceQueries = [];
        $sourceProbeError = null;
        foreach (['payment_histories', 'payment_activity_records'] as $tableName) {
            $tableQuery = prepareQuery($conn, 'SELECT to_regclass(?) IS NOT NULL');
            executeQuery($tableQuery, ['public.' . $tableName]);
            if (!(bool) $tableQuery->fetchColumn()) {
                continue;
            }

            $jsonValue = static function (array $keys): string {
                $expressions = array_map(
                    static fn($key) => "NULLIF(to_jsonb(payment_record)->>'$key', '')",
                    $keys
                );
                return count($expressions) === 1
                    ? $expressions[0]
                    : 'COALESCE(' . implode(', ', $expressions) . ')';
            };
            $courseYear = $jsonValue(['course_year']);
            $courseAndYear = "NULLIF(concat_ws(' / ', " .
                $jsonValue(['course']) . ', ' . $jsonValue(['year_level']) . "), '')";
            $createdAt = $jsonValue(['created_at', 'date_time']);
            
            // Inalis at nilinis ang amount/peso sign para sakto sa delimitations
            $sourceQuery = "SELECT
                    {$jsonValue(['transaction_ref', 'transaction_reference', 'reference_number'])} AS transaction_ref,
                    {$jsonValue(['student_id'])} AS student_id,
                    {$jsonValue(['student_name'])} AS student_name,
                    COALESCE($courseYear, $courseAndYear) AS course_year,
                    REGEXP_REPLACE({$jsonValue(['payment_type', 'payment_description', 'payment_method', 'transaction_type'])}, '\s*[·\-–]\s*₱?\s*[\d,.]+', '', 'g') AS payment_type,
                    0.00 AS amount,
                    $createdAt::timestamptz AS created_at,
                    {$jsonValue(['cashier_staff'])} AS cashier_staff,
                    COALESCE({$jsonValue(['status'])}, 'recorded') AS status
                FROM public.$tableName AS payment_record";
            try {
                $sourceProbe = prepareQuery($conn, $sourceQuery . ' LIMIT 0');
                executeQuery($sourceProbe);
                $sourceQueries[] = $sourceQuery;
            } catch (PDOException $error) {
                $sourceProbeError = $error;
                error_log(sprintf(
                    'Queue API payment activity source unavailable [%s, table %s, SQLSTATE %s]: %s',
                    $requestId,
                    $tableName,
                    (string) ($error->errorInfo[0] ?? $error->getCode()),
                    $error->getMessage()
                ));
            }
        }

        if (!$sourceQueries) {
            if ($sourceProbeError ?? null) {
                throw $sourceProbeError;
            }
            respond(200, emptyPaymentActivityPayload());
        }
        $recordsSourceSql = '(' . implode(' UNION ', $sourceQueries) . ') AS payment_histories';

        $statusOptionsQuery = prepareQuery($conn,
            "SELECT DISTINCT lower(trim(payment_histories.status)) AS status
             FROM $recordsSourceSql
             WHERE NULLIF(trim(payment_histories.status), '') IS NOT NULL
             ORDER BY status"
        );
        executeQuery($statusOptionsQuery);
        $availableStatuses = array_column($statusOptionsQuery->fetchAll(), 'status');

        $range = $_GET['range'] ?? 'all';
        if (!is_string($range)) {
            respond(400, ['success' => false, 'message' => 'Invalid payment activity date range']);
        }
        if (!in_array($range, ['all', 'today', '7days', '30days'], true)) {
            respond(400, ['success' => false, 'message' => 'Invalid payment activity date range']);
        }

        $statusInput = $_GET['status'] ?? 'all';
        if (!is_string($statusInput)) {
            respond(400, ['success' => false, 'message' => 'Invalid payment activity status']);
        }
        $status = strtolower(trim($statusInput));
        if (strlen($status) > 32 || ($status !== 'all' && !in_array($status, $availableStatuses, true))) {
            respond(400, ['success' => false, 'message' => 'Invalid payment activity status']);
        }

        $searchInput = $_GET['search'] ?? '';
        if (!is_string($searchInput)) {
            respond(400, ['success' => false, 'message' => 'Invalid payment activity search']);
        }
        $search = trim($searchInput);
        if (strlen($search) > 120) {
            respond(400, ['success' => false, 'message' => 'Payment activity search is too long']);
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $localToday = new DateTimeImmutable('today', $timezone);
        $startDate = match ($range) {
            'today' => $localToday,
            '7days' => $localToday->modify('-6 days'),
            '30days' => $localToday->modify('-29 days'),
            default => null,
        };
        $endOfDay = $localToday->modify('+1 day')->format('Y-m-d\TH:i:sP');

        $where = [];
        $parameters = [];
        if ($search !== '') {
            $where[] = "(payment_histories.transaction_ref ILIKE ? OR payment_histories.student_id ILIKE ?
                         OR payment_histories.student_name ILIKE ? OR COALESCE(payment_histories.course_year, '') ILIKE ?
                         OR payment_histories.payment_type ILIKE ? OR payment_histories.cashier_staff ILIKE ?)";
            $searchPattern = '%' . $search . '%';
            array_push(
                $parameters,
                $searchPattern,
                $searchPattern,
                $searchPattern,
                $searchPattern,
                $searchPattern,
                $searchPattern
            );
        }
        if ($status !== 'all') {
            $where[] = 'lower(trim(payment_histories.status)) = ?';
            $parameters[] = $status;
        }
        if ($startDate !== null) {
            $where[] = 'payment_histories.created_at >= ? AND payment_histories.created_at < ?';
            $parameters[] = $startDate->format('Y-m-d\TH:i:sP');
            $parameters[] = $endOfDay;
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $countQuery = prepareQuery($conn, 'SELECT COUNT(*) FROM ' . $recordsSourceSql . $whereSql);
        executeQuery($countQuery, $parameters);
        $filteredCount = (int) $countQuery->fetchColumn();

        $pageSize = 50;
        $page = filter_var($_GET['page'] ?? '1', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($page === false) {
            respond(400, ['success' => false, 'message' => 'Invalid payment activity page']);
        }
        $page = min($page, max(1, (int) ceil($filteredCount / $pageSize)));
        $recordQuery = prepareQuery($conn,
            'SELECT payment_histories.transaction_ref, payment_histories.student_id,
                    payment_histories.student_name, payment_histories.course_year,
                    payment_histories.payment_type, payment_histories.amount, payment_histories.created_at,
                    payment_histories.cashier_staff, payment_histories.status
             FROM ' . $recordsSourceSql . $whereSql .
            ' ORDER BY payment_histories.created_at DESC, payment_histories.transaction_ref DESC' .
            ' LIMIT ' . $pageSize . ' OFFSET ' . (($page - 1) * $pageSize)
        );
        executeQuery($recordQuery, $parameters);
        $records = $recordQuery->fetchAll();

        $metricsQuery = prepareQuery($conn,
            "SELECT COUNT(*) AS total_records,
                    COUNT(*) FILTER (WHERE payment_histories.created_at >= ? AND payment_histories.created_at < ?) AS today_records
             FROM $recordsSourceSql"
        );
        executeQuery($metricsQuery, [
            $localToday->format('Y-m-d\TH:i:sP'),
            $endOfDay,
        ]);
        $metrics = $metricsQuery->fetch();

        $queueCountQuery = prepareQuery($conn,
            "SELECT COUNT(*) FROM public.queue_tickets
             WHERE service_date = ? AND status IN ('waiting', 'serving')"
        );
        executeQuery($queueCountQuery, [$today]);

        $activityQuery = prepareQuery($conn,
            "SELECT (payment_histories.created_at AT TIME ZONE ?)::date AS activity_date,
                    COUNT(*) AS records
             FROM $recordsSourceSql
             WHERE payment_histories.created_at >= ? AND payment_histories.created_at < ?
             GROUP BY 1
             ORDER BY activity_date"
        );
        executeQuery($activityQuery, [
            date_default_timezone_get(),
            $localToday->modify('-6 days')->format('Y-m-d\TH:i:sP'),
            $endOfDay,
        ]);
        $activityByDate = [];
        foreach ($activityQuery->fetchAll() as $activityRow) {
            $activityByDate[$activityRow['activity_date']] = $activityRow;
        }
        $activity = [];
        for ($daysAgo = 6; $daysAgo >= 0; $daysAgo--) {
            $activityDate = $localToday->modify("-$daysAgo days")->format('Y-m-d');
            $activity[] = $activityByDate[$activityDate] ?? [
                'activity_date' => $activityDate,
                'records' => 0,
            ];
        }

        respond(200, [
            'configured' => true,
            'success' => true,
            'status' => 'success',
            'data' => $records,
            'total_records' => (int) $metrics['total_records'],
            'today_records' => (int) $metrics['today_records'],
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $filteredCount,
                'pages' => max(1, (int) ceil($filteredCount / $pageSize)),
            ],
            'metrics' => [
                'total_records' => (int) $metrics['total_records'],
                'today_records' => (int) $metrics['today_records'],
                'active_queue_count' => (int) $queueCountQuery->fetchColumn(),
            ],
            'statuses' => $availableStatuses,
            'activity' => $activity,
        ]);
    }

    if ($action === 'create_ticket') {
        if (!in_array($_SESSION['role'] ?? '', ['student', 'cashier'], true)) {
            respond(401, ['success' => false, 'message' => 'Authentication required']);
        }
        requireRole($_SESSION['role']);
        requireCsrfToken();
    } else {
        requireRole('cashier');
        requireCsrfToken();
    }

    if ($action === 'create_ticket') {
        $lane = requestString($input, 'lane', 16);
        $studentName = requestString($input, 'studentName', 120);
        $studentId = requestString($input, 'studentId', 64);
        $service = requestString($input, 'service', 120);
        $payment = requestString($input, 'payment', 240);
        $paymentPeriod = requestString($input, 'paymentPeriod', 64);
        $notes = requestString($input, 'notes', 240);

        if (!in_array($lane, ['Regular', 'Priority'], true)) {
            respond(400, ['success' => false, 'message' => 'Lane must be Regular or Priority']);
        }
        if ($studentName === '' || $studentId === '') {
            respond(400, [
                'success' => false,
                'code' => 'STUDENT_DETAILS_REQUIRED',
                'fields' => ['studentId', 'studentName'],
                'message' => 'Enter both the Student ID and full name.',
            ]);
        }
        if ($service === '' || $payment === '') {
            respond(400, ['success' => false, 'message' => 'Service and payment are required']);
        }

        $conn->beginTransaction();
        $dailyLock = prepareQuery($conn, 'SELECT pg_advisory_xact_lock(hashtext(?))');
        executeQuery($dailyLock, ["daily-operations:$today"]);
        $student = prepareQuery($conn,
            "SELECT student_number AS student_id,
                    btrim(first_name) || ' ' || btrim(last_name) AS full_name,
            NULL AS course, NULL AS address, NULL AS year_level
             FROM public.student_profiles
             WHERE lower(btrim(student_number)) = lower(btrim(?))
               AND regexp_replace(
                     lower(btrim(first_name) || ' ' || btrim(last_name)),
                     '[[:space:]]+', ' ', 'g'
                   ) = regexp_replace(lower(btrim(?)), '[[:space:]]+', ' ', 'g')
             LIMIT 1"
        );
        executeQuery($student, [$studentId, $studentName]);
        $profile = $student->fetch();
        if (!$profile) {
            $conn->rollBack();
            respond(403, [
                'success' => false,
                'code' => 'STUDENT_RECORD_MISMATCH',
                'fields' => ['studentId', 'studentName'],
                'message' => 'The Student ID and full name do not match an active CCDI student record. Check the ID and spelling, and use the full name on the record.',
            ]);
        }
        if (
            ($_SESSION['role'] ?? '') === 'student' &&
            !hash_equals((string) ($_SESSION['identity']['student_id'] ?? ''), (string) $profile['student_id'])
        ) {
            $conn->rollBack();
            respond(403, ['success' => false, 'message' => 'You may only create a ticket for the student account currently signed in.']);
        }

        if (!in_array($service, [
            'Tuition Payment',
            'Cert. of Enrollment',
            'Certificate of Enrollment',
            'Cert. of Registration',
            'Certificate of Registration',
            'Transcript of Records (TOR)',
            'Student Clearance',
            'Other School Documents',
            'Other School Fees',
        ], true)) {
            $conn->rollBack();
            respond(400, ['success' => false, 'message' => 'Select a supported service']);
        }
        if ($service !== 'Tuition Payment') {
            $paymentPeriod = '';
            $payment = 'N/A (Document Only)';
        } elseif (!in_array($payment, [
            'Full Payment',
            'Partial Payment',
            'Promissory Note',
            'Promissory',
        ], true)) {
            $conn->rollBack();
            respond(400, ['success' => false, 'message' => 'Select a supported tuition payment option']);
        }
        $studentLock = prepareQuery($conn, 'SELECT pg_advisory_xact_lock(hashtext(?))');
        executeQuery($studentLock, ["student:$today:{$profile['student_id']}"]);
        $active = prepareQuery($conn,
            "SELECT 1 FROM public.queue_tickets
             WHERE service_date = ? AND student_id = ? AND status IN ('waiting', 'serving')
             LIMIT 1"
        );
        executeQuery($active, [$today, $profile['student_id']]);
        if ($active->fetchColumn()) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'This student already has an active ticket today']);
        }

        $dailyCount = prepareQuery($conn,
            "SELECT
                (SELECT COUNT(*) FROM public.queue_tickets WHERE service_date = ?) +
                (SELECT COALESCE(SUM(total_tickets), 0) FROM public.daily_reports WHERE report_date = ?)"
        );
        executeQuery($dailyCount, [$today, $today]);
        if ((int) $dailyCount->fetchColumn() >= 100) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'Daily ticket limit of 100 reached for today.']);
        }

        $count = prepareQuery($conn,
            "SELECT
                (SELECT COUNT(*) FROM public.queue_tickets WHERE lane = ? AND service_date = ?) +
                (SELECT COALESCE(SUM(
                    CASE WHEN ? = 'Priority' THEN priority_tickets ELSE regular_tickets END
                 ), 0)
                 FROM public.daily_reports WHERE report_date = ?)"
        );
        executeQuery($count, [$lane, $today, $lane, $today]);
        $prefix = $lane === 'Priority' ? 'P' : 'A';
        $ticketNumber = $prefix . '-' . str_pad((string) ((int) $count->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
        $insert = prepareQuery($conn,
            "INSERT INTO public.queue_tickets
                (ticket_number, lane, student_name, student_id, course, address, year_level,
                 transaction_type, payment_description, payment_period, notes, status, service_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'waiting', ?)
             RETURNING ticket_id"
        );
        executeQuery($insert, [
            $ticketNumber,
            $lane,
            $profile['full_name'],
            $profile['student_id'],
            $profile['course'],
            $profile['address'],
            $profile['year_level'],
            $service,
            $payment,
            $paymentPeriod,
            $notes,
            $today,
        ]);
        $ticketId = (int) $insert->fetchColumn();
        $conn->commit();
        respond(201, ['success' => true, 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber]);
    }

    requireRole('cashier');

    if ($action === 'call_next') {
        $lane = requestString($input, 'lane', 16);
        if (!in_array($lane, ['Regular', 'Priority'], true)) {
            respond(400, ['success' => false, 'message' => 'Lane must be Regular or Priority']);
        }

        $conn->beginTransaction();
        $dailyLock = prepareQuery($conn, 'SELECT pg_advisory_xact_lock(hashtext(?))');
        executeQuery($dailyLock, ["daily-operations:$today"]);
        $lock = prepareQuery($conn, 'SELECT pg_advisory_xact_lock(hashtext(?))');
        executeQuery($lock, ["serving:$today:$lane"]);
        $serving = prepareQuery($conn,
            "SELECT 1 FROM public.queue_tickets
             WHERE service_date = ? AND lane = ? AND status = 'serving' LIMIT 1"
        );
        executeQuery($serving, [$today, $lane]);
        if ($serving->fetchColumn()) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'A ticket is already being served in this lane']);
        }
        $next = prepareQuery($conn,
            "SELECT ticket_id FROM public.queue_tickets
             WHERE service_date = ? AND lane = ? AND status = 'waiting'
             ORDER BY created_at ASC, ticket_id ASC LIMIT 1"
        );
        executeQuery($next, [$today, $lane]);
        $ticketId = $next->fetchColumn();
        if ($ticketId === false) {
            $conn->rollBack();
            respond(404, ['success' => false, 'message' => 'No tickets are waiting in this lane']);
        }
        $update = prepareQuery($conn,
            "UPDATE public.queue_tickets SET status = 'serving'
             WHERE ticket_id = ? AND service_date = ? AND status = 'waiting'
             RETURNING *"
        );
        executeQuery($update, [$ticketId, $today]);
        $ticket = $update->fetch();
        $conn->commit();
        respond(200, ['success' => true, 'data' => restrictTicketForRole($ticket, 'cashier')]);
    }

    if (in_array($action, ['update_status', 'complete_ticket'], true)) {
        $ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $status = $action === 'complete_ticket'
            ? 'completed'
            : ($input['status'] ?? '');
        if (!$ticketId || !in_array($status, ['completed', 'cancelled'], true)) {
            respond(400, ['success' => false, 'message' => 'A valid ticket ID and terminal status are required']);
        }

        try {
            $conn->beginTransaction();
            $dailyLock = prepareQuery($conn, 'SELECT pg_advisory_xact_lock(hashtext(?))');
            executeQuery($dailyLock, ["daily-operations:$today"]);
            $queueColumnsQuery = prepareQuery($conn,
                "SELECT column_name
                 FROM information_schema.columns
                 WHERE table_schema = 'public'
                   AND table_name = 'queue_tickets'
                   AND column_name IN ('completed_at', 'service_date')"
            );
            executeQuery($queueColumnsQuery);
            $queueColumns = array_column($queueColumnsQuery->fetchAll(), 'column_name');
            $hasServiceDate = in_array('service_date', $queueColumns, true);
            $serviceDateCondition = $hasServiceDate ? ' AND service_date = ?' : '';
            $currentParameters = [$ticketId];
            if ($hasServiceDate) {
                $currentParameters[] = $today;
            }
            $current = prepareQuery($conn,
                "SELECT ticket_id,
                        to_jsonb(q)->>'ticket_number' AS ticket_number,
                        to_jsonb(q)->>'student_id' AS student_id,
                        to_jsonb(q)->>'student_name' AS student_name,
                        to_jsonb(q)->>'course' AS course,
                        to_jsonb(q)->>'year_level' AS year_level,
                        to_jsonb(q)->>'transaction_type' AS transaction_type,
                        to_jsonb(q)->>'payment_description' AS payment_description,
                        to_jsonb(q)->>'payment_period' AS payment_period
                 FROM public.queue_tickets AS q
                 WHERE ticket_id = ?$serviceDateCondition AND status = 'serving'
                 FOR UPDATE"
            );
            executeQuery($current, $currentParameters);
            $ticket = $current->fetch();
            if (!$ticket) {
                $conn->rollBack();
                respond(409, ['success' => false, 'message' => 'Ticket is no longer being served']);
            }

            $paymentActivityRecorded = null;
            $paymentActivityWarning = null;
            if ($status === 'completed' && ($ticket['transaction_type'] ?? '') === 'Tuition Payment') {
                $conn->exec('SAVEPOINT payment_activity_insert');
                try {
                    $paymentActivityRecord = prepareQuery($conn,
                        "INSERT INTO public.payment_histories
                            (transaction_ref, student_id, student_name, course_year,
                             payment_type, cashier_staff, status)
                         VALUES (?, ?, ?, ?, ?, ?, 'recorded')"
                    );
                    executeQuery($paymentActivityRecord, [
                        (string)($ticket['ticket_number'] ?? 'TXN') . '-' . (string)($ticket['ticket_id'] ?? '0'),
                        (string)($ticket['student_id'] ?? ''),
                        (string)($ticket['student_name'] ?? ''),
                        implode(' / ', array_filter([
                            trim((string) ($ticket['course'] ?? '')),
                            trim((string) ($ticket['year_level'] ?? '')),
                        ], static fn($value) => $value !== '')),
                        implode(' / ', array_filter([
                            trim((string) ($ticket['payment_description'] ?? '')),
                            trim((string) ($ticket['payment_period'] ?? '')),
                        ], static fn($value) => $value !== '')),
                        (string) ($_SESSION['identity']['staff_id'] ?? 'Admin Cashier'),
                    ]);
                    $paymentActivityRecorded = true;
                } catch (Throwable $error) {
                    $conn->exec('ROLLBACK TO SAVEPOINT payment_activity_insert');
                    error_log('Payment history insert caught exception, continuing ticket completion: ' . $error->getMessage());
                    $paymentActivityRecorded = false;
                    $paymentActivityWarning = 'Ticket completed successfully.';
                } finally {
                    $conn->exec('RELEASE SAVEPOINT payment_activity_insert');
                }
            }

            $completedAtUpdate = in_array('completed_at', $queueColumns, true)
                ? ', completed_at = CURRENT_TIMESTAMP'
                : '';
            $updateParameters = [$status, $ticketId];
            if ($hasServiceDate) {
                $updateParameters[] = $today;
            }
            $stmt = prepareQuery($conn,
                "UPDATE public.queue_tickets
                 SET status = ?$completedAtUpdate
                 WHERE ticket_id = ?$serviceDateCondition AND status = 'serving'
                 RETURNING ticket_id"
            );
            executeQuery($stmt, $updateParameters);
            if (!$stmt->fetchColumn()) {
                $conn->rollBack();
                respond(409, ['success' => false, 'message' => 'Ticket is no longer being served']);
            }
            $conn->commit();
            respond(200, [
                'success' => true,
                'payment_activity_recorded' => $paymentActivityRecorded,
                'message' => $paymentActivityWarning,
            ]);
        } catch (PDOException $error) {
            global $lastFailedQuery;
            if ($conn->inTransaction()) {
                try {
                    $conn->rollBack();
                } catch (Throwable $rollbackError) {
                    error_log(sprintf(
                        'Queue API status update rollback failure [%s]: %s',
                        $requestId,
                        $rollbackError->getMessage()
                    ));
                }
            }

            $sqlState = (string) ($error->errorInfo[0] ?? $error->getCode());
            $isSchemaError = in_array($sqlState, ['42P01', '42703'], true);
            error_log(sprintf(
                'Queue API ticket status update failure [%s, SQLSTATE %s, SQL %s]: %s',
                $requestId,
                $sqlState,
                $lastFailedQuery['sql'] ?? 'unavailable',
                $error->getMessage()
            ));

            respond(503, [
                'success' => false,
                'code' => $isSchemaError ? 'DATABASE_SCHEMA_MISMATCH' : 'DATABASE_OPERATION_FAILED',
                'request_id' => $requestId,
                'message' => $isSchemaError
                    ? 'Ticket status could not be updated because the database schema is not ready. Apply the required migrations and try again.'
                    : 'Ticket status could not be updated because of a database error. Please try again later.',
            ]);
        }
    }

    if ($action === 'recall_ticket') {
        $ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$ticketId) {
            respond(400, ['success' => false, 'message' => 'A valid ticket ID is required']);
        }

        $conn->beginTransaction();
        $dailyLock = prepareQuery($conn, 'SELECT pg_advisory_xact_lock(hashtext(?))');
        executeQuery($dailyLock, ["daily-operations:$today"]);
        $stmt = prepareQuery($conn,
            "UPDATE public.queue_tickets
             SET recall_count = recall_count + 1
             WHERE ticket_id = ? AND service_date = ? AND status = 'serving' AND recall_count < 3
             RETURNING ticket_id, ticket_number, recall_count, student_id"
        );
        executeQuery($stmt, [$ticketId, $today]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            $current = prepareQuery($conn,
                'SELECT status, recall_count FROM public.queue_tickets WHERE ticket_id = ? AND service_date = ?'
            );
            executeQuery($current, [$ticketId, $today]);
            $existing = $current->fetch();
            $conn->rollBack();
            if (!$existing) {
                respond(404, ['success' => false, 'message' => 'Ticket not found in today\'s queue']);
            }
            if ((int) $existing['recall_count'] >= 3) {
                respond(409, ['success' => false, 'message' => 'This ticket has reached the maximum of 3 recalls.']);
            }
            respond(409, ['success' => false, 'message' => 'Only a currently serving ticket can be recalled.']);
        }
        $conn->commit();

        $recallAttempt = (int) $ticket['recall_count'];
        $notificationMsg = "Paki-check ang queue: Ikaw ay tinatawag na sa Cashier (Attempt {$recallAttempt}/3). Bumalik na sa pila.";

        if ($recallAttempt >= 3) {
            $autoSkip = prepareQuery($conn,
                "UPDATE public.queue_tickets SET status = 'cancelled', completed_at = CURRENT_TIMESTAMP
                 WHERE ticket_id = ? AND service_date = ?"
            );
            executeQuery($autoSkip, [$ticket['ticket_id'], $today]);
        }

        $smsWebhookUrl = getenv('SMS_WEBHOOK_URL');
        if ($smsWebhookUrl !== false && $smsWebhookUrl !== '') {
            $studentPhone = '';
            $phoneQuery = prepareQuery($conn,
                "SELECT to_jsonb(profile)->>'mobile_number' AS mobile_number
                 FROM public.student_profiles AS profile
                 WHERE student_number = ? LIMIT 1"
            );
            executeQuery($phoneQuery, [$ticket['student_id']]);
            $phoneRow = $phoneQuery->fetch();
            if ($phoneRow && !empty($phoneRow['mobile_number'])) {
                $studentPhone = $phoneRow['mobile_number'];
            }
            if ($studentPhone !== '') {
                $smsPayload = json_encode([
                    'to'      => $studentPhone,
                    'message' => $notificationMsg,
                    'api_key' => getenv('SMS_API_KEY') ?: '',
                ]);
                $ch = curl_init($smsWebhookUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $smsPayload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                ]);
                curl_exec($ch);
                curl_close($ch);
            }
        }

        respond(200, ['success' => true, 'data' => $ticket, 'notification' => $notificationMsg]);
    }

    if ($action === 'reset_day') {
        $conn->beginTransaction();
        $lock = prepareQuery($conn, 'SELECT pg_advisory_xact_lock(hashtext(?))');
        executeQuery($lock, ["daily-operations:$today"]);
        $archive = prepareQuery($conn,
            "INSERT INTO public.daily_reports
                (report_date, total_tickets, completed_tickets, cancelled_tickets,
                 waiting_tickets, serving_tickets, regular_tickets, priority_tickets)
             SELECT ?, COUNT(*),
                    COUNT(*) FILTER (WHERE status = 'completed'),
                    COUNT(*) FILTER (WHERE status = 'cancelled'),
                    COUNT(*) FILTER (WHERE status = 'waiting'),
                    COUNT(*) FILTER (WHERE status = 'serving'),
                    COUNT(*) FILTER (WHERE lane = 'Regular'),
                    COUNT(*) FILTER (WHERE lane = 'Priority')
             FROM public.queue_tickets
             WHERE service_date = ?
             HAVING COUNT(*) > 0"
        );
        executeQuery($archive, [$today, $today]);
        $delete = prepareQuery($conn, 'DELETE FROM public.queue_tickets WHERE service_date = ?');
        executeQuery($delete, [$today]);
        $conn->commit();
        respond(200, ['success' => true, 'archived_tickets' => $delete->rowCount()]);
    }

    respond(400, ['success' => false, 'message' => 'Unknown action']);
} catch (PDOException $error) {
    global $lastFailedQuery;
    $sqlState = (string) ($error->errorInfo[0] ?? $error->getCode());
    if (
        ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' &&
        in_array($_GET['action'] ?? '', ['get_cashier_payment_activity_records', 'get_c_status-all'], true)
    ) {
        error_log(sprintf(
            'Queue API payment activity query failure [%s, SQLSTATE %s, SQL %s]: %s',
            $requestId,
            $sqlState,
            $lastFailedQuery['sql'] ?? 'unavailable',
            $error->getMessage()
        ));
        $message = sprintf('SQLSTATE %s: %s', $sqlState, $error->getMessage());
        if (!empty($lastFailedQuery['sql'])) {
            $message .= ' | SQL: ' . $lastFailedQuery['sql'];
        }
        respond(500, [
            'success' => false,
            'status' => 'error',
            'request_id' => $requestId,
            'message' => $message,
        ]);
    }

    handleApiException($error);
} catch (Throwable $error) {
    handleApiException($error);
}
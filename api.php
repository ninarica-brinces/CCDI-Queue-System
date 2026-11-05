<?php
ob_start();
ini_set('display_errors', '0');
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
        foreach (['payment_method', 'payment_period', 'payment_percentage', 'discount'] as $field) {
            $ticket[$field] = null;
        }
    }

    if ($role === 'student' && (($ticket['student_id'] ?? '') !== $studentId || !$hasActiveQueue)) {
        foreach (['student_name', 'student_id', 'course', 'address', 'year_level', 'payment_method', 'payment_period', 'payment_percentage', 'discount', 'notes'] as $field) {
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
        $input = json_decode(file_get_contents('php://input'), true);
        if (!is_array($input)) {
            respond(400, ['success' => false, 'message' => 'Request body must be valid JSON']);
        }
        $action = $input['action'] ?? '';
    } elseif ($method !== 'GET') {
        respond(405, ['success' => false, 'message' => 'Method not allowed']);
    }

    $routes = [
        'GET' => ['auth_status', 'get_queue_state', 'get_state', 'get_payment_details'],
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
        require_once __DIR__ . '/db.php';
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

        $stmt = $conn->prepare(
            'SELECT * FROM public.system_users WHERE username = ? AND is_active = TRUE'
        );
        $stmt->execute([$username]);
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

        $student = $conn->prepare(
            "SELECT student_number AS student_id,
                    btrim(first_name) || ' ' || btrim(last_name) AS full_name,
                    current_balance, NULL AS course, NULL AS address, NULL AS year_level
             FROM public.student_profiles
             WHERE lower(btrim(student_number)) = lower(btrim(?))
               AND regexp_replace(
                     lower(btrim(first_name) || ' ' || btrim(last_name)),
                     '[[:space:]]+', ' ', 'g'
                   ) = regexp_replace(lower(btrim(?)), '[[:space:]]+', ' ', 'g')
             LIMIT 1"
        );
        $student->execute([$studentId, $studentName]);
        $profile = $student->fetch();
        if (!$profile) {
            respond(403, [
                'success' => false,
                'code' => 'STUDENT_RECORD_MISMATCH',
                'fields' => ['studentId', 'studentName'],
                'message' => 'The Student ID and full name do not match an active CCDI student record. Check the ID and spelling, and use the full name on the record.',
            ]);
        }

        issueSession('student', ['student_id' => $profile['student_id']]);
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
            $student = $conn->prepare(
                "SELECT student_number AS student_id,
                        btrim(first_name) || ' ' || btrim(last_name) AS full_name,
                        current_balance, NULL AS course, NULL AS address, NULL AS year_level
                 FROM public.student_profiles WHERE student_number = ? LIMIT 1"
            );
            $student->execute([$identity['student_id'] ?? '']);
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
            $actCheck = $conn->prepare(
                "SELECT 1 FROM public.queue_tickets
                 WHERE service_date = ? AND student_id = ?
                   AND status IN ('waiting', 'serving', 'completed')
                 LIMIT 1"
            );
            $actCheck->execute([$today, $studentId]);
            $hasActiveQueue = (bool) $actCheck->fetchColumn();
        }
        $stmt = $conn->prepare(
            'SELECT * FROM public.queue_tickets WHERE service_date = ? ORDER BY created_at ASC, ticket_id ASC'
        );
        $stmt->execute([$today]);
        $tickets = array_map(
            static fn($ticket) => restrictTicketForRole($ticket, $role, $studentId, $hasActiveQueue),
            $stmt->fetchAll()
        );
        respond(200, ['success' => true, 'data' => $tickets, 'has_active_queue' => $hasActiveQueue]);
    }


    // --- Spec 2: Payment details access gate ---
    // Students can only retrieve payment history if they have a queue ticket for today.
    if ($method === 'GET' && $action === 'get_payment_details') {
        $role = $_SESSION['role'] ?? '';
        if (!in_array($role, ['student', 'cashier'], true)) {
            respond(401, ['success' => false, 'message' => 'Authentication required']);
        }
        requireRole($role, false);
        $studentId = $role === 'student'
            ? (string) ($_SESSION['identity']['student_id'] ?? '')
            : trim((string) ($_GET['student_id'] ?? ''));
        if ($studentId === '') {
            respond(400, ['success' => false, 'message' => 'Student ID is required']);
        }
        $queueCheck = $conn->prepare(
            "SELECT 1 FROM public.queue_tickets
             WHERE service_date = ? AND student_id = ?
               AND status IN ('waiting', 'serving', 'completed')
             LIMIT 1"
        );
        $queueCheck->execute([$today, $studentId]);
        if (!$queueCheck->fetchColumn()) {
            respond(403, [
                'success' => false,
                'code'    => 'QUEUE_ACCESS_REQUIRED',
                'message' => 'You must join the queue first to view payment details.',
            ]);
        }
        $histories = $conn->prepare(
            'SELECT reference_number, amount_paid, created_at
             FROM public.payment_activity_records WHERE student_id = ?
             ORDER BY created_at DESC LIMIT 50'
        );
        $histories->execute([$studentId]);
        $historyRows = $histories->fetchAll();

        $profileStmt = $conn->prepare(
            'SELECT current_balance FROM public.student_profiles WHERE student_number = ? LIMIT 1'
        );
        $profileStmt->execute([$studentId]);
        $profileRow = $profileStmt->fetch();

        respond(200, [
            'success' => true,
            'student_id' => $studentId,
            'current_balance' => (float) ($profileRow['current_balance'] ?? 0),
            'data' => $historyRows
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
        $paymentPercentage = requestString($input, 'paymentPercentage', 32);
        $discount = requestString($input, 'discount', 120);
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
        $dailyLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $dailyLock->execute(["daily-operations:$today"]);
        $student = $conn->prepare(
            "SELECT student_number AS student_id,
                    btrim(first_name) || ' ' || btrim(last_name) AS full_name,
                    current_balance, NULL AS course, NULL AS address, NULL AS year_level
             FROM public.student_profiles
             WHERE lower(btrim(student_number)) = lower(btrim(?))
               AND regexp_replace(
                     lower(btrim(first_name) || ' ' || btrim(last_name)),
                     '[[:space:]]+', ' ', 'g'
                   ) = regexp_replace(lower(btrim(?)), '[[:space:]]+', ' ', 'g')
             LIMIT 1"
        );
        $student->execute([$studentId, $studentName]);
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
            $paymentPercentage = '';
            $discount = '';
            $payment = 'N/A (Document Only)';
        } elseif (!in_array($payment, [
            'Full Payment',
            'Partial Payment (Custom Amount)',
            'Partial Payment (50%)',
            'Partial Payment (25%)',
            'Partial Payment',
            'Promissory Note',
            'Promissory',
        ], true)) {
            $conn->rollBack();
            respond(400, ['success' => false, 'message' => 'Select a supported tuition payment option']);
        }
        $customAmount = isset($input['customAmount']) && is_numeric($input['customAmount'])
            ? (float) $input['customAmount']
            : null;
        if ($service === 'Tuition Payment') {
            if ($customAmount !== null && $customAmount > 0) {
                $balStmt = $conn->prepare('SELECT current_balance FROM public.student_profiles WHERE student_number = ? LIMIT 1');
                $balStmt->execute([$profile['student_id']]);
                $studentBalance = (float) ($balStmt->fetchColumn() ?: 0);
                if ($studentBalance > 0) {
                    $computedPct = round(min(100.0, ($customAmount / $studentBalance) * 100), 2);
                    $paymentPercentage = (string) $computedPct;
                } else {
                    $paymentPercentage = '100';
                }
                if ($discount === '') {
                    $discount = 'PHP ' . number_format($customAmount, 2);
                }
            } elseif ($paymentPercentage !== '') {
                if (preg_match('/^(\d+(?:\.\d+)?)%?$/D', $paymentPercentage, $matches)) {
                    $val = (float) $matches[1];
                    if ($val > 100) {
                        $balStmt = $conn->prepare('SELECT current_balance FROM public.student_profiles WHERE student_number = ? LIMIT 1');
                        $balStmt->execute([$profile['student_id']]);
                        $studentBalance = (float) ($balStmt->fetchColumn() ?: 0);
                        if ($studentBalance > 0) {
                            $paymentPercentage = (string) round(min(100.0, ($val / $studentBalance) * 100), 2);
                        } else {
                            $paymentPercentage = '100';
                        }
                        if ($discount === '') {
                            $discount = 'PHP ' . number_format($val, 2);
                        }
                    }
                } else {
                    $conn->rollBack();
                    respond(400, ['success' => false, 'message' => 'Payment percentage must be a number from 0 to 100 or a valid amount.']);
                }
            } elseif ($payment === 'Partial Payment') {
                $conn->rollBack();
                respond(400, ['success' => false, 'message' => 'A payment amount or percentage is required for partial payment.']);
            }
        }

        $studentLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $studentLock->execute(["student:$today:{$profile['student_id']}"]);
        $active = $conn->prepare(
            "SELECT 1 FROM public.queue_tickets
             WHERE service_date = ? AND student_id = ? AND status IN ('waiting', 'serving')
             LIMIT 1"
        );
        $active->execute([$today, $profile['student_id']]);
        if ($active->fetchColumn()) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'This student already has an active ticket today']);
        }

        $dailyCount = $conn->prepare(
            "SELECT
                (SELECT COUNT(*) FROM public.queue_tickets WHERE service_date = ?) +
                (SELECT COALESCE(SUM(total_tickets), 0) FROM public.daily_reports WHERE report_date = ?)"
        );
        $dailyCount->execute([$today, $today]);
        if ((int) $dailyCount->fetchColumn() >= 100) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'Daily ticket limit of 100 students has been reached.']);
        }

        $count = $conn->prepare(
            "SELECT
                (SELECT COUNT(*) FROM public.queue_tickets WHERE lane = ? AND service_date = ?) +
                (SELECT COALESCE(SUM(
                    CASE WHEN ? = 'Priority' THEN priority_tickets ELSE regular_tickets END
                 ), 0)
                 FROM public.daily_reports WHERE report_date = ?)"
        );
        $count->execute([$lane, $today, $lane, $today]);
        $prefix = $lane === 'Priority' ? 'P' : 'A';
        $ticketNumber = $prefix . '-' . str_pad((string) ((int) $count->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);
        $insert = $conn->prepare(
            "INSERT INTO public.queue_tickets
                (ticket_number, lane, student_name, student_id, course, address, year_level,
                 transaction_type, payment_method, payment_period, payment_percentage, discount, notes, status, service_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'waiting', ?)
             RETURNING ticket_id"
        );
        $insert->execute([
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
            $paymentPercentage,
            $discount,
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
        $dailyLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $dailyLock->execute(["daily-operations:$today"]);
        $lock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $lock->execute(["serving:$today:$lane"]);
        $serving = $conn->prepare(
            "SELECT 1 FROM public.queue_tickets
             WHERE service_date = ? AND lane = ? AND status = 'serving' LIMIT 1"
        );
        $serving->execute([$today, $lane]);
        if ($serving->fetchColumn()) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'A ticket is already being served in this lane']);
        }
        $next = $conn->prepare(
            "SELECT ticket_id FROM public.queue_tickets
             WHERE service_date = ? AND lane = ? AND status = 'waiting'
             ORDER BY created_at ASC, ticket_id ASC LIMIT 1"
        );
        $next->execute([$today, $lane]);
        $ticketId = $next->fetchColumn();
        if ($ticketId === false) {
            $conn->rollBack();
            respond(404, ['success' => false, 'message' => 'No tickets are waiting in this lane']);
        }
        $update = $conn->prepare(
            "UPDATE public.queue_tickets SET status = 'serving'
             WHERE ticket_id = ? AND service_date = ? AND status = 'waiting'
             RETURNING *"
        );
        $update->execute([$ticketId, $today]);
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

        $conn->beginTransaction();
        $dailyLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $dailyLock->execute(["daily-operations:$today"]);
        $current = $conn->prepare(
            "SELECT ticket_id, ticket_number, student_id, transaction_type, payment_method, payment_percentage
             FROM public.queue_tickets
             WHERE ticket_id = ? AND service_date = ? AND status = 'serving'
             FOR UPDATE"
        );
        $current->execute([$ticketId, $today]);
        $ticket = $current->fetch();
        if (!$ticket) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'Ticket is no longer being served']);
        }

        if ($status === 'completed' && $ticket['transaction_type'] === 'Tuition Payment') {
            $profile = $conn->prepare(
                'SELECT current_balance FROM public.student_profiles WHERE student_number = ? LIMIT 1'
            );
            $profile->execute([$ticket['student_id']]);
            $studentProfile = $profile->fetch();
            if (!$studentProfile) {
                $conn->rollBack();
                respond(409, ['success' => false, 'message' => 'Student profile is no longer available; payment was not recorded.']);
            }

            $paymentMethod = (string) $ticket['payment_method'];
            $customPercentage = trim((string) ($ticket['payment_percentage'] ?? ''));
            if ($customPercentage !== '') {
                if (!preg_match('/^(\d+(?:\.\d+)?)%?$/D', $customPercentage, $matches)) {
                    $conn->rollBack();
                    respond(409, ['success' => false, 'message' => 'The ticket has an invalid payment percentage; payment was not recorded.']);
                }
                $percentage = (float) $matches[1];
            } else {
                $percentage = match ($paymentMethod) {
                    'Full Payment' => 100.0,
                    'Partial Payment (50%)' => 50.0,
                    'Partial Payment (25%)' => 25.0,
                    'Promissory Note', 'Promissory' => 0.0,
                    default => null,
                };
            }

            if ($percentage === null || $percentage < 0 || $percentage > 100) {
                $conn->rollBack();
                respond(409, ['success' => false, 'message' => 'The ticket has no valid payment percentage; payment was not recorded.']);
            }

            if (!is_numeric($studentProfile['current_balance']) || (float) $studentProfile['current_balance'] < 0) {
                $conn->rollBack();
                respond(409, ['success' => false, 'message' => 'The student profile has an invalid balance; payment was not recorded.']);
            }

            $amountPaid = round((float) $studentProfile['current_balance'] * $percentage / 100, 2);
            $history = $conn->prepare(
                'INSERT INTO public.payment_activity_records (reference_number, student_id, amount_paid)
                 VALUES (?, ?, ?)'
            );
            $history->execute([$ticket['ticket_number'], $ticket['student_id'], $amountPaid]);
        }

        $stmt = $conn->prepare(
            "UPDATE public.queue_tickets
             SET status = ?, completed_at = CURRENT_TIMESTAMP
             WHERE ticket_id = ? AND service_date = ? AND status = 'serving'
             RETURNING ticket_id"
        );
        $stmt->execute([$status, $ticketId, $today]);
        if (!$stmt->fetchColumn()) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'Ticket is no longer being served']);
        }
        $conn->commit();
        respond(200, ['success' => true]);
    }

    if ($action === 'recall_ticket') {
        $ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$ticketId) {
            respond(400, ['success' => false, 'message' => 'A valid ticket ID is required']);
        }

        $conn->beginTransaction();
        $dailyLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $dailyLock->execute(["daily-operations:$today"]);
        $stmt = $conn->prepare(
            "UPDATE public.queue_tickets
             SET recall_count = recall_count + 1
             WHERE ticket_id = ? AND service_date = ? AND status = 'serving' AND recall_count < 3
             RETURNING ticket_id, ticket_number, recall_count, student_id"
        );
        $stmt->execute([$ticketId, $today]);
        $ticket = $stmt->fetch();
        if (!$ticket) {
            $current = $conn->prepare(
                'SELECT status, recall_count FROM public.queue_tickets WHERE ticket_id = ? AND service_date = ?'
            );
            $current->execute([$ticketId, $today]);
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

        // --- Spec 7: Recall notification trigger ---
        // Build the Tagalog recall notification message.
        $recallAttempt = (int) $ticket['recall_count'];
        $notificationMsg = "Paki-check ang queue: Ikaw ay tinatawag na sa Cashier (Attempt {$recallAttempt}/3). Bumalik na sa pila.";

        // If recall_count has reached 3, auto-update the ticket to cancelled (Missed/Skipped).
        if ($recallAttempt >= 3) {
            $autoSkip = $conn->prepare(
                "UPDATE public.queue_tickets SET status = 'cancelled', completed_at = CURRENT_TIMESTAMP
                 WHERE ticket_id = ? AND service_date = ?"
            );
            $autoSkip->execute([$ticket['ticket_id'], $today]);
        }

        // SMS webhook integration point — configure SMS_WEBHOOK_URL and SMS_API_KEY env vars.
        $smsWebhookUrl = getenv('SMS_WEBHOOK_URL');
        if ($smsWebhookUrl !== false && $smsWebhookUrl !== '') {
            $studentPhone = '';
            $phoneQuery = $conn->prepare(
                'SELECT mobile_number FROM public.student_profiles WHERE student_number = ? LIMIT 1'
            );
            $phoneQuery->execute([$ticket['student_id']]);
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
        $lock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $lock->execute(["daily-operations:$today"]);
        $archive = $conn->prepare(
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
        $archive->execute([$today, $today]);
        $delete = $conn->prepare('DELETE FROM public.queue_tickets WHERE service_date = ?');
        $delete->execute([$today]);
        $conn->commit();
        respond(200, ['success' => true, 'archived_tickets' => $delete->rowCount()]);
    }

    respond(400, ['success' => false, 'message' => 'Unknown action']);
} catch (Throwable $error) {
    if (($conn ?? null) instanceof PDO && $conn->inTransaction()) {
        try {
            $conn->rollBack();
        } catch (Throwable $rollbackError) {
            error_log(sprintf('Queue API rollback failure [%s]: %s', $requestId ?? 'unavailable', $rollbackError->getMessage()));
        }
    }
    error_log(sprintf(
        'Queue API error [%s] (%s, SQLSTATE %s): %s in %s:%d',
        $requestId ?? 'unavailable',
        get_class($error),
        (string) $error->getCode(),
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
    ));
    respond(500, [
        'success' => false,
        'message' => 'The queue service encountered a server or database error. Please try again later.',
    ]);
}

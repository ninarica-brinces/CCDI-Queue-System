<?php
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

function respond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
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

function restrictTicketForRole($ticket, $role, $studentId = '')
{
    if (($ticket['transaction_type'] ?? '') !== 'Tuition Payment') {
        foreach (['payment_method', 'payment_period', 'payment_percentage', 'discount'] as $field) {
            $ticket[$field] = null;
        }
    }

    if ($role === 'student' && ($ticket['student_id'] ?? '') !== $studentId) {
        foreach (['student_name', 'student_id', 'course', 'address', 'year_level', 'payment_method', 'payment_period', 'payment_percentage', 'discount', 'notes'] as $field) {
            $ticket[$field] = null;
        }
    }

    return $ticket;
}

verifyRequestOrigin();

$requestTimezone = getenv('APP_TIMEZONE');
date_default_timezone_set($requestTimezone !== false && $requestTimezone !== '' ? $requestTimezone : 'Asia/Manila');
$today = date('Y-m-d');
$conn = null;

try {
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

    require_once __DIR__ . '/db.php';

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
            respond(403, ['success' => false, 'message' => 'Authorization failed: Student ID and full name must match a CCDI student record.']);
        }

        $student = $conn->prepare(
            "SELECT student_id, btrim(first_name) || ' ' || btrim(last_name) AS full_name,
                    course, address, year_level
             FROM public.ccdi_students
             WHERE lower(btrim(student_id)) = lower(btrim(?))
               AND lower(btrim(first_name) || ' ' || btrim(last_name)) = lower(btrim(?))
             LIMIT 1"
        );
        $student->execute([$studentId, $studentName]);
        $profile = $student->fetch();
        if (!$profile) {
            respond(403, ['success' => false, 'message' => 'Authorization failed: Student ID and full name must exactly match a CCDI student record.']);
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
                "SELECT student_id, btrim(first_name) || ' ' || btrim(last_name) AS full_name,
                        course, address, year_level
                 FROM public.ccdi_students WHERE student_id = ? LIMIT 1"
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
        $stmt = $conn->prepare(
            'SELECT * FROM public.queue_tickets WHERE service_date = ? ORDER BY created_at ASC, ticket_id ASC'
        );
        $stmt->execute([$today]);
        $tickets = array_map(
            static fn($ticket) => restrictTicketForRole($ticket, $role, $studentId),
            $stmt->fetchAll()
        );
        respond(200, ['success' => true, 'data' => $tickets]);
    }

    if ($method === 'GET') {
        respond(400, ['success' => false, 'message' => 'Unknown action']);
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
            respond(403, ['success' => false, 'message' => 'Authorization failed: Student ID and full name are required.']);
        }
        if ($service === '' || $payment === '') {
            respond(400, ['success' => false, 'message' => 'Service and payment are required']);
        }

        $conn->beginTransaction();
        $dailyLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $dailyLock->execute(["daily-operations:$today"]);
        $student = $conn->prepare(
            "SELECT student_id, btrim(first_name) || ' ' || btrim(last_name) AS full_name,
                    course, address, year_level
             FROM public.ccdi_students
             WHERE lower(btrim(student_id)) = lower(btrim(?))
               AND lower(btrim(first_name) || ' ' || btrim(last_name)) = lower(btrim(?))
             LIMIT 1"
        );
        $student->execute([$studentId, $studentName]);
        $profile = $student->fetch();
        if (!$profile) {
            $conn->rollBack();
            respond(403, ['success' => false, 'message' => 'Authorization failed: Student ID and full name must exactly match a CCDI student record.']);
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
            'Partial Payment (50%)',
            'Partial Payment (25%)',
            'Partial Payment',
            'Promissory Note',
            'Promissory',
        ], true)) {
            $conn->rollBack();
            respond(400, ['success' => false, 'message' => 'Select a supported tuition payment option']);
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
            respond(409, ['success' => false, 'message' => 'The daily limit of 100 student tickets has been reached.']);
        }

        $count = $conn->prepare(
            "SELECT
                (SELECT COUNT(*) FROM public.queue_tickets WHERE lane = ? AND service_date = ?) +
                (SELECT COALESCE(SUM(COALESCE((lane_breakdown ->> ?)::integer, 0)), 0)
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

    if ($action === 'update_status') {
        $ticketId = filter_var($input['ticket_id'] ?? null, FILTER_VALIDATE_INT);
        $status = $input['status'] ?? '';
        if (!$ticketId || !in_array($status, ['completed', 'cancelled'], true)) {
            respond(400, ['success' => false, 'message' => 'A valid ticket ID and terminal status are required']);
        }

        $conn->beginTransaction();
        $dailyLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $dailyLock->execute(["daily-operations:$today"]);
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
             RETURNING ticket_id, ticket_number, recall_count"
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
        respond(200, ['success' => true, 'data' => $ticket]);
    }

    if ($action === 'reset_day') {
        $conn->beginTransaction();
        $lock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $lock->execute(["daily-operations:$today"]);
        $archive = $conn->prepare(
            "INSERT INTO public.daily_reports
                (report_date, total_tickets, completed_tickets, cancelled_tickets,
                 waiting_tickets, serving_tickets, regular_tickets, priority_tickets,
                 service_breakdown, lane_breakdown, status_breakdown)
             SELECT ?, COUNT(*),
                    COUNT(*) FILTER (WHERE status = 'completed'),
                    COUNT(*) FILTER (WHERE status = 'cancelled'),
                    COUNT(*) FILTER (WHERE status = 'waiting'),
                    COUNT(*) FILTER (WHERE status = 'serving'),
                    COUNT(*) FILTER (WHERE lane = 'Regular'),
                    COUNT(*) FILTER (WHERE lane = 'Priority'),
                    COALESCE((
                        SELECT jsonb_object_agg(service_name, ticket_count)
                        FROM (
                            SELECT transaction_type AS service_name, COUNT(*) AS ticket_count
                            FROM public.queue_tickets WHERE service_date = ? GROUP BY transaction_type
                        ) AS services
                    ), '{}'::jsonb),
                    COALESCE((
                        SELECT jsonb_object_agg(lane_name, ticket_count)
                        FROM (
                            SELECT lane AS lane_name, COUNT(*) AS ticket_count
                            FROM public.queue_tickets WHERE service_date = ? GROUP BY lane
                        ) AS lanes
                    ), '{}'::jsonb),
                    COALESCE((
                        SELECT jsonb_object_agg(status_name, ticket_count)
                        FROM (
                            SELECT status AS status_name, COUNT(*) AS ticket_count
                            FROM public.queue_tickets WHERE service_date = ? GROUP BY status
                        ) AS statuses
                    ), '{}'::jsonb)
             FROM public.queue_tickets WHERE service_date = ?"
        );
        $archive->execute([$today, $today, $today, $today, $today]);
        $delete = $conn->prepare('DELETE FROM public.queue_tickets WHERE service_date = ?');
        $delete->execute([$today]);
        $conn->commit();
        respond(200, ['success' => true, 'archived_tickets' => $delete->rowCount()]);
    }

    respond(400, ['success' => false, 'message' => 'Unknown action']);
} catch (Throwable $error) {
    if ($conn instanceof PDO && $conn->inTransaction()) {
        try {
            $conn->rollBack();
        } catch (Throwable $rollbackError) {
            error_log(sprintf('Queue API rollback failure [%s]: %s', $requestId, $rollbackError->getMessage()));
        }
    }
    error_log(sprintf(
        'Queue API error [%s] (%s, SQLSTATE %s): %s in %s:%d',
        $requestId,
        get_class($error),
        (string) $error->getCode(),
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
    ));
    respond(500, [
        'success' => false,
        'message' => "The queue service encountered a server or database error. Reference: $requestId",
        'request_id' => $requestId,
    ]);
}

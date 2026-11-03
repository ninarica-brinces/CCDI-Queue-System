<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

function respond($status, $payload)
{
    http_response_code($status);
    echo json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

function requestString($input, $key)
{
    $value = $input[$key] ?? '';
    if (!is_string($value) && !is_numeric($value)) {
        respond(400, ['success' => false, 'message' => "Invalid value for $key"]);
    }
    return trim((string) $value);
}

function restrictPaymentDetails($ticket)
{
    if (
        ($ticket['transaction_type'] ?? '') !== 'Tuition Payment' &&
        !in_array($ticket['status'] ?? '', ['waiting', 'serving'], true)
    ) {
        foreach (['payment_method', 'payment_period', 'payment_percentage', 'discount'] as $field) {
            $ticket[$field] = null;
        }
    }

    return $ticket;
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit();
}

$timezone = getenv('APP_TIMEZONE');
$requestId = 'unassigned';

try {
    $requestId = bin2hex(random_bytes(8));
    date_default_timezone_set($timezone !== false && $timezone !== '' ? $timezone : 'Asia/Manila');
    require_once __DIR__ . '/db.php';

    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? '';
    $today = date('Y-m-d');

    if ($method === 'GET' && $action === 'get_state') {
        $stmt = $conn->prepare(
            'SELECT * FROM public.queue_tickets WHERE service_date = ? ORDER BY created_at ASC, ticket_id ASC'
        );
        $stmt->execute([$today]);
        $tickets = array_map('restrictPaymentDetails', $stmt->fetchAll());
        respond(200, ['success' => true, 'data' => $tickets]);
    }

    if ($method !== 'POST') {
        respond(405, ['success' => false, 'message' => 'Method not allowed']);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        respond(400, ['success' => false, 'message' => 'Request body must be valid JSON']);
    }

    $actionType = $input['action'] ?? '';

    if ($actionType === 'create_ticket') {
        $lane = requestString($input, 'lane');
        $studentName = requestString($input, 'studentName');
        $studentId = requestString($input, 'studentId');
        $course = requestString($input, 'course');
        $address = requestString($input, 'address');
        $yearLevel = requestString($input, 'yearLevel');
        $service = requestString($input, 'service');
        $payment = requestString($input, 'payment');
        $paymentPeriod = requestString($input, 'paymentPeriod');
        $paymentPercentage = requestString($input, 'paymentPercentage');
        $discount = requestString($input, 'discount');
        $notes = requestString($input, 'notes');

        if (!in_array($lane, ['Regular', 'Priority'], true)) {
            respond(400, ['success' => false, 'message' => 'Lane must be Regular or Priority']);
        }
        if ($studentName === '' || $studentId === '' || strlen($studentName) > 120 || strlen($studentId) > 64) {
            respond(403, ['success' => false, 'message' => 'Authorization failed: a valid Student Name and Student ID are required and must exactly match an authorized CCDI student record.']);
        }
        $conn->beginTransaction();
        $dailyLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $dailyLock->execute(["daily-operations:$today"]);

        $student = $conn->prepare(
            "SELECT student_id, first_name || ' ' || last_name AS full_name, course, address, year_level
             FROM public.ccdi_students
             WHERE student_id = ? AND first_name || ' ' || last_name = ?
             LIMIT 1"
        );
        $student->execute([$studentId, $studentName]);
        $authorizedStudent = $student->fetch();
        if (!$authorizedStudent) {
            $conn->rollBack();
            respond(403, ['success' => false, 'message' => 'Authorization failed: Student Name and Student ID must exactly match an authorized CCDI student record.']);
        }

        $studentName = $authorizedStudent['full_name'];
        $studentId = $authorizedStudent['student_id'];
        $course = $authorizedStudent['course'];
        $address = $authorizedStudent['address'];
        $yearLevel = (string) $authorizedStudent['year_level'];

        if ($service === '' || $payment === '') {
            $conn->rollBack();
            respond(400, ['success' => false, 'message' => 'Service and payment are required']);
        }
        if (
            strlen($service) > 120 ||
            strlen($payment) > 240 ||
            strlen($paymentPeriod) > 64 ||
            strlen($paymentPercentage) > 32 ||
            strlen($discount) > 120 ||
            strlen($notes) > 240
        ) {
            $conn->rollBack();
            respond(400, ['success' => false, 'message' => 'One or more ticket fields exceed the allowed length']);
        }

        $lock = $conn->prepare("SELECT pg_advisory_xact_lock(hashtext(?))");
        $lock->execute(["ticket-number:$today:$lane"]);

        $studentLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $studentLock->execute(["student:$today:$studentId"]);
        $active = $conn->prepare(
            "SELECT 1 FROM public.queue_tickets
             WHERE service_date = ? AND student_id = ? AND status IN ('waiting', 'serving')
             LIMIT 1"
        );
        $active->execute([$today, $studentId]);
        if ($active->fetchColumn()) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'This student already has an active ticket today']);
        }

        $dailyCount = $conn->prepare(
            'SELECT COUNT(*) FROM public.queue_tickets WHERE service_date = ?'
        );
        $dailyCount->execute([$today]);
        if ((int) $dailyCount->fetchColumn() >= 100) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'The daily limit of 100 student tickets has been reached.']);
        }

        $count = $conn->prepare(
            'SELECT COUNT(*) FROM public.queue_tickets WHERE lane = ? AND service_date = ?'
        );
        $count->execute([$lane, $today]);
        $prefix = $lane === 'Priority' ? 'P' : 'A';
        $ticketNumber = $prefix . '-' . str_pad((string) ((int) $count->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);

        $insert = $conn->prepare(
            "INSERT INTO public.queue_tickets
                (ticket_number, lane, student_name, student_id, course, address, year_level,
                 transaction_type, payment_method, payment_period, payment_percentage, discount, notes, status, service_date)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'waiting', ?)
             RETURNING ticket_id"
        );
        $insert->bindValue(1, $ticketNumber);
        $insert->bindValue(2, $lane);
        $insert->bindValue(3, $studentName);
        $insert->bindValue(
            4,
            $studentId !== '' ? $studentId : null,
            $studentId !== '' ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $insert->bindValue(5, $course);
        $insert->bindValue(6, $address);
        $insert->bindValue(7, $yearLevel);
        $insert->bindValue(8, $service);
        $insert->bindValue(9, $payment);
        $insert->bindValue(10, $paymentPeriod);
        $insert->bindValue(11, $paymentPercentage);
        $insert->bindValue(12, $discount);
        $insert->bindValue(13, $notes);
        $insert->bindValue(14, $today);
        $insert->execute();
        $ticketId = (int) $insert->fetchColumn();
        $conn->commit();

        respond(201, ['success' => true, 'ticket_id' => $ticketId, 'ticket_number' => $ticketNumber]);
    }

    if ($actionType === 'call_next') {
        $lane = requestString($input, 'lane');
        if (!in_array($lane, ['Regular', 'Priority'], true)) {
            respond(400, ['success' => false, 'message' => 'Lane must be Regular or Priority']);
        }

        $conn->beginTransaction();
        $dailyLock = $conn->prepare('SELECT pg_advisory_xact_lock(hashtext(?))');
        $dailyLock->execute(["daily-operations:$today"]);
        $lock = $conn->prepare("SELECT pg_advisory_xact_lock(hashtext(?))");
        $lock->execute(["serving:$today:$lane"]);

        $serving = $conn->prepare(
            "SELECT ticket_id FROM public.queue_tickets
             WHERE service_date = ? AND lane = ? AND status = 'serving'
             LIMIT 1"
        );
        $serving->execute([$today, $lane]);
        if ($serving->fetchColumn()) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'A ticket is already being served in this lane']);
        }

        $next = $conn->prepare(
            "SELECT ticket_id FROM public.queue_tickets
             WHERE service_date = ? AND lane = ? AND status = 'waiting'
             ORDER BY created_at ASC, ticket_id ASC
             LIMIT 1"
        );
        $next->execute([$today, $lane]);
        $ticketId = $next->fetchColumn();
        if ($ticketId === false) {
            $conn->rollBack();
            respond(404, ['success' => false, 'message' => 'No tickets are waiting in this lane']);
        }

        $update = $conn->prepare(
            "UPDATE public.queue_tickets SET status = 'serving'
             WHERE ticket_id = ? AND status = 'waiting'
             RETURNING ticket_id, ticket_number, lane, student_name, student_id, course, address, year_level, transaction_type, payment_method, payment_period, payment_percentage, discount, notes, status, service_date, created_at, completed_at"
        );
        $update->execute([$ticketId]);
        $ticket = $update->fetch();
        $conn->commit();
        respond(200, ['success' => true, 'data' => $ticket]);
    }

    if ($actionType === 'update_status') {
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

    if ($actionType === 'recall_ticket') {
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
            $existingTicket = $current->fetch();
            $conn->rollBack();
            if (!$existingTicket) {
                respond(404, ['success' => false, 'message' => 'Ticket not found in today\'s queue']);
            }
            if ((int) $existingTicket['recall_count'] >= 3) {
                respond(409, ['success' => false, 'message' => 'This ticket has reached the maximum of 3 recalls.']);
            }
            respond(409, ['success' => false, 'message' => 'Only a currently serving ticket can be recalled.']);
        }

        $conn->commit();
        respond(200, ['success' => true, 'data' => $ticket]);
    }

    if ($actionType === 'reset_day') {
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
                            FROM public.queue_tickets
                            WHERE service_date = ?
                            GROUP BY transaction_type
                        ) AS services
                    ), '{}'::jsonb),
                    COALESCE((
                        SELECT jsonb_object_agg(lane_name, ticket_count)
                        FROM (
                            SELECT lane AS lane_name, COUNT(*) AS ticket_count
                            FROM public.queue_tickets
                            WHERE service_date = ?
                            GROUP BY lane
                        ) AS lanes
                    ), '{}'::jsonb),
                    COALESCE((
                        SELECT jsonb_object_agg(status_name, ticket_count)
                        FROM (
                            SELECT status AS status_name, COUNT(*) AS ticket_count
                            FROM public.queue_tickets
                            WHERE service_date = ?
                            GROUP BY status
                        ) AS statuses
                    ), '{}'::jsonb)
             FROM public.queue_tickets
             WHERE service_date = ?"
        );
        $archive->execute([$today, $today, $today, $today, $today]);

        $delete = $conn->prepare('DELETE FROM public.queue_tickets WHERE service_date = ?');
        $delete->execute([$today]);
        $conn->commit();
        respond(200, ['success' => true, 'archived_tickets' => $delete->rowCount()]);
    }

    respond(400, ['success' => false, 'message' => 'Unknown action']);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        try {
            $conn->rollBack();
        } catch (Throwable $rollbackError) {
            error_log(sprintf(
                'Queue API rollback failure [%s]: %s in %s:%d',
                $requestId,
                $rollbackError->getMessage(),
                $rollbackError->getFile(),
                $rollbackError->getLine()
            ));
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
    $payload = [
        'success' => false,
        'message' => "The queue service encountered a server or database error. Check server logs (Reference: $requestId)",
        'request_id' => $requestId,
        'debug_error' => $error->getMessage(),
    ];
    if ($error instanceof PDOException && $error->getCode() !== '') {
        $payload['sqlstate'] = (string) $error->getCode();
    }
    respond(500, $payload);
}

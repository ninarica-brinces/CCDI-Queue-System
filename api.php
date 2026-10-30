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
            'SELECT * FROM queue_tickets WHERE service_date = ? ORDER BY created_at ASC, ticket_id ASC'
        );
        $stmt->execute([$today]);
        respond(200, ['success' => true, 'data' => $stmt->fetchAll()]);
    }

    if ($method === 'GET' && in_array($action, ['search_students', 'search_student'], true)) {
        $query = requestString($_GET, 'q');
        if (strlen($query) > 100) {
            respond(400, ['success' => false, 'message' => 'Search query is too long']);
        }
        if ($query === '') {
            respond(200, ['success' => true, 'data' => []]);
        }

        $searchTerm = '%' . $query . '%';
        $stmt = $conn->prepare(
            "SELECT student_id, first_name, last_name,
                    first_name || ' ' || last_name AS full_name,
                    course, address, year_level
             FROM ccdi_students
             WHERE student_id ILIKE :student_id
                OR first_name ILIKE :first_name
                OR last_name ILIKE :last_name
                OR (first_name || ' ' || last_name) ILIKE :full_name
             ORDER BY last_name, first_name
             LIMIT 10"
        );
        $stmt->execute([
            'student_id' => $searchTerm,
            'first_name' => $searchTerm,
            'last_name' => $searchTerm,
            'full_name' => $searchTerm,
        ]);
        respond(200, ['success' => true, 'data' => $stmt->fetchAll()]);
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
        if ($studentName === '' || $service === '' || $payment === '') {
            respond(400, ['success' => false, 'message' => 'Student name, service, and payment are required']);
        }
        if (
            strlen($studentName) > 120 ||
            strlen($studentId) > 64 ||
            strlen($course) > 120 ||
            strlen($address) > 500 ||
            strlen($yearLevel) > 32 ||
            strlen($service) > 120 ||
            strlen($payment) > 240 ||
            strlen($paymentPeriod) > 64 ||
            strlen($paymentPercentage) > 32 ||
            strlen($discount) > 120 ||
            strlen($notes) > 240
        ) {
            respond(400, ['success' => false, 'message' => 'One or more ticket fields exceed the allowed length']);
        }

        $conn->beginTransaction();
        $lock = $conn->prepare("SELECT pg_advisory_xact_lock(hashtext(?))");
        $lock->execute(["ticket-number:$today:$lane"]);

        if ($studentId !== '') {
            $studentLock = $conn->prepare("SELECT pg_advisory_xact_lock(hashtext(?))");
            $studentLock->execute(["student:$today:$studentId"]);
            $active = $conn->prepare(
                "SELECT 1 FROM queue_tickets
                 WHERE service_date = ? AND student_id = ? AND status IN ('waiting', 'serving')
                 LIMIT 1"
            );
            $active->execute([$today, $studentId]);
            if ($active->fetchColumn()) {
                $conn->rollBack();
                respond(409, ['success' => false, 'message' => 'This student already has an active ticket today']);
            }
        }

        $count = $conn->prepare(
            'SELECT COUNT(*) FROM queue_tickets WHERE lane = ? AND service_date = ?'
        );
        $count->execute([$lane, $today]);
        $prefix = $lane === 'Priority' ? 'P' : 'A';
        $ticketNumber = $prefix . '-' . str_pad((string) ((int) $count->fetchColumn() + 1), 3, '0', STR_PAD_LEFT);

        $insert = $conn->prepare(
            "INSERT INTO queue_tickets
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
        $lock = $conn->prepare("SELECT pg_advisory_xact_lock(hashtext(?))");
        $lock->execute(["serving:$today:$lane"]);

        $serving = $conn->prepare(
            "SELECT ticket_id FROM queue_tickets
             WHERE service_date = ? AND lane = ? AND status = 'serving'
             LIMIT 1"
        );
        $serving->execute([$today, $lane]);
        if ($serving->fetchColumn()) {
            $conn->rollBack();
            respond(409, ['success' => false, 'message' => 'A ticket is already being served in this lane']);
        }

        $next = $conn->prepare(
            "SELECT ticket_id FROM queue_tickets
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
            "UPDATE queue_tickets SET status = 'serving'
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

        $stmt = $conn->prepare(
            "UPDATE queue_tickets
             SET status = ?, completed_at = CURRENT_TIMESTAMP
             WHERE ticket_id = ? AND service_date = ? AND status = 'serving'
             RETURNING ticket_id"
        );
        $stmt->execute([$status, $ticketId, $today]);
        if (!$stmt->fetchColumn()) {
            respond(409, ['success' => false, 'message' => 'Ticket is no longer being served']);
        }
        respond(200, ['success' => true]);
    }

    if ($actionType === 'reset_day') {
        $stmt = $conn->prepare('DELETE FROM queue_tickets WHERE service_date = ?');
        $stmt->execute([$today]);
        respond(200, ['success' => true]);
    }

    respond(400, ['success' => false, 'message' => 'Unknown action']);
} catch (Throwable $error) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        try {
            $conn->rollBack();
        } catch (Throwable $rollbackError) {
            error_log("Queue API rollback failure [$requestId]: " . $rollbackError->getMessage());
        }
    }
    error_log(sprintf(
        'Queue API error [%s]: %s in %s:%d',
        $requestId,
        $error->getMessage(),
        $error->getFile(),
        $error->getLine()
    ));
    if ($error instanceof PDOException && $error->getCode() === '23505') {
        respond(409, [
            'success' => false,
            'message' => 'An active ticket already exists for this student',
            'request_id' => $requestId,
        ]);
    }
    $payload = [
        'success' => false,
        'message' => "The queue service is temporarily unavailable (Reference: $requestId)",
        'request_id' => $requestId,
    ];
    if (getenv('APP_DEBUG') === 'true') {
        $payload['debug'] = $error->getMessage();
    }
    respond(500, $payload);
}

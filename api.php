<?php
require_once 'db.php';

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$today = date('Y-m-d');

// 1. GET STATE (Kunin ang mga active tickets ngayong araw)
if ($method === 'GET' && $action === 'get_state') {
    $stmt = $conn->prepare("SELECT * FROM queue_tickets WHERE service_date = ? ORDER BY created_at ASC");
    $stmt->bind_param("s", $today);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $tickets = [];
    while ($row = $result->fetch_assoc()) {
        $tickets[] = $row;
    }
    
    echo json_encode(["success" => true, "data" => $tickets]);
    exit();
}

// 2. SEARCH STUDENTS (Pinagsama ang first_name at last_name mula sa ccdi_students)
if ($method === 'GET' && $action === 'search_students') {
    $query = $_GET['q'] ?? '';
    $searchTerm = "%" . $query . "%";
    
    $stmt = $conn->prepare("SELECT CONCAT(first_name, ' ', last_name) AS student_name FROM ccdi_students WHERE first_name LIKE ? OR last_name LIKE ? LIMIT 10");
    $stmt->bind_param("ss", $searchTerm, $searchTerm);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $students = [];
    while ($row = $result->fetch_assoc()) {
        $students[] = $row['student_name'];
    }
    
    echo json_encode(["success" => true, "data" => $students]);
    exit();
}

// 3. POST ACTIONS (Create, Update Status, Reset Day)
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $actionType = $input['action'] ?? '';

    if ($actionType === 'create_ticket') {
        $lane = $input['lane'];
        $studentName = $input['studentName'];
        $service = $input['service'];
        $payment = $input['payment'];
        $discount = isset($input['discount']) && $input['discount'] !== '' ? $input['discount'] : 'None';
        $prefix = ($lane === 'Priority') ? 'P' : 'A';

        $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM queue_tickets WHERE lane = ? AND service_date = ?");
        $stmt->bind_param("ss", $lane, $today);
        $stmt->execute();
        $count = $stmt->get_result()->fetch_assoc()['cnt'] + 1;
        $ticketNumber = $prefix . '-' . str_pad($count, 3, '0', STR_PAD_LEFT);

        // Pagsasamahin ang Payment at Discount sa Record
        $paymentWithDiscount = ($discount !== 'None') ? $payment . " (Discount: " . $discount . ")" : $payment;

        $stmt = $conn->prepare("INSERT INTO queue_tickets (ticket_number, lane, student_name, transaction_type, payment_method, status, service_date) VALUES (?, ?, ?, ?, ?, 'waiting', ?)");
        $stmt->bind_param("ssssss", $ticketNumber, $lane, $studentName, $service, $paymentWithDiscount, $today);
        
        if ($stmt->execute()) {
            echo json_encode(["success" => true, "ticket_id" => $conn->insert_id, "ticket_number" => $ticketNumber]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to create ticket"]);
        }
        exit();
    }

    if ($actionType === 'update_status') {
        $ticketId = $input['ticket_id'];
        $status = $input['status'];
        $completedAt = ($status === 'completed' || $status === 'cancelled') ? date('Y-m-d H:i:s') : null;

        $stmt = $conn->prepare("UPDATE queue_tickets SET status = ?, completed_at = ? WHERE ticket_id = ?");
        $stmt->bind_param("ssi", $status, $completedAt, $ticketId);
        
        if ($stmt->execute()) {
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to update status"]);
        }
        exit();
    }

    if ($actionType === 'reset_day') {
        $stmt = $conn->prepare("DELETE FROM queue_tickets WHERE service_date = ?");
        $stmt->bind_param("s", $today);
        if ($stmt->execute()) {
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false, "message" => "Failed to reset day"]);
        }
        exit();
    }
}
?>
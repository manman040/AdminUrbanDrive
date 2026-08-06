<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['driverId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required field: driverId']);
    exit;
}

$driverId = $input['driverId'];
$deadlineTimestamp = null;
$deadlineDate = $input['deadlineDate'] ?? null;
$deadlineTime = $input['deadlineTime'] ?? null;

// If deadlineDate is provided, convert to timestamp
if ($deadlineDate) {
    // If time is also provided, combine them
    if ($deadlineTime) {
        $datetimeString = $deadlineDate . ' ' . $deadlineTime;
    } else {
        $datetimeString = $deadlineDate . ' 23:59:59'; // Default to end of day
    }
    
    $deadlineTimestamp = strtotime($datetimeString);
    if ($deadlineTimestamp === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date/time format']);
        exit;
    }
    
    // Convert to milliseconds for Firebase timestamp format
    $deadlineTimestamp = $deadlineTimestamp * 1000;
} else {
    // If no date provided, remove the deadline
    $deadlineTimestamp = null;
}

try {
    // Get current driver commission data
    $driverCommissionData = getFirebaseData('driver_commissions/' . $driverId);
    
    if (!is_array($driverCommissionData)) {
        $driverCommissionData = [];
    }
    
    // Update payment deadline
    if ($deadlineTimestamp !== null) {
        $driverCommissionData['payment_deadline'] = [
            'time' => $deadlineTimestamp,
            'date' => date('Y-m-d H:i:s', firebaseTimestampToSeconds($deadlineTimestamp)),
            'set_by' => $_SESSION['admin_username'] ?? 'admin',
            'set_at' => time() * 1000
        ];
    } else {
        // Remove deadline if null
        if (isset($driverCommissionData['payment_deadline'])) {
            unset($driverCommissionData['payment_deadline']);
        }
    }
    
    // Update in Firebase
    $result = updateFirebaseData('driver_commissions/' . $driverId, $driverCommissionData);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => $deadlineTimestamp ? 'Payment deadline set successfully' : 'Payment deadline removed successfully',
            'deadline' => $deadlineTimestamp ? [
                'time' => $deadlineTimestamp,
                'date' => date('Y-m-d H:i:s', firebaseTimestampToSeconds($deadlineTimestamp))
            ] : null
        ]);
    } else {
        throw new Exception('Failed to update Firebase');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error setting payment deadline: ' . $e->getMessage()
    ]);
}
?>


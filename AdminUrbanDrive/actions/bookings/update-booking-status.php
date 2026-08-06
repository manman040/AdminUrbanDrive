<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['bookingId']) || !isset($input['status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$bookingId = $input['bookingId'];
$status = $input['status'];

// Validate status
if (!in_array($status, ['confirmed', 'completed', 'cancelled', 'pending'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

try {
    // Update the booking status in Firebase
    $updateData = [
        'status' => $status,
        'updatedAt' => time() * 1000
    ];
    
    $success = updateFirebaseData('bookings/' . $bookingId, $updateData);
    
    if ($success) {
        // If booking is marked as completed, also create commission record
        if ($status === 'completed') {
            $booking = getFirebaseData("bookings/{$bookingId}");
            if ($booking) {
                $driverId = $booking['driverId'] ?? 'unknown';
                $fare = bookingFareAmount($booking);
                $commissionAmount = ($fare * 20) / 100; // 20% company cut
                
                // Update driver commission data
                updateDriverCommission($driverId, $bookingId, $fare, $commissionAmount, 'unpaid');
            }
        }
        
        echo json_encode(['success' => true, 'message' => 'Booking status updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update booking status']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>

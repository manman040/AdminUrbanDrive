<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['bookingId']) || !isset($input['commissionAmount'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$bookingId = $input['bookingId'];
$commissionAmount = floatval($input['commissionAmount']);
$rate = intval($input['rate'] ?? 20); // Default 20% company cut

try {
    // Get the booking to find driver name
    $booking = getFirebaseData("bookings/{$bookingId}");
    
    if (!$booking) {
        throw new Exception('Booking not found');
    }
    
    $driverId = $booking['driverId'] ?? 'unknown';
    $driverName = $booking['driver_name'] ?? ($booking['driverName'] ?? $driverId);
    $fare = bookingFareAmount($booking);
    
    // Create commission record
    $commissionData = [
        'booking_id' => $bookingId,
        'driver_name' => $driverName,
        'driver_id' => $driverId,
        'fare_amount' => $fare,
        'commission_rate' => $rate,
        'commission_amount' => $commissionAmount,
        'status' => 'paid',
        'created_at' => time() * 1000, // Firebase timestamp format
        'paid_at' => time() * 1000,
        'paid_by' => $_SESSION['admin_username'] ?? 'admin'
    ];
    
    // Save to Firebase commissions table
    $result = updateFirebaseData("commissions/{$bookingId}", $commissionData);
    
    if ($result) {
        // ALSO update the driver's commission summary in driver_commissions
        updateDriverCommission($driverId, $bookingId, $fare, $commissionAmount, 'paid');
        
        echo json_encode(['success' => true, 'message' => 'Commission marked as paid and driver summary updated']);
    } else {
        throw new Exception('Failed to update commission status');
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

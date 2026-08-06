<?php
session_start();
require_once 'firebase-config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
	http_response_code(401);
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$bookingId = $_POST['bookingId'] ?? ($input['bookingId'] ?? '');
$driverId = $_POST['driverId'] ?? ($input['driverId'] ?? '');
$status = $_POST['status'] ?? ($input['status'] ?? 'confirmed');

if ($bookingId === '' || $driverId === '') {
	http_response_code(400);
	echo json_encode(['success' => false, 'message' => 'bookingId and driverId required']);
	exit;
}

try {
	// Validate driver capacity before assignment
	if (!canDriverAcceptBooking($driverId, $bookingId)) {
		http_response_code(400);
		echo json_encode([
			'success' => false, 
			'message' => 'Driver capacity insufficient for this booking. The booking requires more passengers than the driver\'s car can accommodate.'
		]);
		exit;
	}
	
	$update = [
		'driverId' => $driverId,
		'status' => $status,
		'updatedAt' => time() * 1000
	];
	$ok = updateFirebaseData('bookings/' . $bookingId, $update);
	if ($ok) {
		// Optionally update driver_commissions immediately (no fare change)
		$booking = getFirebaseData('bookings/' . $bookingId);
		$fare = bookingFareAmount($booking);
		$commission = ($fare * 20) / 100;
		updateDriverCommission($driverId, $bookingId, $fare, $commission, $status === 'completed' ? 'unpaid' : 'pending');
		echo json_encode(['success' => true]);
	} else {
		echo json_encode(['success' => false, 'message' => 'Failed to update booking']);
	}
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

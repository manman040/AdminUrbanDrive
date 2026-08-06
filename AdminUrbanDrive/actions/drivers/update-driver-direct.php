<?php
session_start();
require_once 'firebase-config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$driverId = $input['driverId'] ?? '';
$status = $input['status'] ?? '';

if (!$driverId || !$status) {
	echo json_encode(['success' => false, 'message' => 'Driver ID and status are required']);
	exit;
}

// Validate status
if (!in_array($status, ['active', 'approved', 'rejected', 'pending', 'disabled'])) {
	echo json_encode(['success' => false, 'message' => 'Invalid status']);
	exit;
}

try {
	// Get current driver data
	$currentDriver = getFirebaseData('drivers/' . $driverId);
	
	if (empty($currentDriver) || !is_array($currentDriver)) {
		echo json_encode(['success' => false, 'message' => 'Driver not found']);
		exit;
	}
	
	// Update status
	$currentDriver['status'] = $status;
	$currentDriver['updated_at'] = date('Y-m-d H:i:s');
	
	// Update in Firebase
	$success = setFirebaseData('drivers/' . $driverId, $currentDriver);
	
	if ($success) {
		echo json_encode(['success' => true, 'message' => 'Driver status updated successfully', 'driverId' => $driverId, 'status' => $status]);
	} else {
		echo json_encode(['success' => false, 'message' => 'Failed to update driver status']);
	}
} catch (Exception $e) {
	error_log('Error updating driver status: ' . $e->getMessage());
	echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>


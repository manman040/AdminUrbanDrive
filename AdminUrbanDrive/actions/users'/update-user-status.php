<?php
session_start();
require_once 'firebase-config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$userId = $input['userId'] ?? '';
$status = $input['status'] ?? '';

if (!$userId || !$status) {
	echo json_encode(['success' => false, 'message' => 'User ID and status are required']);
	exit;
}

// Validate status
if (!in_array($status, ['active', 'disabled'])) {
	echo json_encode(['success' => false, 'message' => 'Invalid status. Must be "active" or "disabled"']);
	exit;
}

try {
	// Get current user data
	$currentUser = getFirebaseData('users/' . $userId);
	
	if (empty($currentUser) || !is_array($currentUser)) {
		echo json_encode(['success' => false, 'message' => 'User not found']);
		exit;
	}
	
	// Update status
	$currentUser['status'] = $status;
	$currentUser['updated_at'] = date('Y-m-d H:i:s');
	
	// Update in Firebase
	$success = setFirebaseData('users/' . $userId, $currentUser);
	
	if ($success) {
		echo json_encode(['success' => true, 'message' => 'User status updated successfully', 'userId' => $userId, 'status' => $status]);
	} else {
		echo json_encode(['success' => false, 'message' => 'Failed to update user status']);
	}
} catch (Exception $e) {
	error_log('Error updating user status: ' . $e->getMessage());
	echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>


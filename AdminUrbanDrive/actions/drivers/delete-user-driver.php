<?php
session_start();
require_once 'firebase-config.php';

header('Content-Type: application/json');

requireAdminSession(true);

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$id = $input['id'] ?? '';
$type = $input['type'] ?? ''; // 'user' or 'driver'

if (!$id || !$type) {
	echo json_encode(['success' => false, 'message' => 'ID and type are required']);
	exit;
}

try {
	if ($type === 'user') {
		// Delete user from Firebase
		deleteFirebaseData('users/' . $id);
		echo json_encode(['success' => true, 'message' => 'User deleted successfully', 'id' => $id, 'type' => 'user']);
	} elseif ($type === 'driver') {
		// Delete driver from multiple paths
		deleteFirebaseData('drivers/' . $id);
		deleteFirebaseData('driver_approvals/' . $id);
		deleteFirebaseData('driver_commissions/' . $id);
		// Optionally delete from driver_applications if it exists
		deleteFirebaseData('driver_applications/' . $id);
		echo json_encode(['success' => true, 'message' => 'Driver deleted successfully', 'id' => $id, 'type' => 'driver']);
	} else {
		echo json_encode(['success' => false, 'message' => 'Invalid type. Must be "user" or "driver"']);
	}
} catch (Exception $e) {
	error_log('Error deleting ' . $type . ': ' . $e->getMessage());
	echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>


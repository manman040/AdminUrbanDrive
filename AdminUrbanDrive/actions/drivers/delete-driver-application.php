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
$hardDelete = !empty($input['hardDelete']);

if (!$driverId) {
	echo json_encode(['success' => false, 'message' => 'driverId is required']);
	exit;
}

try {
	// Remove from approvals/applications
	// Support both paths depending on how data was stored
	deleteFirebaseData('driver_approvals/' . $driverId);
	deleteFirebaseData('driver_applications/' . $driverId);

	$extra = [];
	if ($hardDelete) {
		// Also remove from drivers and driver_commissions
		deleteFirebaseData('drivers/' . $driverId);
		deleteFirebaseData('driver_commissions/' . $driverId);
		$extra['hardDeleted'] = true;
	}

	echo json_encode(['success' => true, 'driverId' => $driverId] + $extra);
} catch (Exception $e) {
	echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


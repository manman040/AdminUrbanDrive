<?php
session_start();
require_once 'firebase-config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
	http_response_code(401);
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit;
}

header('Content-Type: application/json');

try {
	$drivers = getFirebaseData('drivers');
	$commissions = getFirebaseData('driver_commissions');

	$active = is_array($drivers) ? array_keys($drivers) : [];
	$orphans = [];

	if (is_array($commissions)) {
		foreach ($commissions as $driverId => $_) {
			if (!in_array($driverId, $active, true)) {
				$orphans[] = $driverId;
			}
		}
	}

	foreach ($orphans as $driverId) {
		deleteFirebaseData('driver_commissions/' . $driverId);
	}

	echo json_encode([
		'success' => true,
		'message' => 'Cleanup completed',
		'orphans_deleted' => count($orphans),
		'ids' => $orphans,
	]);
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


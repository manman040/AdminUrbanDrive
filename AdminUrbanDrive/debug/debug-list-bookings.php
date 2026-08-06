<?php
session_start();
require_once 'firebase-config.php';

header('Content-Type: application/json');

$driverId = $_GET['driver_id'] ?? '';
if ($driverId === '') {
	echo json_encode(['error' => 'Provide ?driver_id=...']);
	exit;
}

try {
	// Use the new capacity-filtered function to get available bookings
	// This will automatically filter out bookings that exceed driver capacity
	$availableBookings = getAvailableBookingsForDriver($driverId);
	
	// Also get assigned bookings (bookings already assigned to this driver)
	$bookings = getBookingsData();
	$assignedList = [];
	foreach ($bookings as $id => $b) {
		if (!is_array($b)) continue;
		if (($b['driverId'] ?? '') === $driverId) {
			$assignedList[] = [
				'bookingId' => $id,
				'status' => strtolower($b['status'] ?? ''),
				'fare' => floatval($b['estimatedFare'] ?? 0),
				'createdAt' => $b['createdAt']['time'] ?? null
			];
		}
	}
	
	// Format available bookings
	$availableList = [];
	foreach ($availableBookings as $id => $b) {
		if (!is_array($b)) continue;
		$availableList[] = [
			'bookingId' => $id,
			'status' => strtolower($b['status'] ?? ''),
			'fare' => floatval($b['estimatedFare'] ?? 0),
			'createdAt' => $b['createdAt']['time'] ?? null,
			'passengerCount' => intval($b['passengerCount'] ?? $b['numberOfPassengers'] ?? $b['passengers'] ?? $b['passengerCapacity'] ?? 1)
		];
	}
	
	$driverCapacity = getDriverCapacity($driverId);
	
	echo json_encode([
		'driverId' => $driverId,
		'driverCapacity' => $driverCapacity,
		'availableBookings' => [
			'count' => count($availableList),
			'bookings' => $availableList
		],
		'assignedBookings' => [
			'count' => count($assignedList),
			'bookings' => $assignedList
		]
	], JSON_PRETTY_PRINT);
} catch (Exception $e) {
	echo json_encode(['error' => $e->getMessage()]);
}
?>

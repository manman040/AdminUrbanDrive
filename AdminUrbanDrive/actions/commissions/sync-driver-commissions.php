<?php
session_start();
require_once 'firebase-config.php';

// Optional: protect behind admin session if desired
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
	http_response_code(401);
	echo json_encode(['success' => false, 'message' => 'Unauthorized']);
	exit;
}

header('Content-Type: application/json');

try {
	$bookings = getBookingsData();
	if (!is_array($bookings) || empty($bookings)) {
		echo json_encode(['success' => true, 'message' => 'No bookings found', 'drivers_processed' => 0]);
		exit;
	}

	// Group by driver and recompute
	$byDriver = [];
	foreach ($bookings as $bookingId => $b) {
		$status = strtolower($b['status'] ?? '');
		// Skip cancelled/rejected; include confirmed and completed
		if (in_array($status, ['cancelled','rejected'])) { continue; }
		$driverId = $b['driverId'] ?? null; if (!$driverId) { continue; }
		$fare = bookingFareAmount($b);
		$commission = ($fare * 20) / 100; // 20% company cut
		
		if (!isset($byDriver[$driverId])) {
			$byDriver[$driverId] = [
				'total_fare' => 0.0,
				'total_commission' => 0.0,
				'paid_commission' => 0.0, // we keep existing paid later
				'jobs' => []
			];
		}
		if (in_array($status, ['completed','confirmed'])) {
			$byDriver[$driverId]['total_fare'] += $fare;
			$byDriver[$driverId]['total_commission'] += $commission;
		}
		// De-duplicate by bookingId key
		$byDriver[$driverId]['jobs'][$bookingId] = [
			'booking_id' => $bookingId,
			'fare_amount' => $fare,
			'commission_amount' => $commission,
			'status' => $status === 'completed' ? 'unpaid' : 'pending',
			'job_date' => isset($b['createdAt']) ? date('Y-m-d', firebaseTimestampToSeconds($b['createdAt'])) : date('Y-m-d'),
			'paid_date' => null
		];
	}

	// Pull driver names
	$drivers = getFirebaseData('drivers');
	$processed = 0;
	foreach ($byDriver as $driverId => $agg) {
		$name = 'Unknown Driver';
		if (is_array($drivers) && isset($drivers[$driverId])) {
			$name = $drivers[$driverId]['name'] ?? ($drivers[$driverId]['fullName'] ?? ($drivers[$driverId]['driverName'] ?? $name));
		}

		$existing = getFirebaseData('driver_commissions/' . $driverId);
		$paid = 0.0;
		if (is_array($existing) && isset($existing['commission_summary']['paid_commission'])) {
			$paid = floatval($existing['commission_summary']['paid_commission']);
		}

		$payload = [
			'driver_info' => [
				'driver_id' => $driverId,
				'driver_name' => $name,
				'last_updated' => time() * 1000
			],
			'commission_summary' => [
				'total_earnings' => $agg['total_fare'],
				'total_commission' => $agg['total_commission'],
				'paid_commission' => $paid,
				'unpaid_commission' => max($agg['total_commission'] - $paid, 0),
				'last_updated' => time() * 1000,
				'commission_rate' => 20
			],
			'recent_jobs' => array_values($agg['jobs'])
		];
		
		// Ensure recent_jobs is always an array, even if empty
		if (!isset($payload['recent_jobs']) || !is_array($payload['recent_jobs'])) {
			$payload['recent_jobs'] = [];
		}
		
		updateFirebaseData('driver_commissions/' . $driverId, $payload);
		$processed++;
	}

	echo json_encode(['success' => true, 'drivers_processed' => $processed]);
} catch (Exception $e) {
	http_response_code(500);
	echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

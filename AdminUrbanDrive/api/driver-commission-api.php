<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'firebase-config.php';

// Get driver ID from URL parameter or POST data
$driverId = $_GET['driver_id'] ?? $_POST['driver_id'] ?? null;

if (!$driverId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Driver ID is required',
        'usage' => 'Use: ?driver_id=YOUR_DRIVER_ID'
    ]);
    exit;
}

try {
    // Get driver's commission data
    $driverCommissionData = getFirebaseData("driver_commissions/{$driverId}");
    
    if (!$driverCommissionData) {
        // If no commission data exists, create basic structure
        $driverCommissionData = [
            'driver_info' => [
                'driver_id' => $driverId,
                'driver_name' => 'Unknown Driver',
                'last_updated' => time() * 1000
            ],
            'commission_summary' => [
                'total_earnings' => 0.0,
                'total_commission' => 0.0,
                'paid_commission' => 0.0,
                'unpaid_commission' => 0.0,
                'last_updated' => time() * 1000,
                'commission_rate' => 20
            ],
            'recent_jobs' => [],
            'payment_history' => []
        ];
    }
    
    // Always compute the commission summary live from bookings/commission_payment.
    // driver_commissions.commission_summary is only ever written once, when the
    // driver is approved -- the app never updates it as rides complete, so it
    // can't be trusted as-is (previously this only recomputed when it read
    // exactly 0, which meant it went stale again after the first real ride).
    $bookings = getBookingsData();
    $totalFare = 0.0;
    $totalCommission = 0.0;
    $recentJobs = [];

    if (is_array($bookings)) {
        foreach ($bookings as $bookingId => $booking) {
            if (strtolower($booking['status'] ?? '') === 'completed' &&
                ($booking['driverId'] ?? '') === $driverId) {

                $fare = bookingFareAmount($booking);
                $commission = ($fare * 20) / 100; // 20% company cut

                $totalFare += $fare;
                $totalCommission += $commission;

                $recentJobs[] = [
                    'booking_id' => $bookingId,
                    'fare_amount' => $fare,
                    'commission_amount' => $commission,
                    'status' => 'unpaid',
                    'job_date' => isset($booking['createdAt']) ?
                        date('Y-m-d', firebaseTimestampToSeconds($booking['createdAt'])) :
                        date('Y-m-d'),
                    'paid_date' => null
                ];
            }
        }
    }

    $paidCommission = 0.0;
    try {
        $payments = getFirebaseData("commission_payment/{$driverId}");
        if (is_array($payments)) {
            foreach ($payments as $payment) {
                if (!is_array($payment)) continue;
                $status = strtolower($payment['status'] ?? '');
                if (in_array($status, ['paid', 'success', 'completed'], true)) {
                    $paidCommission += floatval($payment['amount'] ?? 0);
                }
            }
        }
    } catch (Exception $e) {
        // Leave paidCommission at 0 if payments can't be read.
    }
    if ($paidCommission > $totalCommission) {
        $paidCommission = $totalCommission;
    }

    // Update commission summary
    $driverCommissionData['commission_summary'] = [
        'total_earnings' => $totalFare,
        'total_commission' => $totalCommission,
        'paid_commission' => $paidCommission,
        'unpaid_commission' => max($totalCommission - $paidCommission, 0),
        'last_updated' => time() * 1000,
        'commission_rate' => 20
    ];

    $driverCommissionData['recent_jobs'] = $recentJobs;
    
    // Return driver commission data
    echo json_encode([
        'success' => true,
        'data' => $driverCommissionData,
        'timestamp' => time() * 1000
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching commission data: ' . $e->getMessage()
    ]);
}
?>

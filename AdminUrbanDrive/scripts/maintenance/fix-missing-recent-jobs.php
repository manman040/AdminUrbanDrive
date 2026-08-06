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
    // Get all drivers from drivers table
    $drivers = getFirebaseData('drivers');
    if (!is_array($drivers)) {
        echo json_encode(['success' => false, 'message' => 'No drivers found']);
        exit;
    }

    $fixed = 0;
    foreach ($drivers as $driverId => $driverData) {
        $driverName = $driverData['name'] ?? ($driverData['driverName'] ?? 'Unknown Driver');
        
        // Check if driver_commissions exists for this driver
        $existingCommission = getFirebaseData('driver_commissions/' . $driverId);
        
        if (!$existingCommission) {
            // Create initial structure for driver
            $payload = [
                'driver_info' => [
                    'driver_id' => $driverId,
                    'driver_name' => $driverName,
                    'last_updated' => time() * 1000
                ],
                'commission_summary' => [
                    'total_earnings' => 0,
                    'total_commission' => 0,
                    'paid_commission' => 0,
                    'unpaid_commission' => 0,
                    'last_updated' => time() * 1000,
                    'commission_rate' => 20
                ],
                'recent_jobs' => []
            ];
            
            updateFirebaseData('driver_commissions/' . $driverId, $payload);
            $fixed++;
            echo "Created commission structure for driver: $driverName ($driverId)<br>";
        } else {
            // Check if recent_jobs is missing
            if (!isset($existingCommission['recent_jobs'])) {
                // Add missing recent_jobs
                $existingCommission['recent_jobs'] = [];
                updateFirebaseData('driver_commissions/' . $driverId, $existingCommission);
                $fixed++;
                echo "Added missing recent_jobs for driver: $driverName ($driverId)<br>";
            }
        }
    }

    echo "<br><strong>Fixed $fixed drivers</strong><br>";
    echo "<br><a href='driver-commission.php'>Go back to Driver Commission page</a>";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
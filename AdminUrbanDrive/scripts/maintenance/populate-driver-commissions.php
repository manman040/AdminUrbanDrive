<?php
require_once 'firebase-config.php';

echo "Populating driver_commissions with existing booking data...\n\n";

try {
    // Get all bookings
    $bookings = getBookingsData();
    
    if (empty($bookings)) {
        echo "No bookings found in Firebase.\n";
        exit;
    }
    
    $processedDrivers = [];
    $totalProcessed = 0;
    
    foreach ($bookings as $bookingId => $booking) {
        $status = strtolower($booking['status'] ?? '');
        
        // Only process completed rides
        if ($status !== 'completed') {
            continue;
        }
        
        $driverId = $booking['driverId'] ?? 'unknown';
        $fare = floatval($booking['estimatedFare'] ?? 0);
        $commissionAmount = ($fare * 20) / 100; // 20% company cut
        
        // Check if we already processed this driver
        if (!in_array($driverId, $processedDrivers)) {
            $processedDrivers[] = $driverId;
            
            // Get driver name from drivers; fallback to driver_approvals
            $driverName = 'Unknown Driver';
            try {
                $driversData = getFirebaseData('drivers');
                if (is_array($driversData) && isset($driversData[$driverId])) {
                    $driverName = $driversData[$driverId]['name'] ?? $driversData[$driverId]['driverName'] ?? $driverName;
                } else {
                    $approvals = getFirebaseData('driver_approvals');
                    if (is_array($approvals) && isset($approvals[$driverId])) {
                        $driverName = $approvals[$driverId]['name'] ?? $approvals[$driverId]['driverName'] ?? $driverName;
                    }
                }
            } catch (Exception $e) {
                // Keep default name
            }
            
            // Create initial driver commission record
            // Pull driver name strictly from drivers table
            $driverNameStrict = $driverName;
            $driversOnly = getFirebaseData('drivers');
            if (is_array($driversOnly) && isset($driversOnly[$driverId])) {
                $driverNameStrict = $driversOnly[$driverId]['name'] ?? ($driversOnly[$driverId]['driverName'] ?? $driverNameStrict);
            }

            $driverCommissionData = [
                'driver_info' => [
                    'driver_id' => $driverId,
                    'driver_name' => $driverNameStrict,
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
            
            // Calculate totals for this driver
            $totalFare = 0.0;
            $totalCommission = 0.0;
            $recentJobs = [];
            
            foreach ($bookings as $bId => $b) {
                if (strtolower($b['status'] ?? '') === 'completed' && 
                    ($b['driverId'] ?? '') === $driverId) {
                    
                    $bFare = floatval($b['estimatedFare'] ?? 0);
                    $bCommission = ($bFare * 20) / 100;
                    
                    $totalFare += $bFare;
                    $totalCommission += $bCommission;
                    
                    $recentJobs[] = [
                        'booking_id' => $bId,
                        'fare_amount' => $bFare,
                        'commission_amount' => $bCommission,
                        'status' => 'unpaid', // Default to unpaid
                        'job_date' => isset($b['createdAt']['time']) ? 
                            date('Y-m-d', $b['createdAt']['time'] / 1000) : 
                            date('Y-m-d'),
                        'paid_date' => null
                    ];
                }
            }
            
            // Update the driver commission data
            $driverCommissionData['commission_summary']['total_earnings'] = $totalFare;
            $driverCommissionData['commission_summary']['total_commission'] = $totalCommission;
            $driverCommissionData['commission_summary']['unpaid_commission'] = $totalCommission;
            $driverCommissionData['recent_jobs'] = $recentJobs;
            
            // Write to Firebase
            $url = $firebase_database_url . "driver_commissions/{$driverId}.json";
            $context = stream_context_create([
                'http' => [
                    'method' => 'PUT',
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode($driverCommissionData),
                    'ignore_errors' => true,
                    'timeout' => 10
                ]
            ]);
            
            $result = file_get_contents($url, false, $context);
            
            if ($result !== false) {
                echo "✅ Created commission record for driver: {$driverName} ({$driverId})\n";
                echo "   - Total Earnings: RM " . number_format($totalFare, 2) . "\n";
                echo "   - Total Commission: RM " . number_format($totalCommission, 2) . "\n";
                echo "   - Jobs: " . count($recentJobs) . "\n\n";
                $totalProcessed++;
            } else {
                echo "❌ Failed to create commission record for driver: {$driverId}\n\n";
            }
        }
    }
    
    echo "🎉 Successfully processed {$totalProcessed} drivers!\n";
    echo "📊 Check your Firebase console at: /driver_commissions/\n";
    echo "🔗 Test the API: driver-commission-api.php?driver_id=DRIVER_ID\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>

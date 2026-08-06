<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Debug - Urban Drive</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f6fa; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .success { color: green; }
        .error { color: red; }
        .info { color: blue; }
        .warning { color: orange; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
        h2 { margin-top: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        a { color: #ff6b35; text-decoration: none; }
        a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Dashboard Debug Information</h1>
        <p><a href="dashboard.php">← Back to Dashboard</a></p>

        <!-- Firebase Configuration -->
        <div class="card">
            <h2>1. Firebase Configuration</h2>
            <?php
            global $firebase_config, $firebase_database_url;
            if (isFirebaseConfigured()) {
                echo '<p class="success">✅ Firebase is configured</p>';
                echo '<p class="info">Database URL: ' . htmlspecialchars($firebase_database_url) . '</p>';
            } else {
                echo '<p class="error">❌ Firebase is NOT configured</p>';
            }
            ?>
        </div>

        <!-- Bookings Data -->
        <div class="card">
            <h2>2. Bookings Data</h2>
            <?php
            try {
                $bookings = getBookingsData();
                echo '<p class="info">Bookings data type: ' . gettype($bookings) . '</p>';
                
                if ($bookings === null) {
                    echo '<p class="error">❌ Bookings is NULL</p>';
                } elseif (!is_array($bookings)) {
                    echo '<p class="error">❌ Bookings is not an array. Type: ' . gettype($bookings) . '</p>';
                } elseif (empty($bookings)) {
                    echo '<p class="warning">⚠️ Bookings array is empty</p>';
                } else {
                    echo '<p class="success">✅ Found ' . count($bookings) . ' bookings</p>';
                    
                    // Show first few bookings
                    echo '<h3>Sample Bookings (first 3):</h3>';
                    echo '<table>';
                    echo '<tr><th>Booking ID</th><th>Status</th><th>Fare</th><th>Created At</th></tr>';
                    $count = 0;
                    foreach ($bookings as $bookingId => $booking) {
                        if ($count >= 3) break;
                        if (!is_array($booking)) {
                            echo '<tr><td>' . htmlspecialchars($bookingId) . '</td><td colspan="3">Not an array</td></tr>';
                            continue;
                        }
                        $status = $booking['status'] ?? 'N/A';
                        $fare = isset($booking['estimatedFare']) ? 'RM ' . number_format($booking['estimatedFare'], 2) : 'N/A';
                        $createdAt = isset($booking['createdAt']['time']) ? date('Y-m-d H:i:s', $booking['createdAt']['time'] / 1000) : 'N/A';
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($bookingId) . '</td>';
                        echo '<td>' . htmlspecialchars($status) . '</td>';
                        echo '<td>' . htmlspecialchars($fare) . '</td>';
                        echo '<td>' . htmlspecialchars($createdAt) . '</td>';
                        echo '</tr>';
                        $count++;
                    }
                    echo '</table>';
                    
                    // Show raw data structure
                    echo '<h3>Raw Bookings Data Structure:</h3>';
                    echo '<pre>' . htmlspecialchars(json_encode(array_slice($bookings, 0, 2, true), JSON_PRETTY_PRINT)) . '</pre>';
                }
            } catch (Exception $e) {
                echo '<p class="error">❌ Error getting bookings: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            ?>
        </div>

        <!-- Metrics -->
        <div class="card">
            <h2>3. Metrics Calculation</h2>
            <?php
            try {
                $metrics = getMetrics();
                echo '<table>';
                echo '<tr><th>Metric</th><th>Value</th></tr>';
                echo '<tr><td>Total Rides Today</td><td>' . htmlspecialchars($metrics['total_rides_today']) . '</td></tr>';
                echo '<tr><td>Total Revenue</td><td>' . htmlspecialchars($metrics['total_revenue']) . '</td></tr>';
                echo '<tr><td>Active Rides</td><td>' . htmlspecialchars($metrics['active_rides']) . '</td></tr>';
                echo '<tr><td>Average Rating</td><td>' . htmlspecialchars($metrics['average_rating']) . '</td></tr>';
                echo '</table>';
            } catch (Exception $e) {
                echo '<p class="error">❌ Error calculating metrics: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            ?>
        </div>

        <!-- Ratings Data -->
        <div class="card">
            <h2>4. Customer Ratings Data</h2>
            <?php
            try {
                $ratings = getFirebaseData('ratings');
                echo '<p class="info">Ratings data type: ' . gettype($ratings) . '</p>';
                
                if ($ratings === null) {
                    echo '<p class="warning">⚠️ Ratings is NULL</p>';
                } elseif (!is_array($ratings)) {
                    echo '<p class="warning">⚠️ Ratings is not an array. Type: ' . gettype($ratings) . '</p>';
                } elseif (empty($ratings)) {
                    echo '<p class="warning">⚠️ Ratings array is empty</p>';
                } else {
                    $totalRatings = 0;
                    $totalRatingValue = 0;
                    foreach ($ratings as $driverId => $driverRatings) {
                        if (!is_array($driverRatings)) continue;
                        foreach ($driverRatings as $ratingId => $entry) {
                            if (isset($entry['rating'])) {
                                $totalRatings++;
                                $totalRatingValue += floatval($entry['rating']);
                            }
                        }
                    }
                    $avgRating = $totalRatings > 0 ? round($totalRatingValue / $totalRatings, 1) : 0;
                    echo '<p class="success">✅ Found ' . $totalRatings . ' ratings</p>';
                    echo '<p class="info">Average Rating: ' . $avgRating . '</p>';
                }
            } catch (Exception $e) {
                echo '<p class="error">❌ Error getting ratings: ' . htmlspecialchars($e->getMessage()) . '</p>';
            }
            ?>
        </div>

        <!-- Direct Firebase Test -->
        <div class="card">
            <h2>5. Direct Firebase URL Test</h2>
            <?php
            global $firebase_database_url;
            $testUrl = $firebase_database_url . 'bookings.json';
            echo '<p class="info">Testing URL: <a href="' . htmlspecialchars($testUrl) . '" target="_blank">' . htmlspecialchars($testUrl) . '</a></p>';
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'ignore_errors' => true
                ]
            ]);
            
            $result = @file_get_contents($testUrl, false, $context);
            
            if ($result === FALSE) {
                echo '<p class="error">❌ Failed to fetch data from Firebase URL</p>';
            } else {
                echo '<p class="success">✅ Successfully fetched data</p>';
                echo '<p class="info">Response length: ' . strlen($result) . ' bytes</p>';
                echo '<p class="info">Response preview (first 500 chars):</p>';
                echo '<pre>' . htmlspecialchars(substr($result, 0, 500)) . '</pre>';
                
                $decoded = json_decode($result, true);
                if ($decoded === null) {
                    echo '<p class="error">❌ Failed to decode JSON</p>';
                } else {
                    echo '<p class="success">✅ JSON decoded successfully</p>';
                    echo '<p class="info">Data type: ' . gettype($decoded) . '</p>';
                    if (is_array($decoded)) {
                        echo '<p class="info">Array count: ' . count($decoded) . '</p>';
                    }
                }
            }
            ?>
        </div>
    </div>
</body>
</html>


<?php
// Test Firebase Connection
require_once 'firebase-config.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Firebase Connection Test</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; }";
echo ".success { color: green; }";
echo ".error { color: red; }";
echo ".info { color: blue; }";
echo "</style></head><body>";

echo "<h1>Firebase Connection Test</h1>";

// Test 1: Check if Firebase config is loaded
echo "<h2>1. Firebase Configuration</h2>";
if (isset($firebase_config) && isset($firebase_database_url)) {
    echo "<p class='success'>✅ Firebase configuration loaded</p>";
    echo "<p class='info'>Database URL: " . $firebase_database_url . "</p>";
} else {
    echo "<p class='error'>❌ Firebase configuration not found</p>";
}

// Test 2: Test reading data from Firebase
echo "<h2>2. Reading Data from Firebase</h2>";
try {
    $rides = getRidesData();
    echo "<p class='success'>✅ Successfully read rides data</p>";
    echo "<p class='info'>Rides count: " . count($rides) . "</p>";
    
    if (!empty($rides)) {
        echo "<h3>Sample Rides Data:</h3>";
        echo "<ul>";
        $count = 0;
        foreach ($rides as $rideId => $ride) {
            if ($count >= 3) break; // Show only first 3 rides
            echo "<li><strong>ID:</strong> " . $rideId . " - <strong>Customer:</strong> " . ($ride['customer'] ?? 'N/A') . " - <strong>Status:</strong> " . ($ride['status'] ?? 'N/A') . "</li>";
            $count++;
        }
        echo "</ul>";
    } else {
        echo "<p class='info'>No rides data found in Firebase</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error reading rides data: " . $e->getMessage() . "</p>";
}

// Test 3: Test writing data to Firebase
echo "<h2>3. Writing Data to Firebase</h2>";
try {
    $testData = [
        'test_field' => 'test_value',
        'timestamp' => date('Y-m-d H:i:s'),
        'test_type' => 'connection_test'
    ];
    
    $testId = 'test_' . uniqid();
    if (setFirebaseData('test/' . $testId, $testData)) {
        echo "<p class='success'>✅ Successfully wrote test data to Firebase</p>";
        echo "<p class='info'>Test ID: " . $testId . "</p>";
        
        // Clean up - delete the test data
        if (deleteFirebaseData('test/' . $testId)) {
            echo "<p class='success'>✅ Successfully cleaned up test data</p>";
        } else {
            echo "<p class='error'>⚠️ Could not clean up test data</p>";
        }
    } else {
        echo "<p class='error'>❌ Failed to write test data to Firebase</p>";
    }
} catch (Exception $e) {
    echo "<p class='error'>❌ Error writing test data: " . $e->getMessage() . "</p>";
}

// Test 4: Test driver applications
echo "<h2>4. Driver Applications Data</h2>";
try {
    $applications = getDriverApplications();
    echo "<p class='success'>✅ Successfully read driver applications data</p>";
    echo "<p class='info'>Applications count: " . count($applications) . "</p>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Error reading driver applications: " . $e->getMessage() . "</p>";
}

// Test 5: Test metrics calculation
echo "<h2>5. Metrics Calculation</h2>";
try {
    $metrics = getMetrics();
    echo "<p class='success'>✅ Successfully calculated metrics</p>";
    echo "<ul>";
    echo "<li><strong>Total Rides Today:</strong> " . $metrics['total_rides_today'] . "</li>";
    echo "<li><strong>Total Revenue:</strong> " . $metrics['total_revenue'] . "</li>";
    echo "<li><strong>Active Rides:</strong> " . $metrics['active_rides'] . "</li>";
    echo "<li><strong>Average Rating:</strong> " . $metrics['average_rating'] . "</li>";
    echo "</ul>";
} catch (Exception $e) {
    echo "<p class='error'>❌ Error calculating metrics: " . $e->getMessage() . "</p>";
}

// Test 6: Check if functions are available
echo "<h2>6. Function Availability</h2>";
$functions = [
    'getFirebaseData',
    'setFirebaseData',
    'updateFirebaseData',
    'deleteFirebaseData',
    'getRidesData',
    'getDriverApplications',
    'getMetrics',
    'authenticateWithFirebase'
];

foreach ($functions as $function) {
    if (function_exists($function)) {
        echo "<p class='success'>✅ Function " . $function . " is available</p>";
    } else {
        echo "<p class='error'>❌ Function " . $function . " is not available</p>";
    }
}

echo "<h2>Summary</h2>";
echo "<p>If you see mostly green checkmarks above, your Firebase integration is working correctly!</p>";
echo "<p>If you see red X marks, please check:</p>";
echo "<ul>";
echo "<li>Your Firebase configuration in firebase-config.php</li>";
echo "<li>Your Firebase project settings</li>";
echo "<li>Your database URL and API key</li>";
echo "<li>Your Firebase Realtime Database rules</li>";
echo "</ul>";

echo "<p><a href='dashboard.php'>← Back to Dashboard</a></p>";
echo "</body></html>";
?>


<?php
require_once 'firebase-config.php';

echo "<h1>Testing Driver Applications Fetch</h1>";
echo "<pre>";

// Test Firebase connection
echo "1. Testing Firebase Configuration:\n";
echo "   Database URL: " . $firebase_database_url . "\n";
echo "   Is Configured: " . (isFirebaseConfigured() ? 'YES' : 'NO') . "\n\n";

// Test fetching driver_approvals
echo "2. Fetching driver_approvals from Firebase:\n";
$url = $firebase_database_url . 'driver_approvals.json';
echo "   URL: " . $url . "\n\n";

$context = stream_context_create([
    'http' => [
        'timeout' => 10,
        'ignore_errors' => true,
        'method' => 'GET'
    ]
]);

$result = @file_get_contents($url, false, $context);

echo "3. Raw Response:\n";
if ($result === FALSE) {
    echo "   ERROR: Failed to fetch data\n";
    if (isset($http_response_header)) {
        echo "   HTTP Response Headers:\n";
        foreach ($http_response_header as $header) {
            echo "     " . $header . "\n";
        }
    }
} else {
    echo "   Response Length: " . strlen($result) . " bytes\n";
    echo "   Response Content: " . substr($result, 0, 500) . "\n\n";
    
    if (trim($result) === 'null' || $result === '') {
        echo "   WARNING: Firebase returned null or empty - no data at this path\n";
    } else {
        $data = json_decode($result, true);
        if ($data === null) {
            echo "   ERROR: Failed to decode JSON\n";
            echo "   JSON Error: " . json_last_error_msg() . "\n";
        } else {
            echo "   SUCCESS: Data decoded\n";
            echo "   Data Type: " . gettype($data) . "\n";
            if (is_array($data)) {
                echo "   Array Count: " . count($data) . "\n";
                if (count($data) > 0) {
                    echo "   First Key: " . array_key_first($data) . "\n";
                    echo "   Sample Data (first item):\n";
                    $firstKey = array_key_first($data);
                    $firstItem = $data[$firstKey];
                    echo "     " . print_r($firstItem, true) . "\n";
                }
            }
        }
    }
}

echo "\n4. Testing getFirebaseData() for each path:\n";
$testPaths = ['driverApplications', 'driver_applications', 'applications', 'driver_approvals'];
foreach ($testPaths as $testPath) {
    echo "   Testing $testPath:\n";
    $data = getFirebaseData($testPath);
    echo "     Type: " . gettype($data) . "\n";
    if (is_array($data)) {
        echo "     Count: " . count($data) . "\n";
        if (isset($data['error'])) {
            echo "     Error: " . $data['error'] . "\n";
        } else if (count($data) > 0) {
            echo "     First key: " . array_key_first($data) . "\n";
        }
    }
}

echo "\n5. Using getDriverApplications() function:\n";
$applications = getDriverApplications();
echo "   Return Type: " . gettype($applications) . "\n";
if (is_array($applications)) {
    echo "   Count: " . count($applications) . "\n";
    if (count($applications) > 0) {
        echo "   Keys: " . implode(', ', array_keys($applications)) . "\n";
    }
} else {
    echo "   Value: " . var_export($applications, true) . "\n";
}

echo "\n6. Checking alternative paths:\n";
$alternativePaths = ['drivers', 'driverApplications', 'applications', 'driver_applications'];
foreach ($alternativePaths as $path) {
    $testUrl = $firebase_database_url . $path . '.json';
    $testResult = @file_get_contents($testUrl, false, $context);
    if ($testResult !== FALSE && trim($testResult) !== 'null' && $testResult !== '') {
        $testData = json_decode($testResult, true);
        if ($testData !== null && is_array($testData) && count($testData) > 0) {
            echo "   FOUND DATA at path: $path (count: " . count($testData) . ")\n";
        }
    }
}

echo "</pre>";
?>


<?php
require_once 'firebase-config.php';

echo "<h1>Testing getDriverApplications() Function</h1>";
echo "<pre>";

// Test each path individually
$paths = ['driverApplications', 'driver_applications', 'applications', 'driver_approvals'];

foreach ($paths as $path) {
    echo "\n=== Testing path: $path ===\n";
    $data = getFirebaseData($path);
    echo "Type: " . gettype($data) . "\n";
    if (is_array($data)) {
        echo "Count: " . count($data) . "\n";
        if (isset($data['error'])) {
            echo "Error: " . $data['error'] . "\n";
        } else if (count($data) > 0) {
            echo "First key: " . array_key_first($data) . "\n";
            $firstItem = $data[array_key_first($data)];
            echo "First item type: " . gettype($firstItem) . "\n";
            if (is_array($firstItem)) {
                echo "First item keys: " . implode(', ', array_keys($firstItem)) . "\n";
            }
        }
    } else {
        echo "Value: " . var_export($data, true) . "\n";
    }
}

echo "\n=== Testing getDriverApplications() ===\n";
$apps = getDriverApplications();
echo "Return type: " . gettype($apps) . "\n";
echo "Count: " . count($apps) . "\n";
if (count($apps) > 0) {
    echo "Keys: " . implode(', ', array_keys($apps)) . "\n";
    $firstKey = array_key_first($apps);
    $firstApp = $apps[$firstKey];
    echo "First app type: " . gettype($firstApp) . "\n";
    if (is_array($firstApp)) {
        echo "First app keys: " . implode(', ', array_keys($firstApp)) . "\n";
    }
}

echo "</pre>";
?>


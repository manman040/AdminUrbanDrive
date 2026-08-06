<?php
// Simple Firebase Connection Test - No Login Required
require_once 'firebase-config.php';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Firebase Connection Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f6fa; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .warning { color: orange; font-weight: bold; }
        .info { color: blue; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
        h2 { margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
        .btn { background: #ff6b35; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin-top: 10px; }
        .btn:hover { background: #e55a2b; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Firebase Connection Test</h1>
        <p><a href="dashboard.php" class="btn">← Back to Dashboard</a></p>

        <!-- Configuration Check -->
        <div class="card">
            <h2>1. Firebase Configuration</h2>
            <?php
            global $firebase_config, $firebase_database_url;
            
            echo '<table>';
            echo '<tr><th>Setting</th><th>Value</th><th>Status</th></tr>';
            
            $configOk = true;
            
            // Check API Key
            if (isset($firebase_config['apiKey']) && !empty($firebase_config['apiKey'])) {
                echo '<tr><td>API Key</td><td><code>' . substr($firebase_config['apiKey'], 0, 20) . '...</code></td><td class="success">✅ Set</td></tr>';
            } else {
                echo '<tr><td>API Key</td><td>Not set</td><td class="error">❌ Missing</td></tr>';
                $configOk = false;
            }
            
            // Check Database URL
            if (isset($firebase_database_url) && !empty($firebase_database_url)) {
                echo '<tr><td>Database URL</td><td><code>' . htmlspecialchars($firebase_database_url) . '</code></td><td class="success">✅ Set</td></tr>';
            } else {
                echo '<tr><td>Database URL</td><td>Not set</td><td class="error">❌ Missing</td></tr>';
                $configOk = false;
            }
            
            // Check Project ID
            if (isset($firebase_config['projectId']) && !empty($firebase_config['projectId'])) {
                echo '<tr><td>Project ID</td><td><code>' . htmlspecialchars($firebase_config['projectId']) . '</code></td><td class="success">✅ Set</td></tr>';
            } else {
                echo '<tr><td>Project ID</td><td>Not set</td><td class="error">❌ Missing</td></tr>';
                $configOk = false;
            }
            
            echo '</table>';
            
            if ($configOk) {
                echo '<p class="success">✅ Configuration looks good!</p>';
            } else {
                echo '<p class="error">❌ Configuration has issues!</p>';
            }
            ?>
        </div>

        <!-- Connection Test -->
        <div class="card">
            <h2>2. Direct Connection Test</h2>
            <?php
            $testUrl = $firebase_database_url . '.json';
            echo '<p class="info">Testing URL: <code>' . htmlspecialchars($testUrl) . '</code></p>';
            
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'ignore_errors' => true,
                    'method' => 'GET'
                ]
            ]);
            
            $result = @file_get_contents($testUrl, false, $context);
            
            // Get HTTP response headers
            $http_code = 0;
            $http_message = '';
            if (isset($http_response_header) && !empty($http_response_header)) {
                $status_line = $http_response_header[0];
                preg_match('/HTTP\/\d\.\d\s+(\d+)\s+(.*)/', $status_line, $matches);
                if (isset($matches[1])) {
                    $http_code = intval($matches[1]);
                    $http_message = isset($matches[2]) ? $matches[2] : '';
                }
            }
            
            if ($result === FALSE) {
                echo '<p class="error">❌ Failed to connect to Firebase</p>';
                if ($http_code > 0) {
                    echo '<p class="error">HTTP Status: ' . $http_code . ' - ' . htmlspecialchars($http_message) . '</p>';
                }
                echo '<p class="warning">Possible causes:</p>';
                echo '<ul>';
                echo '<li>Database rules might be blocking public access</li>';
                echo '<li>Network/firewall issue</li>';
                echo '<li>Incorrect database URL</li>';
                echo '</ul>';
            } else {
                echo '<p class="success">✅ Successfully connected to Firebase!</p>';
                if ($http_code > 0) {
                    echo '<p class="info">HTTP Status: ' . $http_code . ' - ' . htmlspecialchars($http_message) . '</p>';
                }
                echo '<p class="info">Response length: ' . strlen($result) . ' bytes</p>';
                
                if (trim($result) === 'null' || $result === '') {
                    echo '<p class="warning">⚠️ Database returned null (might be empty or access denied)</p>';
                } else {
                    $decoded = json_decode($result, true);
                    if ($decoded === null) {
                        echo '<p class="error">❌ Failed to decode JSON response</p>';
                        echo '<p>Raw response (first 500 chars):</p>';
                        echo '<pre>' . htmlspecialchars(substr($result, 0, 500)) . '</pre>';
                    } else {
                        echo '<p class="success">✅ JSON decoded successfully</p>';
                        if (is_array($decoded)) {
                            echo '<p class="info">Root level keys: ' . (count($decoded) > 0 ? implode(', ', array_keys($decoded)) : 'None (empty object)') . '</p>';
                            if (count($decoded) > 0) {
                                echo '<p>Sample data structure:</p>';
                                echo '<pre>' . htmlspecialchars(json_encode(array_slice($decoded, 0, 2, true), JSON_PRETTY_PRINT)) . '</pre>';
                            }
                        } else {
                            echo '<p class="info">Data type: ' . gettype($decoded) . '</p>';
                        }
                    }
                }
            }
            ?>
        </div>

        <!-- Test All Data Paths -->
        <div class="card">
            <h2>3. Testing Data Paths</h2>
            <?php
            $paths = ['bookings', 'users', 'drivers', 'driver_approvals', 'ratings', 'driver_commissions'];
            
            echo '<table>';
            echo '<tr><th>Path</th><th>URL</th><th>Status</th><th>HTTP Code</th><th>Data Count</th><th>Sample Key</th></tr>';
            
            foreach ($paths as $path) {
                $testUrl = $firebase_database_url . $path . '.json';
                $context = stream_context_create([
                    'http' => [
                        'timeout' => 5,
                        'ignore_errors' => true
                    ]
                ]);
                
                $result = @file_get_contents($testUrl, false, $context);
                
                // Get HTTP response code
                $http_code = 0;
                if (isset($http_response_header) && !empty($http_response_header)) {
                    $status_line = $http_response_header[0];
                    preg_match('/HTTP\/\d\.\d\s+(\d+)/', $status_line, $matches);
                    if (isset($matches[1])) {
                        $http_code = intval($matches[1]);
                    }
                }
                
                echo '<tr>';
                echo '<td><code>' . htmlspecialchars($path) . '</code></td>';
                echo '<td><code style="font-size:10px;">' . htmlspecialchars($testUrl) . '</code></td>';
                
                if ($result === FALSE) {
                    echo '<td class="error">❌ Error</td>';
                    echo '<td>' . ($http_code > 0 ? $http_code : 'N/A') . '</td>';
                    echo '<td>-</td>';
                    echo '<td>-</td>';
                } elseif (trim($result) === 'null' || $result === '') {
                    echo '<td class="warning">⚠️ Empty/Null</td>';
                    echo '<td>' . ($http_code > 0 ? $http_code : 'N/A') . '</td>';
                    echo '<td>0</td>';
                    echo '<td>-</td>';
                } else {
                    $data = json_decode($result, true);
                    if ($data === null) {
                        echo '<td class="error">❌ Invalid JSON</td>';
                        echo '<td>' . ($http_code > 0 ? $http_code : 'N/A') . '</td>';
                        echo '<td>-</td>';
                        echo '<td>-</td>';
                    } else {
                        $count = is_array($data) ? count($data) : 0;
                        if ($count > 0) {
                            echo '<td class="success">✅ Found</td>';
                            echo '<td class="success">' . ($http_code > 0 ? $http_code : '200') . '</td>';
                            echo '<td>' . $count . '</td>';
                            // Show sample key
                            if (is_array($data)) {
                                $sampleKey = array_key_first($data);
                                echo '<td><code>' . htmlspecialchars(substr($sampleKey, 0, 30)) . '</code></td>';
                            } else {
                                echo '<td>-</td>';
                            }
                        } else {
                            echo '<td class="warning">⚠️ Empty</td>';
                            echo '<td>' . ($http_code > 0 ? $http_code : '200') . '</td>';
                            echo '<td>0</td>';
                            echo '<td>-</td>';
                        }
                    }
                }
                
                echo '</tr>';
            }
            
            echo '</table>';
            ?>
        </div>

        <!-- Database Rules Info -->
        <div class="card">
            <h2>4. Database Rules Information</h2>
            <p class="warning"><strong>Important:</strong> If you see "Empty/Null" or HTTP 403/401 errors, check your Firebase Database Rules:</p>
            <ol>
                <li>Go to <a href="https://console.firebase.google.com/project/drive-ab344/database/drive-ab344-default-rtdb/rules" target="_blank">Firebase Console → Realtime Database → Rules</a></li>
                <li>For testing, temporarily allow public read access:</li>
            </ol>
            <pre>{
  "rules": {
    ".read": true,
    ".write": true
  }
}</pre>
            <p class="warning">⚠️ <strong>Warning:</strong> The above rules allow public read/write. For production, use proper authentication!</p>
            <p class="info">After updating rules, wait 1-2 minutes for changes to propagate, then refresh this page.</p>
        </div>

        <!-- Detailed Data Inspection -->
        <div class="card">
            <h2>5. Detailed Data Inspection</h2>
            <?php
            // Test bookings specifically
            $bookingsData = getFirebaseData('bookings');
            echo '<h3>Bookings Data:</h3>';
            
            if ($bookingsData === null) {
                echo '<p class="error">❌ Bookings data is NULL</p>';
            } elseif (!is_array($bookingsData)) {
                echo '<p class="error">❌ Bookings data is not an array. Type: ' . gettype($bookingsData) . '</p>';
                echo '<p>Raw value: <pre>' . htmlspecialchars(print_r($bookingsData, true)) . '</pre></p>';
            } elseif (empty($bookingsData)) {
                echo '<p class="warning">⚠️ Bookings array is empty</p>';
                echo '<p class="info">This means either:</p>';
                echo '<ul>';
                echo '<li>No bookings exist in Firebase</li>';
                echo '<li>Database rules are blocking access</li>';
                echo '<li>Data is in a different path</li>';
                echo '</ul>';
            } else {
                echo '<p class="success">✅ Found ' . count($bookingsData) . ' bookings</p>';
                
                // Show first booking structure
                $firstKey = array_key_first($bookingsData);
                $firstBooking = $bookingsData[$firstKey];
                
                echo '<h4>First Booking Structure:</h4>';
                echo '<p>Key: <code>' . htmlspecialchars($firstKey) . '</code></p>';
                echo '<pre>' . htmlspecialchars(json_encode($firstBooking, JSON_PRETTY_PRINT)) . '</pre>';
            }
            
            // Test users
            $usersData = getFirebaseData('users');
            echo '<h3>Users Data:</h3>';
            if (empty($usersData)) {
                echo '<p class="warning">⚠️ Users array is empty</p>';
            } else {
                echo '<p class="success">✅ Found ' . count($usersData) . ' users</p>';
            }
            
            // Test drivers
            $driversData = getFirebaseData('drivers');
            echo '<h3>Drivers Data:</h3>';
            if (empty($driversData)) {
                echo '<p class="warning">⚠️ Drivers array is empty</p>';
            } else {
                echo '<p class="success">✅ Found ' . count($driversData) . ' drivers</p>';
            }
            ?>
        </div>

        <!-- Recommendations -->
        <div class="card">
            <h2>6. Next Steps</h2>
            <?php
            $bookingsData = getFirebaseData('bookings');
            $hasData = !empty($bookingsData) && is_array($bookingsData) && count($bookingsData) > 0;
            
            if (!$hasData) {
                echo '<div class="warning">';
                echo '<h3>No data found. Follow these steps:</h3>';
                echo '<ol>';
                echo '<li><strong>Check Firebase Database Rules:</strong>';
                echo '<ul>';
                echo '<li>Go to <a href="https://console.firebase.google.com/project/drive-ab344/database/drive-ab344-default-rtdb/rules" target="_blank">Firebase Console → Rules</a></li>';
                echo '<li>If you see HTTP 403 errors above, rules are blocking access</li>';
                echo '<li>Temporarily set rules to allow public read for testing</li>';
                echo '</ul>';
                echo '</li>';
                echo '<li><strong>Verify Data Exists:</strong>';
                echo '<ul>';
                echo '<li>Check <a href="https://console.firebase.google.com/project/drive-ab344/database/drive-ab344-default-rtdb/data" target="_blank">Firebase Console → Data</a></li>';
                echo '<li>Make sure you see data under <code>bookings</code>, <code>users</code>, etc.</li>';
                echo '</ul>';
                echo '</li>';
                echo '<li><strong>Check Database URL:</strong>';
                echo '<ul>';
                echo '<li>Current URL: <code>' . htmlspecialchars($firebase_database_url) . '</code></li>';
                echo '<li>Should match the URL shown in Firebase Console</li>';
                echo '</ul>';
                echo '</li>';
                echo '</ol>';
                echo '</div>';
            } else {
                echo '<p class="success">✅ Data is accessible! If the dashboard still shows empty, check that the data structure matches what the code expects.</p>';
                echo '<p><a href="dashboard.php" class="btn">Go to Dashboard</a></p>';
            }
            ?>
        </div>
    </div>
</body>
</html>


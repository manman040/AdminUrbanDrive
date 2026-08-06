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
    <title>Check Driver Data - Urban Drive</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f6fa; }
        .container { max-width: 1400px; margin: 0 auto; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 11px; max-height: 600px; overflow-y: auto; }
        h2 { margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; font-size: 12px; }
        th { background: #f8f9fa; }
        .btn { background: #ff6b35; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin-top: 10px; }
        .btn:hover { background: #e55a2b; }
        .field-found { color: green; font-weight: bold; }
        .field-not-found { color: red; }
        .path-value { max-width: 400px; word-break: break-all; font-size: 11px; }
        .all-fields { background: #fff3cd; padding: 10px; border-radius: 5px; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Check Driver Application Data Structure</h1>
        <p><a href="driver-requests.php" class="btn">← Back to Driver Requests</a></p>

        <?php
        try {
            $applications = getDriverApplications();
            
            if (empty($applications) || !is_array($applications)) {
                echo '<div class="card"><p>No applications found</p></div>';
            } else {
                echo '<div class="card">';
                echo '<h2>Found ' . count($applications) . ' Applications</h2>';
                echo '<p>Checking ALL fields in each application to find image paths...</p>';
                echo '</div>';
                
                $count = 0;
                foreach ($applications as $appId => $app) {
                    if ($count >= 5) break; // Show first 5
                    if (!is_array($app)) continue;
                    
                    echo '<div class="card">';
                    echo '<h2>Application ID: ' . htmlspecialchars($appId) . '</h2>';
                    echo '<p><strong>Driver Name:</strong> ' . htmlspecialchars($app['name'] ?? 'N/A') . '</p>';
                    echo '<p><strong>Driver ID:</strong> ' . htmlspecialchars($app['driverId'] ?? $appId) . '</p>';
                    
                    // Check for ALL fields that might contain image paths
                    echo '<h3>Image Path Fields Found:</h3>';
                    echo '<table>';
                    echo '<tr><th>Field Name</th><th>Value</th><th>Looks like path?</th></tr>';
                    
                    // Get ALL fields from the application using the function from firebase-config.php
                    $allFields = getAllFieldsRecursive($app);
                    
                    // Check each field for image-like content
                    $imageFields = [];
                    foreach ($allFields as $fieldName => $value) {
                        if (is_string($value) && !empty($value)) {
                            $looksLikePath = false;
                            $reasons = [];
                            
                            // Check if it looks like an image path/URL
                            if (stripos($value, 'http') !== false) {
                                $looksLikePath = true;
                                $reasons[] = 'Contains http';
                            }
                            if (stripos($value, 'gs://') !== false) {
                                $looksLikePath = true;
                                $reasons[] = 'Contains gs://';
                            }
                            if (stripos($value, '.jpg') !== false || stripos($value, '.png') !== false || stripos($value, '.jpeg') !== false) {
                                $looksLikePath = true;
                                $reasons[] = 'Contains image extension';
                            }
                            if (stripos($value, '/') !== false && (stripos($fieldName, 'image') !== false || stripos($fieldName, 'photo') !== false || stripos($fieldName, 'url') !== false || stripos($fieldName, 'path') !== false)) {
                                $looksLikePath = true;
                                $reasons[] = 'Contains / and field name suggests image';
                            }
                            if (stripos($fieldName, 'idCard') !== false || stripos($fieldName, 'license') !== false || stripos($fieldName, 'grant') !== false || stripos($fieldName, 'car') !== false) {
                                if (stripos($value, '/') !== false || stripos($value, 'http') !== false || stripos($value, 'gs://') !== false) {
                                    $looksLikePath = true;
                                    $reasons[] = 'Field name suggests document type';
                                }
                            }
                            
                            if ($looksLikePath) {
                                $imageFields[$fieldName] = [
                                    'value' => $value,
                                    'reasons' => $reasons
                                ];
                                
                                echo '<tr>';
                                echo '<td class="field-found">' . htmlspecialchars($fieldName) . '</td>';
                                echo '<td class="path-value">' . htmlspecialchars(substr($value, 0, 200)) . (strlen($value) > 200 ? '...' : '') . '</td>';
                                echo '<td>' . implode(', ', $reasons) . '</td>';
                                echo '</tr>';
                            }
                        }
                    }
                    
                    if (empty($imageFields)) {
                        echo '<tr><td colspan="3" class="field-not-found">❌ No image paths found in any fields</td></tr>';
                    }
                    
                    echo '</table>';
                    
                    // Show ALL fields for debugging (always expanded to see the issue)
                    echo '<h3 style="margin-top: 20px;">📋 ALL Fields in Application (JSON):</h3>';
                    echo '<div class="all-fields">';
                    echo '<pre>' . htmlspecialchars(json_encode($app, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) . '</pre>';
                    echo '</div>';
                    
                    // Also show a summary table of all fields
                    echo '<h3>📊 All Fields Summary:</h3>';
                    echo '<table>';
                    echo '<tr><th>Field Name</th><th>Type</th><th>Value Preview (first 100 chars)</th></tr>';
                    foreach ($allFields as $fieldName => $value) {
                        $type = gettype($value);
                        $preview = is_string($value) ? htmlspecialchars(substr($value, 0, 100)) : (is_array($value) ? '[Array]' : htmlspecialchars((string)$value));
                        echo '<tr>';
                        echo '<td>' . htmlspecialchars($fieldName) . '</td>';
                        echo '<td>' . htmlspecialchars($type) . '</td>';
                        echo '<td>' . $preview . '</td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                    
                    echo '</div>';
                    $count++;
                }
            }
        } catch (Exception $e) {
            echo '<div class="card"><p class="error">Error: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
        }
        ?>
    </div>
</body>
</html>


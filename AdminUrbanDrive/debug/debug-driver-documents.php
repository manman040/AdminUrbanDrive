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
    <title>Debug Driver Documents - Urban Drive</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f6fa; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 20px; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; font-size: 12px; }
        h2 { margin-top: 0; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f8f9fa; }
        .btn { background: #ff6b35; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; text-decoration: none; display: inline-block; margin-top: 10px; }
        .btn:hover { background: #e55a2b; }
        .field-found { color: green; font-weight: bold; }
        .field-not-found { color: red; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Debug Driver Documents Structure</h1>
        <p><a href="driver-requests.php" class="btn">← Back to Driver Requests</a></p>

        <?php
        try {
            $applications = getDriverApplications();
            
            if (empty($applications) || !is_array($applications)) {
                echo '<div class="card"><p>No applications found</p></div>';
            } else {
                echo '<div class="card">';
                echo '<h2>Found ' . count($applications) . ' Applications</h2>';
                echo '<p>Checking document field structures...</p>';
                echo '</div>';
                
                $count = 0;
                foreach ($applications as $appId => $app) {
                    if ($count >= 3) break; // Show first 3
                    if (!is_array($app)) continue;
                    
                    echo '<div class="card">';
                    echo '<h2>Application: ' . htmlspecialchars($appId) . '</h2>';
                    echo '<p><strong>Driver Name:</strong> ' . htmlspecialchars($app['name'] ?? 'N/A') . '</p>';
                    
                    // Check for documents structure
                    echo '<h3>Document Fields Check:</h3>';
                    echo '<table>';
                    echo '<tr><th>Field Name</th><th>Found</th><th>Value (first 100 chars)</th></tr>';
                    
                    // ID Card
                    $idCardFields = [
                        'idCard', 'idCardUrl', 'id_card', 'id_card_url',
                        'documents.idCard', 'documents.id_card', 'documents.idCardUrl'
                    ];
                    $idCardFound = false;
                    $idCardValue = '';
                    foreach ($idCardFields as $field) {
                        if (strpos($field, '.') !== false) {
                            $parts = explode('.', $field);
                            if (isset($app[$parts[0]]) && is_array($app[$parts[0]]) && isset($app[$parts[0]][$parts[1]])) {
                                $idCardFound = true;
                                $idCardValue = $app[$parts[0]][$parts[1]];
                                break;
                            }
                        } elseif (isset($app[$field]) && !empty($app[$field])) {
                            $idCardFound = true;
                            $idCardValue = $app[$field];
                            break;
                        }
                    }
                    echo '<tr>';
                    echo '<td>ID Card</td>';
                    echo '<td class="' . ($idCardFound ? 'field-found' : 'field-not-found') . '">' . ($idCardFound ? '✅ Found' : '❌ Not Found') . '</td>';
                    echo '<td>' . htmlspecialchars(substr($idCardValue, 0, 100)) . '</td>';
                    echo '</tr>';
                    
                    // Driving License
                    $licenseFields = [
                        'drivingLicense', 'drivingLicenseUrl', 'driving_license', 'driving_license_url',
                        'license', 'licenseUrl', 'license_url',
                        'documents.drivingLicense', 'documents.driving_license', 'documents.license'
                    ];
                    $licenseFound = false;
                    $licenseValue = '';
                    foreach ($licenseFields as $field) {
                        if (strpos($field, '.') !== false) {
                            $parts = explode('.', $field);
                            if (isset($app[$parts[0]]) && is_array($app[$parts[0]]) && isset($app[$parts[0]][$parts[1]])) {
                                $licenseFound = true;
                                $licenseValue = $app[$parts[0]][$parts[1]];
                                break;
                            }
                        } elseif (isset($app[$field]) && !empty($app[$field])) {
                            $licenseFound = true;
                            $licenseValue = $app[$field];
                            break;
                        }
                    }
                    echo '<tr>';
                    echo '<td>Driving License</td>';
                    echo '<td class="' . ($licenseFound ? 'field-found' : 'field-not-found') . '">' . ($licenseFound ? '✅ Found' : '❌ Not Found') . '</td>';
                    echo '<td>' . htmlspecialchars(substr($licenseValue, 0, 100)) . '</td>';
                    echo '</tr>';
                    
                    // Car Grant
                    $grantFields = [
                        'carGrant', 'carGrantUrl', 'car_grant', 'car_grant_url',
                        'grant', 'grantUrl', 'grant_url',
                        'documents.carGrant', 'documents.car_grant', 'documents.grant'
                    ];
                    $grantFound = false;
                    $grantValue = '';
                    foreach ($grantFields as $field) {
                        if (strpos($field, '.') !== false) {
                            $parts = explode('.', $field);
                            if (isset($app[$parts[0]]) && is_array($app[$parts[0]]) && isset($app[$parts[0]][$parts[1]])) {
                                $grantFound = true;
                                $grantValue = $app[$parts[0]][$parts[1]];
                                break;
                            }
                        } elseif (isset($app[$field]) && !empty($app[$field])) {
                            $grantFound = true;
                            $grantValue = $app[$field];
                            break;
                        }
                    }
                    echo '<tr>';
                    echo '<td>Car Grant</td>';
                    echo '<td class="' . ($grantFound ? 'field-found' : 'field-not-found') . '">' . ($grantFound ? '✅ Found' : '❌ Not Found') . '</td>';
                    echo '<td>' . htmlspecialchars(substr($grantValue, 0, 100)) . '</td>';
                    echo '</tr>';
                    
                    // Car Image
                    $imageFields = [
                        'carImage', 'carImageUrl', 'car_image', 'car_image_url',
                        'vehicleImage', 'vehicleImageUrl', 'vehicle_image', 'vehicle_image_url',
                        'documents.carImage', 'documents.car_image', 'documents.vehicleImage'
                    ];
                    $imageFound = false;
                    $imageValue = '';
                    foreach ($imageFields as $field) {
                        if (strpos($field, '.') !== false) {
                            $parts = explode('.', $field);
                            if (isset($app[$parts[0]]) && is_array($app[$parts[0]]) && isset($app[$parts[0]][$parts[1]])) {
                                $imageFound = true;
                                $imageValue = $app[$parts[0]][$parts[1]];
                                break;
                            }
                        } elseif (isset($app[$field]) && !empty($app[$field])) {
                            $imageFound = true;
                            $imageValue = $app[$field];
                            break;
                        }
                    }
                    echo '<tr>';
                    echo '<td>Car Image</td>';
                    echo '<td class="' . ($imageFound ? 'field-found' : 'field-not-found') . '">' . ($imageFound ? '✅ Found' : '❌ Not Found') . '</td>';
                    echo '<td>' . htmlspecialchars(substr($imageValue, 0, 100)) . '</td>';
                    echo '</tr>';
                    
                    echo '</table>';
                    
                    // Show full structure
                    echo '<h3>Full Application Data Structure:</h3>';
                    echo '<pre>' . htmlspecialchars(json_encode($app, JSON_PRETTY_PRINT)) . '</pre>';
                    
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


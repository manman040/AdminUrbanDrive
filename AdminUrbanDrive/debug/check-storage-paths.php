<?php
/**
 * Check Storage Paths Tool
 * This tool helps identify the correct file paths in Firebase Storage
 * by checking common path patterns for driver documents
 */

require_once 'firebase-config.php';
require_once 'auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// Get driver applications
$applications = getFirebaseData('driver_approvals');

if (!is_array($applications)) {
    $applications = [];
}

// Get first few applications for testing
$testApplications = array_slice($applications, 0, 3, true);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check Storage Paths - Admin Panel</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            padding: 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        h1 {
            color: #333;
            margin-bottom: 20px;
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #007bff;
            text-decoration: none;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .driver-card {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            background: #f9f9f9;
        }
        
        .driver-card h2 {
            color: #333;
            margin-bottom: 15px;
        }
        
        .driver-info {
            margin-bottom: 15px;
        }
        
        .driver-info strong {
            display: inline-block;
            width: 150px;
        }
        
        .path-test {
            margin-top: 15px;
            padding: 15px;
            background: white;
            border-radius: 4px;
            border-left: 4px solid #007bff;
        }
        
        .path-test h3 {
            color: #333;
            margin-bottom: 10px;
            font-size: 16px;
        }
        
        .path-list {
            list-style: none;
            margin: 10px 0;
        }
        
        .path-list li {
            padding: 8px;
            margin: 5px 0;
            background: #f0f0f0;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            word-break: break-all;
        }
        
        .test-button {
            background: #007bff;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }
        
        .test-button:hover {
            background: #0056b3;
        }
        
        .test-result {
            margin-top: 10px;
            padding: 10px;
            border-radius: 4px;
            display: none;
        }
        
        .test-result.success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            display: block;
        }
        
        .test-result.error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            display: block;
        }
        
        .test-result.loading {
            background: #d1ecf1;
            border: 1px solid #bee5eb;
            color: #0c5460;
            display: block;
        }
        
        .image-preview {
            max-width: 300px;
            max-height: 300px;
            margin-top: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="driver-requests.php" class="back-link">← Back to Driver Requests</a>
        <h1>Check Storage Paths</h1>
        <p>This tool helps identify the correct file paths for driver documents in Firebase Storage.</p>
        
        <?php if (empty($testApplications)): ?>
            <p>No driver applications found.</p>
        <?php else: ?>
            <?php foreach ($testApplications as $appId => $app): ?>
                <?php if (!is_array($app)) continue; ?>
                <div class="driver-card">
                    <h2><?php echo htmlspecialchars($app['name'] ?? 'Unknown'); ?> (<?php echo htmlspecialchars($appId); ?>)</h2>
                    
                    <div class="driver-info">
                        <strong>Driver ID:</strong> <?php echo htmlspecialchars($app['driverId'] ?? $appId); ?><br>
                        <strong>ID Card:</strong> <?php echo htmlspecialchars($app['idCard'] ?? 'N/A'); ?><br>
                        <strong>Email:</strong> <?php echo htmlspecialchars($app['email'] ?? 'N/A'); ?><br>
                        <strong>Status:</strong> <?php echo htmlspecialchars($app['status'] ?? 'pending'); ?>
                    </div>
                    
                    <div class="path-test">
                        <h3>ID Card Paths</h3>
                        <ul class="path-list" id="idCardPaths_<?php echo htmlspecialchars($appId); ?>">
                            <?php
                            $driverId = $app['driverId'] ?? $appId;
                            $idCardNumber = $app['idCard'] ?? '';
                            $possiblePaths = [];
                            
                            if (!empty($idCardNumber) && !empty($driverId)) {
                                $possiblePaths = [
                                    "ic_photos/{$driverId}/{$idCardNumber}.jpg",
                                    "ic_photos/{$driverId}/{$idCardNumber}.png",
                                    "ic_photos/{$driverId}/{$idCardNumber}",
                                    "ic_photos/{$idCardNumber}.jpg",
                                    "ic_photos/{$idCardNumber}.png",
                                    "ic_photos/{$idCardNumber}",
                                    "ic_photos/{$driverId}/id_card.jpg",
                                    "ic_photos/{$driverId}/id_card.png"
                                ];
                            }
                            
                            foreach ($possiblePaths as $path) {
                                echo '<li>' . htmlspecialchars($path) . '</li>';
                            }
                            ?>
                        </ul>
                        <button class="test-button" onclick="testPaths('idCard', '<?php echo htmlspecialchars($driverId); ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', '<?php echo htmlspecialchars($appId); ?>')">
                            Test ID Card Paths
                        </button>
                        <div class="test-result" id="idCardResult_<?php echo htmlspecialchars($appId); ?>"></div>
                    </div>
                    
                    <div class="path-test">
                        <h3>Driving License Paths</h3>
                        <ul class="path-list" id="licensePaths_<?php echo htmlspecialchars($appId); ?>">
                            <?php
                            $licensePaths = [
                                "license_photos/{$driverId}/driving_license.jpg",
                                "license_photos/{$driverId}/license.jpg",
                                "license_photos/{$driverId}/driving_license.png",
                                "license_photos/{$driverId}/license.png",
                                "license_photos/{$driverId}.jpg",
                                "license_photos/{$driverId}.png"
                            ];
                            
                            foreach ($licensePaths as $path) {
                                echo '<li>' . htmlspecialchars($path) . '</li>';
                            }
                            ?>
                        </ul>
                        <button class="test-button" onclick="testPaths('license', '<?php echo htmlspecialchars($driverId); ?>', '', '<?php echo htmlspecialchars($appId); ?>')">
                            Test License Paths
                        </button>
                        <div class="test-result" id="licenseResult_<?php echo htmlspecialchars($appId); ?>"></div>
                    </div>
                    
                    <div class="path-test">
                        <h3>Car Grant Paths</h3>
                        <ul class="path-list" id="carGrantPaths_<?php echo htmlspecialchars($appId); ?>">
                            <?php
                            $carGrantPaths = [
                                "grant_photos/{$driverId}/car_grant.jpg",
                                "grant_photos/{$driverId}/grant.jpg",
                                "grant_photos/{$driverId}/car_grant.png",
                                "grant_photos/{$driverId}/grant.png",
                                "grant_photos/{$driverId}.jpg",
                                "grant_photos/{$driverId}.png"
                            ];
                            
                            foreach ($carGrantPaths as $path) {
                                echo '<li>' . htmlspecialchars($path) . '</li>';
                            }
                            ?>
                        </ul>
                        <button class="test-button" onclick="testPaths('carGrant', '<?php echo htmlspecialchars($driverId); ?>', '', '<?php echo htmlspecialchars($appId); ?>')">
                            Test Car Grant Paths
                        </button>
                        <div class="test-result" id="carGrantResult_<?php echo htmlspecialchars($appId); ?>"></div>
                    </div>
                    
                    <div class="path-test">
                        <h3>Car Image Paths</h3>
                        <ul class="path-list" id="carImagePaths_<?php echo htmlspecialchars($appId); ?>">
                            <?php
                            $carImagePaths = [
                                "car_photos/{$driverId}/car_image.jpg",
                                "car_photos/{$driverId}/car.jpg",
                                "car_photos/{$driverId}/vehicle.jpg",
                                "car_photos/{$driverId}/car_image.png",
                                "car_photos/{$driverId}/car.png",
                                "car_photos/{$driverId}.jpg",
                                "car_photos/{$driverId}.png"
                            ];
                            
                            foreach ($carImagePaths as $path) {
                                echo '<li>' . htmlspecialchars($path) . '</li>';
                            }
                            ?>
                        </ul>
                        <button class="test-button" onclick="testPaths('carImage', '<?php echo htmlspecialchars($driverId); ?>', '', '<?php echo htmlspecialchars($appId); ?>')">
                            Test Car Image Paths
                        </button>
                        <div class="test-result" id="carImageResult_<?php echo htmlspecialchars($appId); ?>"></div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <script>
        const FIREBASE_STORAGE_URL = 'https://firebasestorage.googleapis.com/v0/b/drive-ab344.firebasestorage.app/o/';
        
        function encodePath(path) {
            return path.split('/').map(segment => encodeURIComponent(segment)).join('/');
        }
        
        function buildStorageUrl(path) {
            return FIREBASE_STORAGE_URL + encodePath(path) + '?alt=media';
        }
        
        function testPaths(docType, driverId, idCardNumber, appId) {
            const resultDiv = document.getElementById(docType + 'Result_' + appId);
            resultDiv.className = 'test-result loading';
            resultDiv.innerHTML = 'Testing paths...';
            
            let possiblePaths = [];
            
            if (docType === 'idCard' && idCardNumber) {
                possiblePaths = [
                    `ic_photos/${driverId}/${idCardNumber}.jpg`,
                    `ic_photos/${driverId}/${idCardNumber}.png`,
                    `ic_photos/${driverId}/${idCardNumber}`,
                    `ic_photos/${idCardNumber}.jpg`,
                    `ic_photos/${idCardNumber}.png`,
                    `ic_photos/${idCardNumber}`,
                    `ic_photos/${driverId}/id_card.jpg`,
                    `ic_photos/${driverId}/id_card.png`
                ];
            } else if (docType === 'license') {
                possiblePaths = [
                    `license_photos/${driverId}/driving_license.jpg`,
                    `license_photos/${driverId}/license.jpg`,
                    `license_photos/${driverId}/driving_license.png`,
                    `license_photos/${driverId}/license.png`,
                    `license_photos/${driverId}.jpg`,
                    `license_photos/${driverId}.png`
                ];
            } else if (docType === 'carGrant') {
                possiblePaths = [
                    `grant_photos/${driverId}/car_grant.jpg`,
                    `grant_photos/${driverId}/grant.jpg`,
                    `grant_photos/${driverId}/car_grant.png`,
                    `grant_photos/${driverId}/grant.png`,
                    `grant_photos/${driverId}.jpg`,
                    `grant_photos/${driverId}.png`
                ];
            } else if (docType === 'carImage') {
                possiblePaths = [
                    `car_photos/${driverId}/car_image.jpg`,
                    `car_photos/${driverId}/car.jpg`,
                    `car_photos/${driverId}/vehicle.jpg`,
                    `car_photos/${driverId}/car_image.png`,
                    `car_photos/${driverId}/car.png`,
                    `car_photos/${driverId}.jpg`,
                    `car_photos/${driverId}.png`
                ];
            }
            
            let currentIndex = 0;
            const tryNextPath = () => {
                if (currentIndex >= possiblePaths.length) {
                    resultDiv.className = 'test-result error';
                    resultDiv.innerHTML = '<strong>All paths failed!</strong><br>None of the ' + possiblePaths.length + ' paths worked. Please check:<br>' +
                        '1. Files exist in Firebase Storage<br>' +
                        '2. Storage rules allow read access<br>' +
                        '3. Actual file paths in Firebase Console';
                    return;
                }
                
                const path = possiblePaths[currentIndex];
                const imageUrl = buildStorageUrl(path);
                
                resultDiv.innerHTML = `Testing path ${currentIndex + 1}/${possiblePaths.length}:<br><code>${path}</code>`;
                
                const img = new Image();
                img.onload = function() {
                    resultDiv.className = 'test-result success';
                    resultDiv.innerHTML = '<strong>✅ Success!</strong><br>' +
                        'Working path: <code>' + path + '</code><br>' +
                        'URL: <code style="font-size: 10px; word-break: break-all;">' + imageUrl + '</code><br>' +
                        '<img src="' + imageUrl + '" class="image-preview" alt="Preview">';
                };
                img.onerror = function() {
                    currentIndex++;
                    setTimeout(tryNextPath, 200);
                };
                img.src = imageUrl;
            };
            
            tryNextPath();
        }
    </script>
</body>
</html>


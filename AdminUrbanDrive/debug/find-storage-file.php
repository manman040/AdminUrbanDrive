<?php
/**
 * Find Storage File API
 * This endpoint tries to find files in Firebase Storage by attempting multiple path patterns
 */

require_once 'firebase-config.php';
require_once 'auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Get parameters
$driverId = $_GET['driverId'] ?? '';
$docType = $_GET['docType'] ?? 'carImage'; // idCard, license, carGrant, carImage
$idCardNumber = $_GET['idCardNumber'] ?? '';

if (empty($driverId)) {
    http_response_code(400);
    echo json_encode(['error' => 'driverId is required']);
    exit;
}

// Generate possible paths based on document type
$possiblePaths = [];

// Get driver application data to find submittedAt timestamp
$applications = getFirebaseData('driver_approvals');
$submittedTimestamp = null;

if (is_array($applications)) {
    // Try to find application by driverId (might be the key or in driverId field)
    $app = null;
    if (isset($applications[$driverId])) {
        $app = $applications[$driverId];
    } else {
        // Search through applications to find matching driverId
        foreach ($applications as $appId => $appData) {
            if (is_array($appData) && isset($appData['driverId']) && $appData['driverId'] === $driverId) {
                $app = $appData;
                break;
            }
        }
    }
    
    if ($app && is_array($app)) {
        if (isset($app['submittedAt']['time'])) {
            $submittedTimestamp = $app['submittedAt']['time'];
        } elseif (isset($app['submittedAt']) && is_numeric($app['submittedAt'])) {
            $submittedTimestamp = $app['submittedAt'];
        }
    }
}

// Generate timestamps to try (prioritize submittedAt if available)
$timestampsToTry = [];
if ($submittedTimestamp) {
    // Try exact timestamp first
    $timestampsToTry[] = $submittedTimestamp;
    
    // Try ±7 days around submission (most likely range)
    for ($i = -7; $i <= 7; $i++) {
        $ts = $submittedTimestamp + ($i * 86400000); // ±7 days
        if (!in_array($ts, $timestampsToTry)) {
            $timestampsToTry[] = $ts;
        }
    }
    
    // Try ±24 hours in smaller increments
    for ($i = -24; $i <= 24; $i++) {
        $ts = $submittedTimestamp + ($i * 3600000); // ±24 hours
        if (!in_array($ts, $timestampsToTry)) {
            $timestampsToTry[] = $ts;
        }
    }
}

// Also add current timestamp and go back 180 days
$currentTimestamp = time() * 1000;
$oneDay = 86400000;
for ($i = 0; $i < 180; $i += 7) { // Every 7 days for 180 days
    $ts = $currentTimestamp - ($i * $oneDay);
    if (!in_array($ts, $timestampsToTry)) {
        $timestampsToTry[] = $ts;
    }
}

// Limit to 100 most relevant timestamps
$timestampsToTry = array_slice($timestampsToTry, 0, 100);

if ($docType === 'idCard' && !empty($idCardNumber)) {
    // ID Card format: ic_photos/{driverId}_ic_{timestamp}.jpg or ic_photos/{driverId}_{idCardNumber}.jpg
    foreach ($timestampsToTry as $ts) {
        $possiblePaths[] = "ic_photos/{$driverId}_ic_{$ts}.jpg";
        $possiblePaths[] = "ic_photos/{$driverId}_ic_{$ts}.png";
    }
    // Also try with ID card number
    $possiblePaths[] = "ic_photos/{$driverId}_{$idCardNumber}.jpg";
    $possiblePaths[] = "ic_photos/{$driverId}_{$idCardNumber}.png";
    $possiblePaths[] = "ic_photos/{$driverId}_ic_{$idCardNumber}.jpg";
    // Try without timestamp
    $possiblePaths[] = "ic_photos/{$driverId}_ic.jpg";
    $possiblePaths[] = "ic_photos/{$driverId}_ic.png";
} else if ($docType === 'license') {
    // License format: license_photos/{driverId}_license_{timestamp}.jpg
    foreach ($timestampsToTry as $ts) {
        $possiblePaths[] = "license_photos/{$driverId}_license_{$ts}.jpg";
        $possiblePaths[] = "license_photos/{$driverId}_license_{$ts}.png";
        $possiblePaths[] = "license_photos/{$driverId}_driving_license_{$ts}.jpg";
        $possiblePaths[] = "license_photos/{$driverId}_driving_license_{$ts}.png";
    }
    // Try without timestamp
    $possiblePaths[] = "license_photos/{$driverId}_license.jpg";
    $possiblePaths[] = "license_photos/{$driverId}_license.png";
    $possiblePaths[] = "license_photos/{$driverId}_driving_license.jpg";
} else if ($docType === 'carGrant') {
    // Car Grant format: grant_photos/{driverId}_grant_{timestamp}.jpg
    foreach ($timestampsToTry as $ts) {
        $possiblePaths[] = "grant_photos/{$driverId}_grant_{$ts}.jpg";
        $possiblePaths[] = "grant_photos/{$driverId}_grant_{$ts}.png";
        $possiblePaths[] = "grant_photos/{$driverId}_car_grant_{$ts}.jpg";
        $possiblePaths[] = "grant_photos/{$driverId}_car_grant_{$ts}.png";
    }
    // Try without timestamp
    $possiblePaths[] = "grant_photos/{$driverId}_grant.jpg";
    $possiblePaths[] = "grant_photos/{$driverId}_grant.png";
    $possiblePaths[] = "grant_photos/{$driverId}_car_grant.jpg";
} else if ($docType === 'carImage') {
    // Car Image format: car_photos/{driverId}_car_{timestamp}.jpg (confirmed from screenshot)
    foreach ($timestampsToTry as $ts) {
        $possiblePaths[] = "car_photos/{$driverId}_car_{$ts}.jpg";
        $possiblePaths[] = "car_photos/{$driverId}_car_{$ts}.png";
    }
    // Try without timestamp (fallback)
    $possiblePaths[] = "car_photos/{$driverId}_car.jpg";
    $possiblePaths[] = "car_photos/{$driverId}_car.png";
}

// Try to check if file exists by making HEAD request
$foundPath = null;
$foundUrl = null;

// Limit number of paths to try (prioritize most likely ones)
// For timestamp-based files, try up to 200 paths (more timestamps = higher chance of finding)
$maxPathsToTry = ($docType === 'carImage' || $docType === 'license' || $docType === 'carGrant') ? 200 : 100;
$pathsToTry = array_slice($possiblePaths, 0, $maxPathsToTry);

// Use multi-curl for faster checking (check multiple files in parallel)
$multiHandle = curl_multi_init();
$curlHandles = [];
$active = null;

// Create curl handles for first batch (10 at a time for parallel checking)
$batchSize = 10;
$currentBatch = 0;
$found = false;

while (!$found && ($currentBatch * $batchSize) < count($pathsToTry)) {
    $batch = array_slice($pathsToTry, $currentBatch * $batchSize, $batchSize);
    
    if (empty($batch)) {
        break;
    }
    
    // Create curl handles for this batch
    foreach ($batch as $index => $path) {
        $url = getFirebaseStorageUrl($path);
        if (!$url) {
            continue;
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_HEADER, false);
        
        curl_multi_add_handle($multiHandle, $ch);
        $curlHandles[$path] = $ch;
    }
    
    // Execute all handles in parallel
    do {
        $mrc = curl_multi_exec($multiHandle, $active);
    } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    
    while ($active && $mrc == CURLM_OK) {
        if (curl_multi_select($multiHandle) == -1) {
            usleep(100);
        }
        do {
            $mrc = curl_multi_exec($multiHandle, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
    }
    
    // Check results
    foreach ($curlHandles as $path => $ch) {
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($httpCode === 200) {
            $foundPath = $path;
            $foundUrl = getFirebaseStorageUrl($path);
            $found = true;
            break;
        }
        curl_multi_remove_handle($multiHandle, $ch);
        curl_close($ch);
    }
    
    $curlHandles = [];
    $currentBatch++;
    
    // If not found in this batch, continue to next batch
    if (!$found) {
        usleep(100000); // 100ms delay between batches
    }
}

// Clean up
foreach ($curlHandles as $ch) {
    curl_multi_remove_handle($multiHandle, $ch);
    curl_close($ch);
}
curl_multi_close($multiHandle);

// Fallback: if parallel checking didn't work, try sequential (slower but more reliable)
if (!$found) {
    foreach (array_slice($pathsToTry, 0, 50) as $index => $path) {
        $url = getFirebaseStorageUrl($path);
        if (!$url) {
            continue;
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_NOBODY, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200) {
            $foundPath = $path;
            $foundUrl = $url;
            break;
        }
        
        if ($index < 10) {
            usleep(50000); // 50ms delay for first 10
        }
    }
}

if ($foundPath) {
    echo json_encode([
        'success' => true,
        'path' => $foundPath,
        'url' => $foundUrl
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'File not found',
        'triedPaths' => $possiblePaths
    ]);
}


<?php
// Firebase Configuration
// IMPORTANT: You need to replace these placeholder values with your actual Firebase project credentials
// Get these values from your google-services.json file and Firebase Console

// Firebase project configuration
$firebase_config = [
    'apiKey' => 'AIzaSyA1m8DqpMjw2AoMcTxqA9fFa-6J-Ve0_U8', // From your google-services.json
    'authDomain' => 'drive-ab344.firebaseapp.com', // From your project ID
    'projectId' => 'drive-ab344', // From your google-services.json
    'storageBucket' => 'drive-ab344.firebasestorage.app', // From your google-services.json
    'messagingSenderId' => '650014399592', // From your google-services.json
    'appId' => '1:650014399592:android:8a254985e60505bd3bafdd' // From your google-services.json
];

// Firebase Realtime Database URL
$firebase_database_url = 'https://drive-ab344-default-rtdb.asia-southeast1.firebasedatabase.app/'; // From your google-services.json

// Firebase Storage URL
$firebase_storage_url = 'https://firebasestorage.googleapis.com/v0/b/drive-ab344.firebasestorage.app/o/';

// Check if Firebase is properly configured
function isFirebaseConfigured() {
    global $firebase_config, $firebase_database_url;
    
    // Check if placeholder values are still being used
    if (strpos($firebase_config['apiKey'], 'AIzaSyBqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXq') !== false ||
        strpos($firebase_database_url, 'your-project-id') !== false) {
        return false;
    }
    
    return true;
}

function firebaseTimestampValue($timestamp) {
    if (is_array($timestamp)) {
        return $timestamp['time'] ?? $timestamp['seconds'] ?? 0;
    }

    return $timestamp;
}

function firebaseTimestampToSeconds($timestamp) {
    $timestamp = firebaseTimestampValue($timestamp);

    if (!is_numeric($timestamp)) {
        return 0;
    }

    $timestamp = (float) $timestamp;

    // Firebase stores JavaScript timestamps in milliseconds.
    if ($timestamp > 10000000000) {
        return (int) floor($timestamp / 1000);
    }

    return (int) floor($timestamp);
}

function bookingFareAmount($booking) {
    if (!is_array($booking)) {
        return 0.0;
    }

    foreach (['estimatedFare', 'fare', 'totalFare', 'price', 'amount', 'totalFareAmount'] as $field) {
        if (isset($booking[$field]) && is_numeric($booking[$field])) {
            return (float) $booking[$field];
        }
    }

    if (isset($booking['fareLabel'])) {
        $cleaned = preg_replace('/[^0-9.]/', '', (string) $booking['fareLabel']);
        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }

    return 0.0;
}

function bookingDistanceKm($booking) {
    if (!is_array($booking)) {
        return 0.0;
    }

    foreach (['distanceKm', 'distance_km', 'tripDistance', 'distance'] as $field) {
        if (isset($booking[$field]) && is_numeric($booking[$field])) {
            return (float) $booking[$field];
        }
    }

    if (isset($booking['distanceLabel'])) {
        $cleaned = preg_replace('/[^0-9.]/', '', (string) $booking['distanceLabel']);
        return is_numeric($cleaned) ? (float) $cleaned : 0.0;
    }

    return 0.0;
}

function firstBookingValue($booking, $fields, $default = '') {
    if (!is_array($booking)) {
        return $default;
    }

    foreach ($fields as $field) {
        if (isset($booking[$field]) && $booking[$field] !== '') {
            return $booking[$field];
        }
    }

    return $default;
}

// Firebase Authentication using REST API
function authenticateWithFirebase($email, $password) {
    global $firebase_config;
    
    // Check if Firebase is configured
    if (!isFirebaseConfigured()) {
        error_log('Firebase not configured. Please update firebase-config.php with your actual Firebase credentials.');
        return false;
    }
    
    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=' . $firebase_config['apiKey'];
    $data = [
        'email' => $email,
        'password' => $password,
        'returnSecureToken' => true
    ];
    
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n",
            'method' => 'POST',
            'content' => json_encode($data),
            'timeout' => 10,
            'ignore_errors' => true // don't warn/abort on Firebase's 400 response for bad credentials -- we read the body ourselves below
        ]
    ];

    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);

    if ($result === FALSE) {
        error_log('Firebase authentication failed: Unable to connect to Firebase Auth API');
        return false;
    }

    $response = json_decode($result, true);

    if (isset($response['idToken'])) {
        // Store the token in session for future use
        $_SESSION['firebase_token'] = $response['idToken'];
        $_SESSION['firebase_user_id'] = $response['localId'];
        return true;
    }
    
    return false;
}

// Firebase Realtime Database functions using REST API
function getFirebaseData($path) {
    global $firebase_database_url;
    
    // Check if Firebase is configured
    if (!isFirebaseConfigured()) {
        error_log('Firebase not configured. Please update firebase-config.php with your actual Firebase credentials.');
        return [];
    }
    
    $url = $firebase_database_url . $path . '.json';
    
    // Add error handling for the HTTP request
    $context = stream_context_create([
        'http' => [
            'timeout' => 10, // 10 second timeout
            'ignore_errors' => true, // Don't throw errors on HTTP errors
            'method' => 'GET'
        ]
    ]);
    
    $result = @file_get_contents($url, false, $context);
    
    if ($result === FALSE) {
        // Try to get HTTP response code
        $http_response = isset($http_response_header) ? $http_response_header : [];
        $error_info = '';
        if (!empty($http_response)) {
            $status_line = $http_response[0];
            $error_info = ' - Response: ' . $status_line;
        }
        error_log('Failed to read data from Firebase: ' . $url . $error_info);
        return [];
    }
    
    // Check for HTTP errors in response headers
    if (isset($http_response_header)) {
        $status_line = $http_response_header[0];
        if (strpos($status_line, '200') === false && strpos($status_line, '201') === false) {
            error_log('Firebase HTTP error for path ' . $path . ': ' . $status_line);
            // Still try to parse the response in case it's an error message
        }
    }
    
    // Handle null response (Firebase returns "null" string when path doesn't exist)
    if (trim($result) === 'null' || $result === '') {
        error_log("Firebase path '$path' returned null or empty");
        return [];
    }
    
    $data = json_decode($result, true);
    
    // If json_decode fails, log and return empty array
    if ($data === null) {
        $jsonError = json_last_error();
        if ($jsonError !== JSON_ERROR_NONE) {
            error_log('JSON decode error for Firebase path ' . $path . ': ' . json_last_error_msg());
            return [];
        }
        // If json_decode returned null but no error, it might be valid null JSON
        // This shouldn't happen with our check above, but handle it anyway
        error_log("Firebase path '$path' decoded to null (no JSON error)");
        return [];
    }
    
    // If we got an error response from Firebase (like "Permission denied"), return it as-is
    // so the caller can detect and handle it
    if (is_array($data) && isset($data['error'])) {
        error_log("Firebase path '$path' returned error: " . $data['error']);
        return $data;
    }
    
    // Return the data if it's an array, otherwise return empty array
    if (!is_array($data)) {
        error_log("Firebase path '$path' returned non-array data: " . gettype($data));
        return [];
    }
    
    error_log("Firebase path '$path' returned " . count($data) . " items");
    return $data;
}

function setFirebaseData($path, $data) {
    global $firebase_database_url;
    
    // Check if Firebase is configured
    if (!isFirebaseConfigured()) {
        error_log('Firebase not configured. Please update firebase-config.php with your actual Firebase credentials.');
        return false;
    }
    
    $url = $firebase_database_url . $path . '.json';
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n",
            'method' => 'PUT',
            'content' => json_encode($data),
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return $result !== FALSE;
}

function updateFirebaseData($path, $data) {
    global $firebase_database_url;
    
    // Check if Firebase is configured
    if (!isFirebaseConfigured()) {
        error_log('Firebase not configured. Please update firebase-config.php with your actual Firebase credentials.');
        return false;
    }
    
    $url = $firebase_database_url . $path . '.json';
    $options = [
        'http' => [
            'header' => "Content-type: application/json\r\n",
            'method' => 'PATCH',
            'content' => json_encode($data),
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return $result !== FALSE;
}

function deleteFirebaseData($path) {
    global $firebase_database_url;
    
    // Check if Firebase is configured
    if (!isFirebaseConfigured()) {
        error_log('Firebase not configured. Please update firebase-config.php with your actual Firebase credentials.');
        return false;
    }
    
    $url = $firebase_database_url . $path . '.json';
    $options = [
        'http' => [
            'method' => 'DELETE',
            'timeout' => 10,
            'ignore_errors' => true
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    return $result !== FALSE;
}

// Example usage functions for your admin panel
function getRidesData() {
    return getFirebaseData('bookings');
}

function getDriverApplications() {
    // driver_approvals is the only path the PerdaRide app actually writes to on
    // driver registration. The old driverApplications/driver_applications/applications
    // paths were leftovers from an earlier app version -- merging them back in risked
    // shadowing a real applicant's current data with stale data from old testing under
    // the same key, since earlier paths took precedence over driver_approvals.
    $data = getFirebaseData('driver_approvals');

    if (is_array($data) && isset($data['error'])) {
        error_log("getDriverApplications: driver_approvals returned error: " . $data['error']);
        return [];
    }

    if (!is_array($data)) {
        return [];
    }

    return $data;
}

function updateDriverStatus($driverId, $status) {
    return updateFirebaseData('driver_approvals/' . $driverId, ['status' => $status]);
}

// Function to get booking data
function getBookingsData() {
    return getFirebaseData('bookings');
}

// Function to update booking status
function updateBookingStatus($bookingId, $status) {
    return updateFirebaseData('bookings/' . $bookingId, ['status' => $status]);
}

// Function to get average rating from customer feedback
function getAverageRatingFromFeedback() {
    try {
        $ratings = getFirebaseData('ratings');
        if (empty($ratings)) {
            return 0.0;
        }
        
        $totalRating = 0;
        $ratingCount = 0;
        
        foreach ($ratings as $driverId => $driverRatings) {
            if (!is_array($driverRatings)) continue;
            foreach ($driverRatings as $ratingId => $entry) {
                if (isset($entry['rating'])) {
                    $totalRating += floatval($entry['rating']);
                    $ratingCount++;
                }
            }
        }
        
        return $ratingCount > 0 ? round($totalRating / $ratingCount, 1) : 0.0;
    } catch (Exception $e) {
        return 0.0;
    }
}

function getMetrics() {
    $bookings = getFirebaseData('bookings');
    $today = date('Y-m-d');
    
    // Check if bookings is null or empty
    if ($bookings === null || !is_array($bookings) || empty($bookings)) {
        // Still try to get average rating even if no bookings
        $averageRating = getAverageRatingFromFeedback();
        return [
            'total_rides_today' => 0,
            'total_revenue' => 'RM 0.00',
            'active_rides' => 0,
            'average_rating' => $averageRating
        ];
    }
    
    $todayBookings = [];
    $totalRevenue = 0;
    $activeBookings = 0;
    
    foreach ($bookings as $bookingId => $booking) {
        // Skip if booking is not an array
        if (!is_array($booking)) {
            continue;
        }
        
        // Check if booking is from today (using createdAt timestamp)
        if (isset($booking['createdAt'])) {
            $bookingDate = date('Y-m-d', firebaseTimestampToSeconds($booking['createdAt']));
            if ($bookingDate === $today) {
                $todayBookings[] = $booking;
            }
        }
        
        // Count active bookings (all non-completed rides)
        if (isset($booking['status'])) {
            $status = strtolower($booking['status']);
            if (!in_array($status, ['completed', 'cancelled', 'rejected'])) {
                $activeBookings++;
            }
        }
        
        // Calculate total revenue from completed rides only
        if (isset($booking['status']) && strtolower($booking['status']) === 'completed') {
            $totalRevenue += bookingFareAmount($booking);
        }
    }
    
    // Get average rating from customer feedback data
    $averageRating = getAverageRatingFromFeedback();
    
    return [
        'total_rides_today' => count($todayBookings),
        'total_revenue' => 'RM ' . number_format($totalRevenue, 2),
        'active_rides' => $activeBookings,
        'average_rating' => $averageRating
    ];
}

// Function to create a new ride
function createRide($rideData) {
    $rideId = uniqid('ride_');
    return setFirebaseData('rides/' . $rideId, array_merge($rideData, [
        'id' => $rideId,
        'created_at' => date('Y-m-d H:i:s'),
        'status' => 'pending'
    ]));
}

// Function to create a new driver application
function createDriverApplication($applicationData) {
    $applicationId = uniqid('app_');
    return setFirebaseData('driver_applications/' . $applicationId, array_merge($applicationData, [
        'id' => $applicationId,
        'created_at' => date('Y-m-d H:i:s'),
        'status' => 'pending'
    ]));
}

// Function to get user data by ID
function getUserData($userId) {
    return getFirebaseData('users/' . $userId);
}

// Function to update user data
function updateUserData($userId, $userData) {
    return updateFirebaseData('users/' . $userId, $userData);
}

// Helper function to format currency
function formatCurrency($amount) {
    return 'RM ' . number_format($amount, 2);
}

// Helper function to validate Firebase data
function validateFirebaseData($data, $requiredFields) {
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            return false;
        }
    }
    return true;
}

// Function to update driver commission data
function updateDriverCommission($driverId, $bookingId, $fareAmount, $commissionAmount, $status = 'unpaid') {
    global $firebase_database_url;
    
    try {
        // Get current driver commission data
        $driverData = getFirebaseData("driver_commissions/{$driverId}");
        
        if (!$driverData) {
            // Create new driver commission record
            // Try to fetch driver name from drivers table
            $driverName = 'Unknown Driver';
            $drivers = getFirebaseData('drivers');
            if (is_array($drivers) && isset($drivers[$driverId])) {
                $driverName = $drivers[$driverId]['name'] ?? ($drivers[$driverId]['fullName'] ?? ($drivers[$driverId]['driverName'] ?? $driverName));
            }

            $driverData = [
                'driver_info' => [
                    'driver_id' => $driverId,
                    'driver_name' => $driverName,
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
        }
        
        // Update commission summary
        $driverData['commission_summary']['total_earnings'] += $fareAmount;
        $driverData['commission_summary']['total_commission'] += $commissionAmount;
        
        if ($status === 'paid') {
            $driverData['commission_summary']['paid_commission'] += $commissionAmount;
        } else {
            $driverData['commission_summary']['unpaid_commission'] += $commissionAmount;
        }
        
        $driverData['commission_summary']['last_updated'] = time() * 1000;
        
        // Add job to recent jobs
        $jobId = 'job_' . time();
        $driverData['recent_jobs'][$jobId] = [
            'booking_id' => $bookingId,
            'fare_amount' => $fareAmount,
            'commission_amount' => $commissionAmount,
            'status' => $status,
            'job_date' => date('Y-m-d'),
            'paid_date' => $status === 'paid' ? date('Y-m-d') : null
        ];
        
        // Keep only last 50 jobs
        if (count($driverData['recent_jobs']) > 50) {
            $driverData['recent_jobs'] = array_slice($driverData['recent_jobs'], -50, null, true);
        }

        // Maintain aggregated totals under recent_jobs as convenience fields
        $driverData['recent_jobs_total_unpaid'] = $driverData['commission_summary']['unpaid_commission'];
        $driverData['recent_jobs_total_commission'] = $driverData['commission_summary']['total_commission'];
        
        // Update Firebase
        $url = $firebase_database_url . "driver_commissions/{$driverId}.json";
        $context = stream_context_create([
            'http' => [
                'method' => 'PUT',
                'header' => 'Content-Type: application/json',
                'content' => json_encode($driverData),
                'ignore_errors' => true,
                'timeout' => 10
            ]
        ]);
        
        $result = file_get_contents($url, false, $context);
        return $result !== false;
        
    } catch (Exception $e) {
        error_log("Error updating driver commission: " . $e->getMessage());
        return false;
    }
}

// Function to get driver's car capacity
function getDriverCapacity($driverId) {
    try {
        $drivers = getFirebaseData('drivers');
        if (empty($drivers) || !is_array($drivers)) {
            return null;
        }
        
        if (isset($drivers[$driverId]) && is_array($drivers[$driverId])) {
            $driver = $drivers[$driverId];
            // Check multiple possible field names for capacity
            $capacity = $driver['passengerCapacity'] ?? $driver['capacity'] ?? $driver['carCapacity'] ?? null;
            return $capacity !== null ? intval($capacity) : null;
        }
        
        return null;
    } catch (Exception $e) {
        error_log("Error getting driver capacity: " . $e->getMessage());
        return null;
    }
}

// Function to get available bookings filtered by driver capacity
function getAvailableBookingsForDriver($driverId) {
    try {
        // Get driver capacity
        $driverCapacity = getDriverCapacity($driverId);
        if ($driverCapacity === null) {
            error_log("Driver capacity not found for driver: " . $driverId);
            return [];
        }
        
        // Get all bookings
        $bookings = getBookingsData();
        if (empty($bookings) || !is_array($bookings)) {
            return [];
        }
        
        $availableBookings = [];
        
        foreach ($bookings as $bookingId => $booking) {
            if (!is_array($booking)) {
                continue;
            }
            
            // Only show pending bookings with no driver assigned
            $status = strtolower($booking['status'] ?? '');
            $assignedDriverId = $booking['driverId'] ?? null;
            
            if ($status !== 'pending' || $assignedDriverId !== null) {
                continue;
            }
            
            // Get passenger count from booking
            // Check multiple possible field names
            $passengerCount = $booking['passengerCount'] ?? 
                            $booking['numberOfPassengers'] ?? 
                            $booking['passengers'] ?? 
                            $booking['passengerCapacity'] ?? 
                            1; // Default to 1 if not specified
            
            $passengerCount = intval($passengerCount);
            
            // Only include bookings where passenger count <= driver capacity
            if ($passengerCount <= $driverCapacity) {
                $availableBookings[$bookingId] = $booking;
            }
        }
        
        return $availableBookings;
    } catch (Exception $e) {
        error_log("Error getting available bookings for driver: " . $e->getMessage());
        return [];
    }
}

// Function to validate if driver can accept a booking based on capacity
function canDriverAcceptBooking($driverId, $bookingId) {
    try {
        $driverCapacity = getDriverCapacity($driverId);
        if ($driverCapacity === null) {
            return false;
        }
        
        $booking = getFirebaseData('bookings/' . $bookingId);
        if (empty($booking) || !is_array($booking)) {
            return false;
        }
        
        // Get passenger count from booking
        $passengerCount = $booking['passengerCount'] ?? 
                         $booking['numberOfPassengers'] ?? 
                         $booking['passengers'] ?? 
                         $booking['passengerCapacity'] ?? 
                         1;
        
        $passengerCount = intval($passengerCount);
        
        // Check if driver capacity is sufficient
        return $passengerCount <= $driverCapacity;
    } catch (Exception $e) {
        error_log("Error validating driver booking capacity: " . $e->getMessage());
        return false;
    }
}

// Function to get Firebase Storage download URL
function getFirebaseStorageUrl($filePath) {
    global $firebase_storage_url, $firebase_config;
    
    if (empty($filePath)) {
        return null;
    }

    // Android/iOS local device paths are not accessible from the admin web app.
    // The mobile app must upload the file to Firebase Storage and save the
    // resulting Storage path or download URL instead of a device cache path.
    if (preg_match('#^(?:/data/user/|/storage/emulated/|content://|file://)#i', $filePath)) {
        error_log('Local device path cannot be opened by admin panel: ' . $filePath);
        return null;
    }
    
    // If it's already a full URL, return as is
    if (strpos($filePath, 'http://') === 0 || strpos($filePath, 'https://') === 0) {
        return $filePath;
    }
    
    // If it's a gs:// URL, convert it
    if (strpos($filePath, 'gs://') === 0) {
        // Extract path from gs:// URL
        // Format: gs://bucket-name/path/to/file.jpg
        $bucketPrefix = 'gs://' . $firebase_config['storageBucket'] . '/';
        if (strpos($filePath, $bucketPrefix) === 0) {
            $filePath = substr($filePath, strlen($bucketPrefix));
        } else {
            // Try to extract path after gs://
            $parts = explode('/', $filePath, 4);
            if (count($parts) >= 4) {
                $filePath = $parts[3]; // Get path after bucket name
            } else {
                // If gs:// format is different, try to extract just the path
                $filePath = preg_replace('#^gs://[^/]+/#', '', $filePath);
            }
        }
    }
    
    // Remove leading slash if present
    $filePath = ltrim($filePath, '/');
    
    // If path is just a number or simple string (like phone number), it might be a filename
    // Try common document folder structures
    if (preg_match('/^[0-9]+$/', $filePath) || (strpos($filePath, '/') === false && strlen($filePath) < 50)) {
        // This might be just a filename, but we can't guess the folder structure
        // Log for debugging
        error_log("Warning: Image path appears to be incomplete: " . $filePath);
        // Still try to use it, but it might fail
    }
    
    // URL encode each path segment separately (Firebase Storage requires this)
    $pathParts = explode('/', $filePath);
    $encodedParts = array_map('rawurlencode', $pathParts);
    $encodedPath = implode('/', $encodedParts);
    
    // Construct download URL
    // Format: https://firebasestorage.googleapis.com/v0/b/{bucket}/o/{path}?alt=media
    $downloadUrl = $firebase_storage_url . $encodedPath . '?alt=media';
    
    return $downloadUrl;
}

// Function to get Firebase Storage download URL with token (for private files)
function getFirebaseStorageDownloadUrl($filePath) {
    global $firebase_storage_url, $firebase_config, $firebase_database_url;
    
    if (empty($filePath)) {
        return null;
    }

    if (preg_match('#^(?:/data/user/|/storage/emulated/|content://|file://)#i', $filePath)) {
        error_log('Local device path cannot be opened by admin panel: ' . $filePath);
        return null;
    }
    
    // If it's already a full URL, return as is
    if (strpos($filePath, 'http://') === 0 || strpos($filePath, 'https://') === 0) {
        return $filePath;
    }
    
    // If it's a gs:// URL, convert it
    if (strpos($filePath, 'gs://') === 0) {
        $path = str_replace('gs://' . $firebase_config['storageBucket'] . '/', '', $filePath);
        $filePath = $path;
    }
    
    // Try to get download token from Firebase Storage metadata
    // For now, return the public URL format
    // In production, you might want to use Firebase Admin SDK to get signed URLs
    $encodedPath = rawurlencode($filePath);
    $downloadUrl = $firebase_storage_url . $encodedPath . '?alt=media';
    
    return $downloadUrl;
}

// Helper function to get image URL from various formats
/**
 * Extract all possible image paths/URLs from driver application data
 * This function checks all possible field names where image paths might be stored
 */
/**
 * Recursively extract ALL fields from an array
 */
function getAllFieldsRecursive($data, $prefix = '') {
    $fields = [];
    if (is_array($data)) {
        foreach ($data as $key => $value) {
            $fieldName = $prefix ? $prefix . '.' . $key : $key;
            if (is_array($value)) {
                $fields = array_merge($fields, getAllFieldsRecursive($value, $fieldName));
            } else {
                $fields[$fieldName] = $value;
            }
        }
    }
    return $fields;
}

/**
 * Check if a string looks like an image path/URL
 */
function looksLikeImagePath($value, $fieldName = '') {
    if (empty($value) || !is_string($value)) {
        return false;
    }
    
    $value = trim($value);
    
    // Check for obvious image indicators
    if (stripos($value, 'http') !== false) return true;
    if (stripos($value, 'gs://') !== false) return true;
    if (stripos($value, '.jpg') !== false || stripos($value, '.png') !== false || stripos($value, '.jpeg') !== false) return true;
    
    // Check if it contains path-like structure and field name suggests it's an image
    if (stripos($value, '/') !== false) {
        $fieldLower = strtolower($fieldName);
        if (stripos($fieldLower, 'image') !== false || 
            stripos($fieldLower, 'photo') !== false || 
            stripos($fieldLower, 'url') !== false || 
            stripos($fieldLower, 'path') !== false ||
            stripos($fieldLower, 'pic') !== false ||
            stripos($fieldLower, 'img') !== false) {
            return true;
        }
        
        // Check for document-related field names
        if (stripos($fieldLower, 'idcard') !== false || 
            stripos($fieldLower, 'license') !== false || 
            stripos($fieldLower, 'grant') !== false || 
            stripos($fieldLower, 'car') !== false ||
            stripos($fieldLower, 'document') !== false) {
            return true;
        }
    }
    
    return false;
}

function extractImagePathsFromApplication($app, $docType) {
    $paths = [];
    
    if (!is_array($app)) {
        return $paths;
    }
    
    // First, try specific field names (most common cases)
    $fieldVariations = [];
    
    if ($docType === 'idCard') {
        $fieldVariations = [
            // Common top-level fields used by the mobile app
            'icPhotoFrontPath', 'icPhotoBackPath', 'icPhotoPath',
            // Common nested fields used by the mobile app
            ['documents', 'icPhotoFrontPath'], ['documents', 'icPhotoBackPath'], ['documents', 'icPhotoPath'],
            // Nested in documents object
            ['documents', 'idCardUrl'], ['documents', 'id_card_url'],
            ['documents', 'idCardPath'], ['documents', 'id_card_path'],
            ['documents', 'idCardImage'], ['documents', 'id_card_image'],
            ['documents', 'idCard'], ['documents', 'id_card'],
            // Top level
            'idCardUrl', 'id_card_url', 'idCardPath', 'id_card_path',
            'idCardImage', 'id_card_image', 'idCard', 'id_card'
        ];
    } elseif ($docType === 'license') {
        $fieldVariations = [
            // Common top-level fields used by the mobile app
            'licensePhotoFrontPath', 'licensePhotoBackPath', 'licensePhotoPath',
            // Common nested fields used by the mobile app
            ['documents', 'licensePhotoFrontPath'], ['documents', 'licensePhotoBackPath'], ['documents', 'licensePhotoPath'],
            // Nested in documents object
            ['documents', 'drivingLicenseUrl'], ['documents', 'driving_license_url'],
            ['documents', 'licenseUrl'], ['documents', 'license_url'],
            ['documents', 'licensePath'], ['documents', 'driving_license_path'],
            ['documents', 'licenseImage'], ['documents', 'license_image'],
            ['documents', 'drivingLicense'], ['documents', 'driving_license'],
            ['documents', 'license'],
            // Top level
            'drivingLicenseUrl', 'driving_license_url', 'licenseUrl', 'license_url',
            'drivingLicensePath', 'licensePath', 'driving_license_path',
            'licenseImage', 'license_image', 'drivingLicense', 'driving_license', 'license'
        ];
    } elseif ($docType === 'carGrant') {
        $fieldVariations = [
            // Common top-level fields used by the mobile app
            'grantPhotoPath',
            // Common nested fields used by the mobile app
            ['documents', 'grantPhotoPath'],
            // Nested in documents object
            ['documents', 'carGrantUrl'], ['documents', 'car_grant_url'],
            ['documents', 'grantUrl'], ['documents', 'grant_url'],
            ['documents', 'carGrantPath'], ['documents', 'car_grant_path'],
            ['documents', 'grantPath'], ['documents', 'grant_path'],
            ['documents', 'carGrantImage'], ['documents', 'car_grant_image'],
            ['documents', 'carGrant'], ['documents', 'car_grant'],
            ['documents', 'grant'],
            // Top level
            'carGrantUrl', 'car_grant_url', 'grantUrl', 'grant_url',
            'carGrantPath', 'grantPath', 'car_grant_path', 'grant_path',
            'carGrantImage', 'car_grant_image', 'carGrant', 'car_grant', 'grant'
        ];
    } elseif ($docType === 'carImage') {
        $fieldVariations = [
            // Common top-level fields used by the mobile app
            'carPhotoFrontPath', 'carPhotoBackPath', 'carPhotoRightPath', 'carPhotoLeftPath', 'carPhotoPath',
            // Common nested fields used by the mobile app
            ['documents', 'carPhotoFrontPath'], ['documents', 'carPhotoBackPath'],
            ['documents', 'carPhotoRightPath'], ['documents', 'carPhotoLeftPath'],
            ['documents', 'carPhotoPath'],
            // Nested in documents object - 4 sides
            ['documents', 'carImageFrontUrl'], ['documents', 'car_image_front_url'],
            ['documents', 'carImageBackUrl'], ['documents', 'car_image_back_url'],
            ['documents', 'carImageRightUrl'], ['documents', 'car_image_right_url'],
            ['documents', 'carImageLeftUrl'], ['documents', 'car_image_left_url'],
            ['documents', 'carImageFrontPath'], ['documents', 'car_image_front_path'],
            ['documents', 'carImageBackPath'], ['documents', 'car_image_back_path'],
            ['documents', 'carImageRightPath'], ['documents', 'car_image_right_path'],
            ['documents', 'carImageLeftPath'], ['documents', 'car_image_left_path'],
            // Legacy single image fields
            ['documents', 'carImageUrl'], ['documents', 'car_image_url'],
            ['documents', 'vehicleImageUrl'], ['documents', 'vehicle_image_url'],
            ['documents', 'carImagePath'], ['documents', 'car_image_path'],
            ['documents', 'vehicleImagePath'], ['documents', 'vehicle_image_path'],
            ['documents', 'carImage'], ['documents', 'car_image'],
            ['documents', 'vehicleImage'], ['documents', 'vehicle_image'],
            // Top level - 4 sides
            'carImageFrontUrl', 'car_image_front_url', 'carImageFrontPath', 'car_image_front_path',
            'carImageBackUrl', 'car_image_back_url', 'carImageBackPath', 'car_image_back_path',
            'carImageRightUrl', 'car_image_right_url', 'carImageRightPath', 'car_image_right_path',
            'carImageLeftUrl', 'car_image_left_url', 'carImageLeftPath', 'car_image_left_path',
            // Legacy single image fields
            'carImageUrl', 'car_image_url', 'vehicleImageUrl', 'vehicle_image_url',
            'carImagePath', 'vehicleImagePath', 'car_image_path', 'vehicle_image_path',
            'carImage', 'car_image', 'vehicleImage', 'vehicle_image'
        ];
    }
    
    // Extract paths from specific field variations first
    foreach ($fieldVariations as $field) {
        $value = null;
        
        if (is_array($field)) {
            // Nested field (e.g., ['documents', 'idCardUrl'])
            if (isset($app[$field[0]]) && is_array($app[$field[0]]) && isset($app[$field[0]][$field[1]])) {
                $value = $app[$field[0]][$field[1]];
            }
        } else {
            // Top-level field
            if (isset($app[$field])) {
                $value = $app[$field];
            }
        }
        
        // If we found a value, add it to paths (if it's not empty and looks like a path/URL)
        if (!empty($value) && is_string($value)) {
            $trimmed = trim($value);
            if (looksLikeImagePath($trimmed, $field)) {
                if (!in_array($trimmed, $paths)) {
                    $paths[] = $trimmed;
                }
            }
        }
    }
    
    // If no paths found, scan ALL fields recursively (aggressive search)
    if (empty($paths)) {
        $allFields = getAllFieldsRecursive($app);
        
        // Filter fields based on document type keywords
        $keywords = [];
        if ($docType === 'idCard') {
            $keywords = ['idcard', 'id_card', 'ic'];
        } elseif ($docType === 'license') {
            $keywords = ['license', 'driving'];
        } elseif ($docType === 'carGrant') {
            $keywords = ['grant', 'car_grant'];
        } elseif ($docType === 'carImage') {
            $keywords = ['car', 'vehicle', 'carimage', 'car_image', 'front', 'back', 'right', 'left'];
        }
        
        foreach ($allFields as $fieldName => $value) {
            if (is_string($value) && !empty($value)) {
                $fieldLower = strtolower($fieldName);
                $valueTrimmed = trim($value);
                
                // Check if field name contains document type keyword
                $matchesKeyword = false;
                foreach ($keywords as $keyword) {
                    if (stripos($fieldLower, $keyword) !== false) {
                        $matchesKeyword = true;
                        break;
                    }
                }
                
                // If field matches keyword AND looks like image path, add it
                if ($matchesKeyword && looksLikeImagePath($valueTrimmed, $fieldName)) {
                    if (!in_array($valueTrimmed, $paths)) {
                        $paths[] = $valueTrimmed;
                    }
                }
            }
        }
    }
    
    return $paths;
}

function getImageUrl($imagePath) {
    if (empty($imagePath)) {
        return null;
    }

    if (preg_match('#^(?:/data/user/|/storage/emulated/|content://|file://)#i', $imagePath)) {
        return null;
    }
    
    // If it's already a full URL, return as is
    if (strpos($imagePath, 'http://') === 0 || strpos($imagePath, 'https://') === 0) {
        return $imagePath;
    }
    
    // If it's a Firebase Storage path, convert it
    if (strpos($imagePath, 'gs://') === 0 || strpos($imagePath, '/') === 0) {
        return getFirebaseStorageUrl($imagePath);
    }
    
    // Default: assume it's a Firebase Storage path
    return getFirebaseStorageUrl($imagePath);
}
?>

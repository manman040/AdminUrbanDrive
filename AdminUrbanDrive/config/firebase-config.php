<?php
// Firebase configuration + Realtime Database helpers for the admin panel.
//
// Every database request carries the signed-in admin's Firebase ID token
// (?auth=<token>). The database rules only grant admin access to accounts
// listed under /admins/{uid}, so a valid Firebase login alone is not enough:
// the account must also be on that list. Setup steps: Docs/ADMIN_AUTH_SETUP.md

// Firebase project configuration (the web API key is a public identifier,
// not a secret -- access is controlled by the database rules).
$firebase_config = [
    'apiKey' => 'AIzaSyCqkGpb-FMP6usYCMovttA8BNXfUtDE-c0',
    'authDomain' => 'drive-ab344.firebaseapp.com',
    'projectId' => 'drive-ab344',
    'storageBucket' => 'drive-ab344.firebasestorage.app',
    'messagingSenderId' => '650014399592',
    'appId' => '1:650014399592:android:8a254985e60505bd3bafdd'
];

// Firebase Realtime Database URL
$firebase_database_url = 'https://drive-ab344-default-rtdb.asia-southeast1.firebasedatabase.app/';

// Firebase Storage URL (legacy -- documents are now Cloudinary URLs)
$firebase_storage_url = 'https://firebasestorage.googleapis.com/v0/b/drive-ab344.firebasestorage.app/o/';

// Last error message from a Firebase request (for diagnostics pages)
$firebase_last_error = '';

function isFirebaseConfigured() {
    global $firebase_config, $firebase_database_url;

    if (strpos($firebase_config['apiKey'], 'AIzaSyBqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXq') !== false ||
        strpos($firebase_database_url, 'your-project-id') !== false) {
        return false;
    }

    return true;
}

function firebaseLastError() {
    global $firebase_last_error;
    return $firebase_last_error;
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

// ---------------------------------------------------------------------------
// HTTP
// ---------------------------------------------------------------------------

/**
 * Performs one HTTP request and returns ['status' => int, 'body' => mixed,
 * 'raw' => string]. status is 0 when the connection itself failed. body is
 * the JSON-decoded response (null if the response was not JSON).
 */
function firebaseHttp($method, $url, $body = null, $formEncoded = false) {
    global $firebase_last_error;

    $headers = $formEncoded
        ? "Content-Type: application/x-www-form-urlencoded\r\n"
        : "Content-Type: application/json\r\n";

    $options = [
        'http' => [
            'method' => $method,
            'header' => $headers,
            'timeout' => 10,
            'ignore_errors' => true, // read 4xx/5xx bodies ourselves instead of warning
        ]
    ];
    if ($body !== null) {
        $options['http']['content'] = $formEncoded ? http_build_query($body) : json_encode($body);
    }

    $context = stream_context_create($options);
    $raw = @file_get_contents($url, false, $context);

    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    if ($raw === false) {
        $firebase_last_error = 'Could not connect to ' . parse_url($url, PHP_URL_HOST);
        return ['status' => 0, 'body' => null, 'raw' => ''];
    }

    return ['status' => $status, 'body' => json_decode($raw, true), 'raw' => $raw];
}

function firebaseResponseOk($response) {
    return $response['status'] >= 200 && $response['status'] < 300;
}

function firebaseResponseError($response) {
    if ($response['status'] === 0) {
        return 'connection failed';
    }
    if (is_array($response['body']) && isset($response['body']['error'])) {
        $error = $response['body']['error'];
        if (is_array($error)) {
            $error = $error['message'] ?? json_encode($error);
        }
        return (string) $error;
    }
    return 'HTTP ' . $response['status'];
}

// ---------------------------------------------------------------------------
// Admin authentication (Firebase Auth + /admins allowlist)
// ---------------------------------------------------------------------------

/** Signs in with email/password. Returns the token payload or null. */
function firebaseSignIn($email, $password) {
    global $firebase_config;

    $response = firebaseHttp(
        'POST',
        'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=' . $firebase_config['apiKey'],
        ['email' => $email, 'password' => $password, 'returnSecureToken' => true]
    );

    if (!firebaseResponseOk($response) || empty($response['body']['idToken'])) {
        global $firebase_last_error;
        $firebase_last_error = firebaseResponseError($response);
        error_log('Firebase sign-in failed: ' . $firebase_last_error);
        return null;
    }

    return $response['body'];
}

function firebaseStoreSession(array $tokens) {
    $_SESSION['firebase_token'] = $tokens['idToken'];
    $_SESSION['firebase_refresh_token'] = $tokens['refreshToken'] ?? ($_SESSION['firebase_refresh_token'] ?? '');
    $_SESSION['firebase_user_id'] = $tokens['localId'];
    // Refresh one minute before Firebase's own expiry so a request never
    // goes out with a token that dies mid-flight.
    $_SESSION['firebase_token_expires_at'] = time() + (int) ($tokens['expiresIn'] ?? 3600) - 60;
}

/** Exchanges the stored refresh token for a fresh ID token. */
function firebaseRefreshSession() {
    global $firebase_config;

    $refreshToken = $_SESSION['firebase_refresh_token'] ?? '';
    if ($refreshToken === '') {
        return false;
    }

    $response = firebaseHttp(
        'POST',
        'https://securetoken.googleapis.com/v1/token?key=' . $firebase_config['apiKey'],
        ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken],
        true
    );

    if (!firebaseResponseOk($response) || empty($response['body']['id_token'])) {
        error_log('Firebase token refresh failed: ' . firebaseResponseError($response));
        return false;
    }

    firebaseStoreSession([
        'idToken' => $response['body']['id_token'],
        'refreshToken' => $response['body']['refresh_token'] ?? $refreshToken,
        'localId' => $response['body']['user_id'] ?? ($_SESSION['firebase_user_id'] ?? ''),
        'expiresIn' => $response['body']['expires_in'] ?? 3600,
    ]);
    return true;
}

/** A valid ID token for the signed-in admin, refreshing if needed; null if none. */
function firebaseAuthToken() {
    if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['firebase_token'])) {
        return null;
    }
    if (time() >= (int) ($_SESSION['firebase_token_expires_at'] ?? 0)) {
        if (!firebaseRefreshSession()) {
            return null;
        }
    }
    return $_SESSION['firebase_token'];
}

/** True only if /admins/{uid} is exactly `true` in the database. */
function firebaseIsAdminUid($uid, $idToken) {
    global $firebase_database_url;

    $response = firebaseHttp(
        'GET',
        $firebase_database_url . 'admins/' . rawurlencode($uid) . '.json?auth=' . rawurlencode($idToken)
    );
    return firebaseResponseOk($response) && $response['body'] === true;
}

/**
 * Logs an admin in. Returns 'ok', or one of the error codes shown on the
 * login page: 'invalid_credentials', 'not_admin', 'not_configured'.
 */
function authenticateAdmin($email, $password) {
    if (!isFirebaseConfigured()) {
        error_log('Firebase not configured. Update config/firebase-config.php.');
        return 'not_configured';
    }

    $tokens = firebaseSignIn($email, $password);
    if ($tokens === null) {
        // Anything other than a credential mistake is a server-side problem the
        // admin cannot fix by retyping the password -- say so on the login page.
        $reason = firebaseLastError();
        foreach (['API_KEY', 'OPERATION_NOT_ALLOWED', 'connection failed', 'HTTP 5', 'PROJECT'] as $marker) {
            if (stripos($reason, $marker) !== false) {
                return 'server_config';
            }
        }
        return 'invalid_credentials';
    }

    if (!firebaseIsAdminUid($tokens['localId'], $tokens['idToken'])) {
        error_log('Login refused: uid ' . $tokens['localId'] . ' is not listed under /admins');
        return 'not_admin';
    }

    session_regenerate_id(true);
    firebaseStoreSession($tokens);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_username'] = $email;
    $_SESSION['auth_method'] = 'firebase';
    return 'ok';
}

// Kept for older callers.
function authenticateWithFirebase($email, $password) {
    return authenticateAdmin($email, $password) === 'ok';
}

function firebaseClearSession() {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

/**
 * Gate for every admin page and action. Redirects to the login page (or,
 * for JSON endpoints, answers 401) unless there is a logged-in admin whose
 * Firebase token is valid or can be refreshed.
 */
function requireAdminSession($jsonResponse = false) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $loggedIn = !empty($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
    if ($loggedIn && firebaseAuthToken() !== null) {
        return;
    }

    $expired = $loggedIn; // was logged in, but the token could not be refreshed
    firebaseClearSession();

    if ($jsonResponse) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $expired ? 'Session expired, please log in again' : 'Unauthorized'
        ]);
        exit;
    }

    header('Location: index.php' . ($expired ? '?error=session_expired' : ''));
    exit;
}

// ---------------------------------------------------------------------------
// Realtime Database REST helpers
// ---------------------------------------------------------------------------

function firebaseDatabaseUrl($path, array $query = []) {
    global $firebase_database_url;

    $token = firebaseAuthToken();
    if ($token !== null) {
        $query['auth'] = $token;
    }

    $url = $firebase_database_url . ltrim($path, '/') . '.json';
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

/**
 * Reads a path. Returns the decoded array, or [] when the path is empty or
 * the request failed (the failure is logged and kept in firebaseLastError()).
 */
function getFirebaseData($path) {
    global $firebase_last_error;

    if (!isFirebaseConfigured()) {
        error_log('Firebase not configured. Update config/firebase-config.php.');
        return [];
    }

    $response = firebaseHttp('GET', firebaseDatabaseUrl($path));

    if (!firebaseResponseOk($response)) {
        $firebase_last_error = "Read of '$path' failed: " . firebaseResponseError($response);
        error_log('Firebase ' . $firebase_last_error);
        return [];
    }

    if (!is_array($response['body'])) {
        // "null" for a missing path, or a scalar value -- nothing to iterate.
        return [];
    }

    return $response['body'];
}

function firebaseWriteResult($method, $path, $response) {
    global $firebase_last_error;

    if (firebaseResponseOk($response)) {
        return true;
    }
    $firebase_last_error = "$method '$path' failed: " . firebaseResponseError($response);
    error_log('Firebase ' . $firebase_last_error);
    return false;
}

function setFirebaseData($path, $data) {
    if (!isFirebaseConfigured()) {
        return false;
    }
    return firebaseWriteResult('PUT', $path, firebaseHttp('PUT', firebaseDatabaseUrl($path), $data));
}

function updateFirebaseData($path, $data) {
    if (!isFirebaseConfigured()) {
        return false;
    }
    return firebaseWriteResult('PATCH', $path, firebaseHttp('PATCH', firebaseDatabaseUrl($path), $data));
}

function deleteFirebaseData($path) {
    if (!isFirebaseConfigured()) {
        return false;
    }
    return firebaseWriteResult('DELETE', $path, firebaseHttp('DELETE', firebaseDatabaseUrl($path)));
}

// ---------------------------------------------------------------------------
// Push (POST) a new child under $path. Returns the generated key, or null.
// ---------------------------------------------------------------------------
function pushFirebaseData($path, $data) {
    if (!isFirebaseConfigured()) {
        return null;
    }
    $response = firebaseHttp('POST', firebaseDatabaseUrl($path), $data);
    if (!firebaseWriteResult('POST', $path, $response)) {
        return null;
    }
    return is_array($response['body']) && isset($response['body']['name']) ? $response['body']['name'] : null;
}

// ---------------------------------------------------------------------------
// Booking status vocabulary. These are the only values the mobile app writes
// or understands (pending -> accepted -> arrived_pickup -> in_progress ->
// completed, or cancelled at any point). Anything else written by the admin
// simply hides the ride from both the driver and the rider.
// ---------------------------------------------------------------------------
function bookingStatuses() {
    return ['pending', 'accepted', 'arrived_pickup', 'in_progress', 'completed', 'cancelled'];
}

function bookingActiveStatuses() {
    return ['pending', 'accepted', 'arrived_pickup', 'in_progress'];
}

function bookingStatusLabel($status) {
    $labels = [
        'pending' => 'Pending',
        'accepted' => 'Accepted',
        'arrived_pickup' => 'Driver Arrived',
        'in_progress' => 'In Progress',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
    ];
    $key = normalizeBookingStatus($status);
    return $labels[$key] ?? ucwords(str_replace('_', ' ', $key));
}

// Maps legacy/loose spellings (older admin code, mock data) onto the app's vocabulary.
function normalizeBookingStatus($status) {
    $key = strtolower(trim((string) $status));
    $aliases = [
        'confirmed' => 'accepted',
        'assigned' => 'accepted',
        'ongoing' => 'in_progress',
        'on going' => 'in_progress',
        'on_ride' => 'in_progress',
        'started' => 'in_progress',
        'arrived' => 'arrived_pickup',
        'cancel' => 'cancelled',
        'canceled' => 'cancelled',
        'rejected' => 'cancelled',
        'finish' => 'completed',
        'finished' => 'completed',
        'done' => 'completed',
        'complete' => 'completed',
    ];
    return $aliases[$key] ?? $key;
}

// ---------------------------------------------------------------------------
// In-app notifications: the same feeds the mobile app writes and reads
// (user_notifications/{uid} for riders, driver_notifications/{uid} for drivers).
// ---------------------------------------------------------------------------
function pushAppNotification($uid, $isDriver, $title, $body, $bookingId = null) {
    $uid = trim((string) $uid);
    if ($uid === '') {
        return null;
    }
    $payload = [
        'title' => $title,
        'body' => $body,
        'read' => false,
        'createdAt' => time() * 1000,
        'source' => 'admin',
    ];
    if ($bookingId !== null && $bookingId !== '') {
        $payload['bookingId'] = (string) $bookingId;
    }
    return pushFirebaseData(($isDriver ? 'driver_notifications/' : 'user_notifications/') . $uid, $payload);
}

// Human-readable date for the app's timestamps (registrationDate/createdAt are
// milliseconds since epoch) with the older created_at strings as fallback.
function formatFirebaseDate($record, $format = 'M d, Y') {
    if (!is_array($record)) {
        return '';
    }
    foreach (['registrationDate', 'createdAt', 'submittedAt', 'approved_at', 'created_at', 'updatedAt'] as $field) {
        if (!isset($record[$field]) || $record[$field] === '' || $record[$field] === null) {
            continue;
        }
        $value = $record[$field];
        if (is_array($value) || is_numeric($value)) {
            $seconds = firebaseTimestampToSeconds($value);
            return $seconds > 0 ? date($format, $seconds) : '';
        }
        $parsed = strtotime((string) $value);
        if ($parsed !== false) {
            return date($format, $parsed);
        }
        return (string) $value;
    }
    return '';
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

// The two ride categories the app offers. Kept here as the single source of
// truth for defaults so the admin page and the save handler can't drift out
// of sync with each other.
function fareSettingsDefaults() {
    return [
        'categoryA' => ['baseFare' => 2.00, 'perKm' => 1.20, 'bookingFee' => 1.00],
        'categoryB' => ['baseFare' => 3.00, 'perKm' => 1.50, 'bookingFee' => 1.00],
    ];
}

// Function to get fare settings for both ride categories, filling in the
// documented defaults for whichever category hasn't been saved yet (e.g. a
// fresh install where the admin hasn't opened Fare Settings before).
function getFareSettings() {
    $stored = getFirebaseData('fareSettings');
    if (!is_array($stored)) {
        $stored = [];
    }

    $settings = [];
    foreach (fareSettingsDefaults() as $category => $defaults) {
        $categoryData = isset($stored[$category]) && is_array($stored[$category])
            ? $stored[$category]
            : [];
        $settings[$category] = [
            'baseFare' => isset($categoryData['baseFare']) && is_numeric($categoryData['baseFare'])
                ? (float) $categoryData['baseFare']
                : $defaults['baseFare'],
            'perKm' => isset($categoryData['perKm']) && is_numeric($categoryData['perKm'])
                ? (float) $categoryData['perKm']
                : $defaults['perKm'],
            'bookingFee' => isset($categoryData['bookingFee']) && is_numeric($categoryData['bookingFee'])
                ? (float) $categoryData['bookingFee']
                : $defaults['bookingFee'],
            'updatedAt' => isset($categoryData['updatedAt']) && is_numeric($categoryData['updatedAt'])
                ? (int) $categoryData['updatedAt']
                : null,
            'updatedBy' => isset($categoryData['updatedBy']) && $categoryData['updatedBy'] !== ''
                ? (string) $categoryData['updatedBy']
                : null,
        ];
    }

    return $settings;
}

// Function to save fare settings for one ride category. Caller is
// responsible for validating $category against fareSettingsDefaults() and
// the amounts before calling this.
function saveFareSettings($category, $baseFare, $perKm, $bookingFee) {
    return setFirebaseData('fareSettings/' . $category, [
        'baseFare' => $baseFare,
        'perKm' => $perKm,
        'bookingFee' => $bookingFee,
        'updatedAt' => time() * 1000,
    ]);
}

// ---------------------------------------------------------------------------
// Fare settings: labels, change detection and the change log
// (fareSettingsHistory/{pushId}). Every save from the admin panel that
// actually changes a value is recorded with who changed it, when, and the
// old -> new values, so the Fare Settings page can show a history.
// ---------------------------------------------------------------------------

// The app calls these "Package A (1-4 passengers)" / "Package B (5-8 passengers)".
function fareCategoryLabels() {
    return [
        'categoryA' => ['letter' => 'A', 'name' => 'Category A', 'tier' => 'Standard', 'package' => 'Package A', 'passengers' => '1-4 passengers'],
        'categoryB' => ['letter' => 'B', 'name' => 'Category B', 'tier' => 'Premium', 'package' => 'Package B', 'passengers' => '5-8 passengers'],
    ];
}

function fareFieldLabels() {
    return ['baseFare' => 'Base fare', 'perKm' => 'Price per KM', 'bookingFee' => 'Booking fee'];
}

// Which of the three amounts differ between two settings arrays (2 dp).
// Returns [field => ['from' => x, 'to' => y]] -- empty when nothing changed.
function fareSettingsDiff(array $before, array $after) {
    $changes = [];
    foreach (fareFieldLabels() as $field => $label) {
        $from = round((float) ($before[$field] ?? 0), 2);
        $to = round((float) ($after[$field] ?? 0), 2);
        if (abs($from - $to) >= 0.005) {
            $changes[$field] = ['from' => $from, 'to' => $to];
        }
    }
    return $changes;
}

/**
 * Saves one category and, if anything changed, appends an entry to
 * fareSettingsHistory. Returns:
 *   ['success' => bool, 'message' => string, 'settings' => array,
 *    'changes' => array, 'history' => entry-with-id|null]
 */
function saveFareSettingsWithHistory($category, $baseFare, $perKm, $bookingFee, $changedBy = 'admin') {
    $defaults = fareSettingsDefaults();
    if (!isset($defaults[$category])) {
        return ['success' => false, 'message' => 'Invalid ride category', 'settings' => [], 'changes' => [], 'history' => null];
    }

    $current = getFareSettings();
    $before = [
        'baseFare' => round((float) ($current[$category]['baseFare'] ?? $defaults[$category]['baseFare']), 2),
        'perKm' => round((float) ($current[$category]['perKm'] ?? $defaults[$category]['perKm']), 2),
        'bookingFee' => round((float) ($current[$category]['bookingFee'] ?? $defaults[$category]['bookingFee']), 2),
    ];
    $after = [
        'baseFare' => round((float) $baseFare, 2),
        'perKm' => round((float) $perKm, 2),
        'bookingFee' => round((float) $bookingFee, 2),
    ];
    $changes = fareSettingsDiff($before, $after);

    $now = time() * 1000;
    $record = $after + ['updatedAt' => $now, 'updatedBy' => (string) $changedBy];

    if (!setFirebaseData('fareSettings/' . $category, $record)) {
        return [
            'success' => false,
            'message' => 'Failed to save fare settings: ' . firebaseLastError(),
            'settings' => $record,
            'changes' => $changes,
            'history' => null,
        ];
    }

    $entry = null;
    if (!empty($changes)) {
        $entry = [
            'category' => $category,
            'changedAt' => $now,
            'changedBy' => (string) $changedBy,
            'before' => $before,
            'after' => $after,
            'changes' => $changes,
        ];
        $id = pushFirebaseData('fareSettingsHistory', $entry);
        if ($id === null) {
            error_log('Fare settings saved but the history entry could not be written: ' . firebaseLastError());
        }
        $entry['id'] = $id;
    }

    return [
        'success' => true,
        'message' => empty($changes) ? 'No changes to save' : 'Fare settings saved',
        'settings' => $record,
        'changes' => $changes,
        'history' => $entry,
    ];
}

// Newest first, at most $limit entries.
function getFareSettingsHistory($limit = 100) {
    $raw = getFirebaseData('fareSettingsHistory');
    $entries = [];
    if (is_array($raw)) {
        foreach ($raw as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $entry['id'] = (string) $id;
            $entry['changedAt'] = (int) firebaseTimestampValue($entry['changedAt'] ?? 0);
            if (!is_array($entry['changes'] ?? null)) {
                $entry['changes'] = [];
            }
            $entries[] = $entry;
        }
    }
    usort($entries, function ($a, $b) {
        return $b['changedAt'] <=> $a['changedAt'];
    });
    return array_slice($entries, 0, max(1, (int) $limit));
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

function getMetrics($month = null, $year = null, $allTime = true) {
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

        $bookingSeconds = isset($booking['createdAt']) ? firebaseTimestampToSeconds($booking['createdAt']) : null;

        // Check if booking is from today (using createdAt timestamp)
        if ($bookingSeconds !== null) {
            $bookingDate = date('Y-m-d', $bookingSeconds);
            if ($bookingDate === $today) {
                $todayBookings[] = $booking;
            }
        }

        // Count active bookings (all non-completed rides)
        if (isset($booking['status'])) {
            $status = strtolower($booking['status']);
            if (in_array(normalizeBookingStatus($status), bookingActiveStatuses(), true)) {
                $activeBookings++;
            }
        }

        // Calculate total revenue from completed rides only, scoped to the same
        // month/year (or all-time) the rest of the dashboard is currently showing
        // -- otherwise this tile is a lifetime total sitting next to a
        // month-scoped breakdown and looks inflated/wrong by comparison.
        if (isset($booking['status']) && strtolower($booking['status']) === 'completed') {
            $inSelectedPeriod = $allTime;
            if (!$allTime) {
                $inSelectedPeriod = $bookingSeconds !== null
                    && intval(date('n', $bookingSeconds)) === $month
                    && intval(date('Y', $bookingSeconds)) === $year;
            }
            if ($inSelectedPeriod) {
                $totalRevenue += bookingFareAmount($booking);
            }
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
        
        return setFirebaseData("driver_commissions/{$driverId}", $driverData);

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
            
            if ($status !== 'pending' || ($assignedDriverId !== null && $assignedDriverId !== '')) {
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

    if ($docType === 'profilePicture') {
        $fieldVariations = [
            // Common top-level fields used by the mobile app
            'profilePicturePath',
            // Common nested fields used by the mobile app
            ['documents', 'profilePicturePath'],
            // Nested in documents object
            ['documents', 'profilePictureUrl'], ['documents', 'profile_picture_url'],
            ['documents', 'profilePicturePath'], ['documents', 'profile_picture_path'],
            ['documents', 'profilePictureImage'], ['documents', 'profile_picture_image'],
            ['documents', 'profilePicture'], ['documents', 'profile_picture'],
            // Top level
            'profilePictureUrl', 'profile_picture_url', 'profilePicturePath', 'profile_picture_path',
            'profilePictureImage', 'profile_picture_image', 'profilePicture', 'profile_picture'
        ];
    } elseif ($docType === 'idCard') {
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
        if ($docType === 'profilePicture') {
            $keywords = ['profilepicture', 'profile_picture', 'profilephoto', 'profile_photo'];
        } elseif ($docType === 'idCard') {
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

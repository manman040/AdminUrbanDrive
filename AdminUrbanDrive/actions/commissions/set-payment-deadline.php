<?php
session_start();
require_once 'firebase-config.php';

requireAdminSession(true);
header('Content-Type: application/json');

// Sets or removes driver_commissions/{driverId}/payment_deadline -- the node
// the driver's Total Earning screen watches.
//
// Removal used to unset the key locally and PATCH the parent back, but PATCH
// only merges and can never delete a key, so the deadline silently stayed.
// It is now a real DELETE of that one path. Note the app re-creates a fresh
// 24-hour deadline on its own whenever the driver still owes commission and
// none is set, so "remove" only sticks once the balance is cleared.

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['driverId'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required field: driverId']);
    exit;
}

$driverId = trim((string) $input['driverId']);
$deadlineDate = $input['deadlineDate'] ?? null;
$deadlineTime = $input['deadlineTime'] ?? null;
$deadlineTimestamp = null;

if ($deadlineDate) {
    $datetimeString = $deadlineDate . ' ' . ($deadlineTime ? $deadlineTime : '23:59:59');
    $parsed = strtotime($datetimeString);
    if ($parsed === false) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid date/time format']);
        exit;
    }
    $deadlineTimestamp = $parsed * 1000; // Firebase timestamps are milliseconds
}

try {
    $path = 'driver_commissions/' . $driverId;

    if ($deadlineTimestamp !== null) {
        $deadline = [
            'time' => $deadlineTimestamp,
            'date' => date('Y-m-d H:i:s', firebaseTimestampToSeconds($deadlineTimestamp)),
            'set_by' => $_SESSION['admin_username'] ?? 'admin',
            'set_at' => time() * 1000,
        ];
        // PATCH only this key; the rest of the driver's commission record is untouched.
        $ok = updateFirebaseData($path, ['payment_deadline' => $deadline]);
        if (!$ok) {
            throw new Exception('Failed to update Firebase: ' . firebaseLastError());
        }
        pushAppNotification($driverId, true, 'Commission payment deadline', 'Please settle your outstanding commission by ' . date('d M Y, h:i A', firebaseTimestampToSeconds($deadlineTimestamp)) . '.');
        echo json_encode([
            'success' => true,
            'message' => 'Payment deadline set successfully',
            'deadline' => ['time' => $deadlineTimestamp, 'date' => $deadline['date']]
        ]);
        exit;
    }

    $ok = deleteFirebaseData($path . '/payment_deadline');
    if (!$ok) {
        throw new Exception('Failed to update Firebase: ' . firebaseLastError());
    }
    echo json_encode([
        'success' => true,
        'message' => 'Payment deadline removed successfully',
        'deadline' => null
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error setting payment deadline: ' . $e->getMessage()
    ]);
}

<?php
session_start();
require_once 'firebase-config.php';

requireAdminSession(true);
header('Content-Type: application/json');

// Changes a booking's status from the admin panel. Only the statuses the
// mobile app understands are accepted (see bookingStatuses() in the config);
// anything else would make the ride vanish from the driver's and rider's screens.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || empty($input['bookingId']) || !isset($input['status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$bookingId = trim((string) $input['bookingId']);
$status = normalizeBookingStatus($input['status']);

if (!in_array($status, bookingStatuses(), true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid status. Allowed: ' . implode(', ', bookingStatuses())
    ]);
    exit;
}

try {
    $booking = getFirebaseData('bookings/' . $bookingId);
    if (empty($booking) || !is_array($booking)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    $previousStatus = normalizeBookingStatus($booking['status'] ?? '');
    $now = time() * 1000;

    $updateData = [
        'status' => $status,
        'updatedAt' => $now,
        'statusChangedBy' => 'admin',
    ];
    if ($status === 'completed' && empty($booking['completedAt'])) {
        $updateData['completedAt'] = $now;
    }
    if ($status === 'cancelled') {
        $updateData['cancelledBy'] = 'admin';
    }

    if (!updateFirebaseData('bookings/' . $bookingId, $updateData)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update booking status: ' . firebaseLastError()]);
        exit;
    }

    // Completing a ride from the admin side records the commission just like
    // the app-driven flow would.
    $driverId = trim((string) ($booking['driverId'] ?? ''));
    if ($status === 'completed' && $previousStatus !== 'completed' && $driverId !== '') {
        $fare = bookingFareAmount($booking);
        updateDriverCommission($driverId, $bookingId, $fare, ($fare * 20) / 100, 'unpaid');
    }

    // Tell both parties through the same notification feeds the app uses.
    $label = bookingStatusLabel($status);
    $riderId = trim((string) ($booking['userId'] ?? ''));
    if ($riderId !== '' && $status !== $previousStatus) {
        pushAppNotification($riderId, false, 'Booking ' . strtolower($label), 'Your booking was marked "' . $label . '" by Urban Drive.', $bookingId);
    }
    if ($driverId !== '' && $status !== $previousStatus) {
        pushAppNotification($driverId, true, 'Booking ' . strtolower($label), 'A booking assigned to you was marked "' . $label . '" by Urban Drive.', $bookingId);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Booking status updated to ' . $label,
        'status' => $status,
        'previousStatus' => $previousStatus,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}

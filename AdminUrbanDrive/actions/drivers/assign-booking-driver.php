<?php
session_start();
require_once 'firebase-config.php';

requireAdminSession(true);
header('Content-Type: application/json');

// Assigns a driver to a booking from the admin panel. The booking is written
// exactly the way the app's own "Accept" does it (driverId + status
// 'accepted'), so the driver sees it as an active ride and the rider's
// tracking screen picks it up. The old default status 'confirmed' hid the
// ride from both of them.

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$bookingId = trim((string) ($_POST['bookingId'] ?? ($input['bookingId'] ?? '')));
$driverId = trim((string) ($_POST['driverId'] ?? ($input['driverId'] ?? '')));
$status = normalizeBookingStatus($_POST['status'] ?? ($input['status'] ?? 'accepted'));

if ($bookingId === '' || $driverId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'bookingId and driverId required']);
    exit;
}

if (!in_array($status, ['accepted', 'arrived_pickup', 'in_progress'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Assignment status must be accepted, arrived_pickup or in_progress']);
    exit;
}

try {
    $booking = getFirebaseData('bookings/' . $bookingId);
    if (empty($booking) || !is_array($booking)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Booking not found']);
        exit;
    }

    $driver = getFirebaseData('drivers/' . $driverId);
    if (empty($driver) || !is_array($driver)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Driver not found']);
        exit;
    }
    if (strtolower((string) ($driver['status'] ?? '')) === 'disabled') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This driver is disabled']);
        exit;
    }

    $currentStatus = normalizeBookingStatus($booking['status'] ?? '');
    if (in_array($currentStatus, ['completed', 'cancelled'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Cannot assign a driver to a ' . $currentStatus . ' booking']);
        exit;
    }

    // Validate driver capacity before assignment
    if (!canDriverAcceptBooking($driverId, $bookingId)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Driver capacity insufficient for this booking. The booking requires more passengers than the driver\'s car can accommodate.'
        ]);
        exit;
    }

    $update = [
        'driverId' => $driverId,
        'status' => $status,
        'updatedAt' => time() * 1000,
        'assignedBy' => 'admin',
    ];
    if (!updateFirebaseData('bookings/' . $bookingId, $update)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update booking: ' . firebaseLastError()]);
        exit;
    }

    $pickup = (string) firstBookingValue($booking, ['pickupAddress', 'pickupLocation'], 'the pickup point');
    pushAppNotification($driverId, true, 'New ride assigned', 'Urban Drive assigned you a ride from ' . $pickup . '.', $bookingId);
    $riderId = trim((string) ($booking['userId'] ?? ''));
    if ($riderId !== '') {
        pushAppNotification($riderId, false, 'Driver assigned', 'A driver has been assigned to your booking.', $bookingId);
    }

    echo json_encode(['success' => true, 'status' => $status, 'driverId' => $driverId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

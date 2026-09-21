<?php
session_start();
require_once 'firebase-config.php';

requireAdminSession(true);
header('Content-Type: application/json');

// Records a commission payment the driver made outside ToyyibPay (cash at the
// office, bank transfer, ...). The record is written to
// commission_payment/{driverId} in exactly the shape the mobile app and the
// ToyyibPay backend use -- bookingIds + amount + status 'paid' -- so the
// driver's Total Earning screen shows those rides as paid immediately.
//
// The previous version wrote to commissions/{bookingId}, a path nothing else
// reads, so admin-recorded payments never reached the app.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$driverId = trim((string) ($input['driverId'] ?? ''));
$bookingIds = [];
if (isset($input['bookingIds']) && is_array($input['bookingIds'])) {
    foreach ($input['bookingIds'] as $id) {
        $id = trim((string) $id);
        if ($id !== '' && !in_array($id, $bookingIds, true)) {
            $bookingIds[] = $id;
        }
    }
} elseif (!empty($input['bookingId'])) {
    $bookingIds[] = trim((string) $input['bookingId']);
}
$method = trim((string) ($input['method'] ?? 'cash'));
$note = trim((string) ($input['note'] ?? ''));
$rate = isset($input['rate']) && is_numeric($input['rate']) ? (float) $input['rate'] : 20.0;

if ($driverId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'driverId is required']);
    exit;
}
if (empty($bookingIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Select at least one completed ride']);
    exit;
}

try {
    $bookings = getFirebaseData('bookings');
    if (!is_array($bookings)) {
        $bookings = [];
    }

    $fareTotal = 0.0;
    $accepted = [];
    $skipped = [];
    foreach ($bookingIds as $bookingId) {
        $booking = $bookings[$bookingId] ?? null;
        if (!is_array($booking)) {
            $skipped[] = $bookingId . ': not found';
            continue;
        }
        if (normalizeBookingStatus($booking['status'] ?? '') !== 'completed') {
            $skipped[] = $bookingId . ': ride is not completed';
            continue;
        }
        if (trim((string) ($booking['driverId'] ?? '')) !== $driverId) {
            $skipped[] = $bookingId . ': belongs to another driver';
            continue;
        }
        $fareTotal += bookingFareAmount($booking);
        $accepted[] = $bookingId;
    }

    if (empty($accepted)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'No valid rides to mark paid. ' . implode('; ', $skipped)]);
        exit;
    }

    $amount = round(($fareTotal * $rate) / 100, 2);
    if (isset($input['amount']) && is_numeric($input['amount']) && (float) $input['amount'] > 0) {
        $amount = round((float) $input['amount'], 2);
    }

    $now = time() * 1000;
    $record = [
        'bookingIds' => $accepted,
        'amount' => $amount,
        'status' => 'paid',
        'method' => $method !== '' ? $method : 'cash',
        'note' => $note,
        'reference' => 'ADMIN-' . strtoupper(substr($driverId, 0, 8)) . '-' . substr((string) $now, -6),
        'createdAt' => $now,
        'paid_at' => $now,
        'paid_by' => $_SESSION['admin_username'] ?? 'admin',
        'source' => 'admin',
    ];

    $paymentId = pushFirebaseData('commission_payment/' . $driverId, $record);
    if ($paymentId === null) {
        throw new Exception('Failed to save payment: ' . firebaseLastError());
    }

    pushAppNotification(
        $driverId,
        true,
        'Commission payment recorded',
        'Urban Drive recorded RM ' . number_format($amount, 2) . ' for ' . count($accepted) . ' ride(s). Thank you!'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Payment of RM ' . number_format($amount, 2) . ' recorded for ' . count($accepted) . ' ride(s)',
        'paymentId' => $paymentId,
        'amount' => $amount,
        'bookingIds' => $accepted,
        'skipped' => $skipped,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

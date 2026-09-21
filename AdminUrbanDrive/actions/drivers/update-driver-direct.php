<?php
session_start();
require_once 'firebase-config.php';

requireAdminSession(true);
header('Content-Type: application/json');

// Enables or disables a driver. Writes drivers/{driverId}/status, which the
// mobile app now checks on splash, login and the driver dashboard: a
// disabled driver is signed out with an explanation and cannot take rides.
// Disabling also forces the driver offline (isAvailable = false) so they
// drop out of the pending-job pool right away.

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$driverId = trim((string) ($input['driverId'] ?? ''));
$status = strtolower(trim((string) ($input['status'] ?? '')));
if ($status === 'approved') {
    $status = 'active';
}

if ($driverId === '' || $status === '') {
    echo json_encode(['success' => false, 'message' => 'Driver ID and status are required']);
    exit;
}

if (!in_array($status, ['active', 'disabled'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status. Must be "active" or "disabled"']);
    exit;
}

try {
    $currentDriver = getFirebaseData('drivers/' . $driverId);
    if (empty($currentDriver) || !is_array($currentDriver)) {
        echo json_encode(['success' => false, 'message' => 'Driver not found']);
        exit;
    }

    // PATCH only the fields that change; the old code PUT the whole record
    // back, which could wipe anything the app wrote in between.
    $update = [
        'status' => $status,
        'updated_at' => date('Y-m-d H:i:s'),
        'updatedAt' => time() * 1000,
        'statusChangedBy' => 'admin',
    ];
    if ($status === 'disabled') {
        $update['isAvailable'] = false;
    }

    if (!updateFirebaseData('drivers/' . $driverId, $update)) {
        echo json_encode(['success' => false, 'message' => 'Failed to update driver status: ' . firebaseLastError()]);
        exit;
    }

    if ($status === 'disabled') {
        pushAppNotification($driverId, true, 'Account disabled', 'Your driver account has been disabled by Urban Drive. Please contact the office.');
    } else {
        pushAppNotification($driverId, true, 'Account enabled', 'Your driver account is active again. You can go online and take rides.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'Driver ' . ($status === 'disabled' ? 'disabled' : 'enabled') . ' successfully',
        'driverId' => $driverId,
        'status' => $status
    ]);
} catch (Exception $e) {
    error_log('Error updating driver status: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

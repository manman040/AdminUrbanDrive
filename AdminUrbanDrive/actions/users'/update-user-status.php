<?php
session_start();
require_once 'firebase-config.php';

requireAdminSession(true);
header('Content-Type: application/json');

// Enables or disables a rider. Writes users/{userId}/status, which the mobile
// app now checks on splash, login and the rider dashboard: a disabled rider is
// signed out with an explanation and cannot book.

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$userId = trim((string) ($input['userId'] ?? ''));
$status = strtolower(trim((string) ($input['status'] ?? '')));

if ($userId === '' || $status === '') {
    echo json_encode(['success' => false, 'message' => 'User ID and status are required']);
    exit;
}

if (!in_array($status, ['active', 'disabled'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid status. Must be "active" or "disabled"']);
    exit;
}

try {
    $currentUser = getFirebaseData('users/' . $userId);
    if (empty($currentUser) || !is_array($currentUser)) {
        echo json_encode(['success' => false, 'message' => 'User not found']);
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

    if (!updateFirebaseData('users/' . $userId, $update)) {
        echo json_encode(['success' => false, 'message' => 'Failed to update user status: ' . firebaseLastError()]);
        exit;
    }

    if ($status === 'disabled') {
        pushAppNotification($userId, false, 'Account disabled', 'Your account has been disabled by Urban Drive. Please contact the office.');
    } else {
        pushAppNotification($userId, false, 'Account enabled', 'Your account is active again. You can book rides.');
    }

    echo json_encode([
        'success' => true,
        'message' => 'User ' . ($status === 'disabled' ? 'disabled' : 'enabled') . ' successfully',
        'userId' => $userId,
        'status' => $status
    ]);
} catch (Exception $e) {
    error_log('Error updating user status: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}

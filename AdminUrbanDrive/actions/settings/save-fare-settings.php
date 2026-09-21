<?php
session_start();
require_once 'firebase-config.php';

requireAdminSession(true);
header('Content-Type: application/json');

// Saves one ride category's fares and records the change in
// fareSettingsHistory (who, when, old -> new). The app reads fareSettings
// directly, so the new prices apply to the next booking preview immediately.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

$category = (string) ($input['category'] ?? '');
$validCategories = array_keys(fareSettingsDefaults());

if (!in_array($category, $validCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid ride category']);
    exit;
}

$amounts = [];
foreach (['baseFare', 'perKm', 'bookingFee'] as $field) {
    $value = $input[$field] ?? null;
    if (!is_numeric($value) || (float) $value < 0 || (float) $value > 10000) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Base fare, price per KM, and booking fee must all be numbers between 0 and 10,000'
        ]);
        exit;
    }
    $amounts[$field] = round((float) $value, 2);
}

try {
    $result = saveFareSettingsWithHistory(
        $category,
        $amounts['baseFare'],
        $amounts['perKm'],
        $amounts['bookingFee'],
        $_SESSION['admin_username'] ?? 'admin'
    );

    if (!$result['success']) {
        throw new Exception($result['message']);
    }

    echo json_encode([
        'success' => true,
        'message' => $result['message'],
        'category' => $category,
        'settings' => $result['settings'],
        'changes' => $result['changes'],
        'history' => $result['history'],
        'unchanged' => empty($result['changes']),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saving fare settings: ' . $e->getMessage()
    ]);
}

<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

$category = $input['category'] ?? '';
$validCategories = array_keys(fareSettingsDefaults());

if (!in_array($category, $validCategories, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid ride category']);
    exit;
}

$baseFare = isset($input['baseFare']) && is_numeric($input['baseFare']) ? (float) $input['baseFare'] : null;
$perKm = isset($input['perKm']) && is_numeric($input['perKm']) ? (float) $input['perKm'] : null;
$bookingFee = isset($input['bookingFee']) && is_numeric($input['bookingFee']) ? (float) $input['bookingFee'] : null;

if ($baseFare === null || $perKm === null || $bookingFee === null ||
    $baseFare < 0 || $perKm < 0 || $bookingFee < 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Base fare, price per KM, and booking fee must all be numbers of 0 or more'
    ]);
    exit;
}

try {
    $result = saveFareSettings($category, $baseFare, $perKm, $bookingFee);

    if ($result) {
        echo json_encode([
            'success' => true,
            'message' => 'Fare settings saved successfully',
            'category' => $category,
            'baseFare' => $baseFare,
            'perKm' => $perKm,
            'bookingFee' => $bookingFee
        ]);
    } else {
        throw new Exception('Failed to update Firebase');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error saving fare settings: ' . $e->getMessage()
    ]);
}
?>

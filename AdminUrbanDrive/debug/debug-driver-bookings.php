<?php
session_start();
require_once 'firebase-config.php';

// Optional: protect behind admin session if desired
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$driverId = $_GET['driverId'] ?? 'dc0aoTWFphdVK0ayxCbgIm6aLTq1'; // Default to second driver

try {
    // Get all bookings
    $bookings = getBookingsData();
    echo "<h2>Debug for Driver: $driverId</h2>";
    
    // Check if driver exists in drivers table
    $drivers = getFirebaseData('drivers');
    $driverName = 'Unknown';
    if (is_array($drivers) && isset($drivers[$driverId])) {
        $driverName = $drivers[$driverId]['name'] ?? ($drivers[$driverId]['driverName'] ?? 'Unknown');
    }
    echo "<p><strong>Driver Name:</strong> $driverName</p>";
    
    // Find all bookings for this driver
    $driverBookings = [];
    foreach ($bookings as $bookingId => $booking) {
        if (isset($booking['driverId']) && $booking['driverId'] === $driverId) {
            $driverBookings[$bookingId] = $booking;
        }
    }
    
    echo "<p><strong>Total bookings found for this driver:</strong> " . count($driverBookings) . "</p>";
    
    if (empty($driverBookings)) {
        echo "<p style='color: red;'><strong>No bookings found with driverId = $driverId</strong></p>";
        echo "<h3>All bookings (to check driverId values):</h3>";
        foreach ($bookings as $bookingId => $booking) {
            echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px;'>";
            echo "<strong>Booking ID:</strong> $bookingId<br>";
            echo "<strong>Driver ID:</strong> " . ($booking['driverId'] ?? 'NOT SET') . "<br>";
            echo "<strong>Status:</strong> " . ($booking['status'] ?? 'NOT SET') . "<br>";
            echo "<strong>Fare:</strong> " . ($booking['estimatedFare'] ?? 'NOT SET') . "<br>";
            echo "</div>";
        }
    } else {
        echo "<h3>Bookings for this driver:</h3>";
        foreach ($driverBookings as $bookingId => $booking) {
            $status = strtolower($booking['status'] ?? '');
            $fare = floatval($booking['estimatedFare'] ?? 0);
            $commission = ($fare * 20) / 100;
            
            echo "<div style='border: 1px solid #ccc; margin: 10px; padding: 10px;'>";
            echo "<strong>Booking ID:</strong> $bookingId<br>";
            echo "<strong>Status:</strong> $status<br>";
            echo "<strong>Fare:</strong> RM $fare<br>";
            echo "<strong>Commission:</strong> RM $commission<br>";
            echo "<strong>Will be included:</strong> " . (in_array($status, ['cancelled','rejected']) ? 'NO (cancelled/rejected)' : 'YES') . "<br>";
            echo "</div>";
        }
    }
    
    // Check current driver_commissions data
    $currentCommission = getFirebaseData('driver_commissions/' . $driverId);
    echo "<h3>Current driver_commissions data:</h3>";
    if ($currentCommission) {
        echo "<pre>" . json_encode($currentCommission, JSON_PRETTY_PRINT) . "</pre>";
    } else {
        echo "<p style='color: red;'>No driver_commissions data found for this driver</p>";
    }
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>
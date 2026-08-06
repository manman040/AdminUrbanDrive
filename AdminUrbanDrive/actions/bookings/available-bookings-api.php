<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once 'firebase-config.php';

// Get driver ID from URL parameter or POST data
$driverId = $_GET['driver_id'] ?? $_POST['driver_id'] ?? $_GET['driverId'] ?? $_POST['driverId'] ?? null;

if (!$driverId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Driver ID is required',
        'usage' => 'Use: ?driver_id=YOUR_DRIVER_ID'
    ]);
    exit;
}

try {
    // Get available bookings filtered by driver capacity
    $availableBookings = getAvailableBookingsForDriver($driverId);
    
    // Get driver capacity for response info
    $driverCapacity = getDriverCapacity($driverId);
    
    // Format bookings for response
    $formattedBookings = [];
    foreach ($availableBookings as $bookingId => $booking) {
        if (!is_array($booking)) {
            continue;
        }
        
        // Get passenger count
        $passengerCount = $booking['passengerCount'] ?? 
                         $booking['numberOfPassengers'] ?? 
                         $booking['passengers'] ?? 
                         $booking['passengerCapacity'] ?? 
                         1;
        
        $formattedBookings[] = [
            'bookingId' => $bookingId,
            'pickupLocation' => firstBookingValue($booking, ['pickupLocation', 'pickupAddress']),
            'dropoffLocation' => firstBookingValue($booking, ['dropoffLocation', 'dropoffAddress']),
            'pickupLat' => $booking['pickupLat'] ?? 0,
            'pickupLng' => $booking['pickupLng'] ?? 0,
            'dropoffLat' => $booking['dropoffLat'] ?? 0,
            'dropoffLng' => $booking['dropoffLng'] ?? 0,
            'distanceKm' => bookingDistanceKm($booking),
            'estimatedFare' => bookingFareAmount($booking),
            'passengerCount' => intval($passengerCount),
            'status' => strtolower($booking['status'] ?? 'pending'),
            'createdAt' => $booking['createdAt'] ?? null,
            'driverId' => $booking['driverId'] ?? null
        ];
    }
    
    // Return response
    echo json_encode([
        'success' => true,
        'driverId' => $driverId,
        'driverCapacity' => $driverCapacity,
        'count' => count($formattedBookings),
        'bookings' => $formattedBookings,
        'message' => count($formattedBookings) > 0 
            ? 'Found ' . count($formattedBookings) . ' available booking(s) matching your car capacity' 
            : 'No available bookings matching your car capacity',
        'timestamp' => time() * 1000
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching available bookings: ' . $e->getMessage(),
        'driverId' => $driverId
    ]);
}
?>


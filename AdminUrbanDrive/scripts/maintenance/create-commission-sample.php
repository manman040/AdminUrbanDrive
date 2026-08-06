<?php
require_once 'firebase-config.php';

// Sample commission data structure for Firebase
$sampleCommissions = [
    'commission_sample_1' => [
        'booking_id' => 'booking_123',
        'driver_name' => 'Ahmad Rahman',
        'driver_id' => 'driver_001',
        'fare_amount' => 25.50,
        'commission_rate' => 20,
        'commission_amount' => 5.10,
        'status' => 'paid',
        'created_at' => time() * 1000,
        'paid_at' => time() * 1000,
        'paid_by' => 'admin'
    ],
    'commission_sample_2' => [
        'booking_id' => 'booking_124',
        'driver_name' => 'Siti Aminah',
        'driver_id' => 'driver_002',
        'fare_amount' => 18.75,
        'commission_rate' => 20,
        'commission_amount' => 3.75,
        'status' => 'unpaid',
        'created_at' => time() * 1000,
        'paid_at' => null,
        'paid_by' => null
    ],
    'commission_sample_3' => [
        'booking_id' => 'booking_125',
        'driver_name' => 'Muhammad Ali',
        'driver_id' => 'driver_003',
        'fare_amount' => 32.00,
        'commission_rate' => 20,
        'commission_amount' => 6.40,
        'status' => 'paid',
        'created_at' => time() * 1000,
        'paid_at' => time() * 1000,
        'paid_by' => 'admin'
    ]
];

echo "Sample Commission Data Structure for Firebase:\n\n";
echo "Path: /commissions/\n\n";

foreach ($sampleCommissions as $key => $commission) {
    echo "Key: {$key}\n";
    echo json_encode($commission, JSON_PRETTY_PRINT) . "\n\n";
}

echo "To add this to your Firebase database:\n";
echo "1. Go to Firebase Console > Realtime Database\n";
echo "2. Navigate to the root level\n";
echo "3. Add a new child node called 'commissions'\n";
echo "4. Add the sample data above as child nodes\n\n";

echo "Commission Data Structure Explanation:\n";
echo "- booking_id: Links to the booking in your bookings table\n";
echo "- driver_name: Driver's full name for easy tracking\n";
echo "- driver_id: Driver's unique ID (for reference)\n";
echo "- fare_amount: Total fare paid by passenger\n";
echo "- commission_rate: Company's percentage cut (20%)\n";
echo "- commission_amount: Actual commission amount\n";
echo "- status: 'paid' or 'unpaid'\n";
echo "- created_at: When commission was created (Firebase timestamp)\n";
echo "- paid_at: When commission was paid (null if unpaid)\n";
echo "- paid_by: Admin username who marked it as paid\n";
?>

<?php
require_once 'firebase-config.php';

// Sample data structure for driver commissions in Firebase
echo "Firebase Data Structure for Driver Commissions:\n\n";

echo "1. DRIVER-SPECIFIC COMMISSION TRACKING:\n";
echo "Path: /driver_commissions/{driverId}/\n\n";

$sampleDriverId = "hsnA4Zk1SEYyE1QsdAHEhjchabJ2";
$sampleDriverCommission = [
    "driver_info" => [
        "driver_id" => $sampleDriverId,
        "driver_name" => "Ahmad Rahman",
        "phone" => "+60123456789",
        "email" => "ahmad@example.com"
    ],
    "commission_summary" => [
        "total_earnings" => 150.75,
        "total_commission" => 30.15,
        "paid_commission" => 15.00,
        "unpaid_commission" => 15.15,
        "last_updated" => time() * 1000,
        "commission_rate" => 20
    ],
    "recent_jobs" => [
        "job_1" => [
            "booking_id" => "-OYWMvXy2gLcdiveAwao",
            "fare_amount" => 25.50,
            "commission_amount" => 5.10,
            "status" => "paid",
            "job_date" => "2025-08-25",
            "paid_date" => "2025-08-26"
        ],
        "job_2" => [
            "booking_id" => "-OYWVro50BsTXp2s_Cie",
            "fare_amount" => 18.75,
            "commission_amount" => 3.75,
            "status" => "unpaid",
            "job_date" => "2025-08-25",
            "paid_date" => null
        ]
    ],
    "payment_history" => [
        "payment_1" => [
            "amount" => 15.00,
            "date" => "2025-08-26",
            "method" => "bank_transfer",
            "reference" => "TXN123456"
        ]
    ]
];

echo "Sample Driver Commission Record:\n";
echo json_encode($sampleDriverCommission, JSON_PRETTY_PRINT) . "\n\n";

echo "2. GLOBAL COMMISSION OVERVIEW:\n";
echo "Path: /commission_overview/\n\n";

$globalOverview = [
    "total_drivers" => 15,
    "total_commission_paid" => 1250.50,
    "total_commission_unpaid" => 340.25,
    "last_updated" => time() * 1000,
    "active_drivers" => 12
];

echo "Global Commission Overview:\n";
echo json_encode($globalOverview, JSON_PRETTY_PRINT) . "\n\n";

echo "3. HOW TO IMPLEMENT IN FIREBASE:\n\n";
echo "Step 1: Create the structure in Firebase Console:\n";
echo "- Go to Firebase Console > Realtime Database\n";
echo "- Navigate to root level\n";
echo "- Add 'driver_commissions' as a new child node\n";
echo "- For each driver, add their driverId as a child node\n";
echo "- Add the sample data structure above\n\n";

echo "Step 2: Update your admin panel to write to this structure:\n";
echo "- When a ride is completed, update both:\n";
echo "  - /bookings/{bookingId} (existing)\n";
echo "  - /driver_commissions/{driverId}/recent_jobs/{jobId}\n";
echo "  - /driver_commissions/{driverId}/commission_summary\n\n";

echo "Step 3: Create driver app endpoints:\n";
echo "- GET /driver_commissions/{driverId} - Get driver's commission info\n";
echo "- GET /driver_commissions/{driverId}/recent_jobs - Get recent jobs\n";
echo "- GET /driver_commissions/{driverId}/payment_history - Get payment history\n\n";

echo "4. DRIVER APP INTEGRATION:\n\n";
echo "Android/Flutter code example:\n";
echo "```dart\n";
echo "// Get driver commission data\n";
echo "final snapshot = await FirebaseDatabase.instance\n";
echo "    .ref('driver_commissions/\$driverId')\n";
echo "    .once();\n";
echo "\n";
echo "if (snapshot.snapshot.exists) {\n";
echo "    final data = snapshot.snapshot.value as Map<dynamic, dynamic>;\n";
echo "    final summary = data['commission_summary'];\n";
echo "    final unpaid = summary['unpaid_commission'];\n";
echo "    final paid = summary['paid_commission'];\n";
echo "    // Display in driver app\n";
echo "}\n";
echo "```\n\n";

echo "5. REAL-TIME UPDATES:\n";
echo "```dart\n";
echo "// Listen for real-time updates\n";
echo "FirebaseDatabase.instance\n";
echo "    .ref('driver_commissions/\$driverId/commission_summary')\n";
echo "    .onValue\n";
echo "    .listen((event) {\n";
echo "        // Update UI when commission data changes\n";
echo "        updateCommissionDisplay(event.snapshot.value);\n";
echo "    });\n";
echo "```\n";
?>

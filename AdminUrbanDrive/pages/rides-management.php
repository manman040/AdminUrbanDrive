<?php
session_start();
require_once 'firebase-config.php';

requireAdminSession();

// All rides, every status, newest first. Reads the booking schema the mobile
// app actually writes (userId / driverId / pickupAddress / fareLabel /
// paymentMethod / createdAt ...) and resolves rider and driver names from
// users/ and drivers/. The previous version printed date_time / customer /
// destination fields that never existed, so every cell came out blank.

if (!function_exists('h')) {
    function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}

$rides = [];
$loadError = '';
$metrics = ['total_rides_today' => 0, 'total_revenue' => 'RM 0.00', 'active_rides' => 0, 'average_rating' => 0.0];

try {
    $metrics = getMetrics();
    $bookings = getBookingsData();
    $usersData = getFirebaseData('users');
    $driversData = getFirebaseData('drivers');
    if (!is_array($bookings)) { $bookings = []; }
    if (!is_array($usersData)) { $usersData = []; }
    if (!is_array($driversData)) { $driversData = []; }

    foreach ($bookings as $bookingId => $booking) {
        if (!is_array($booking)) {
            continue;
        }

        $status = normalizeBookingStatus($booking['status'] ?? 'pending');
        $createdMs = (int) firebaseTimestampValue($booking['createdAt'] ?? 0);
        if ($createdMs > 0 && $createdMs < 10000000000) {
            $createdMs *= 1000; // stored in seconds
        }
        $userId = trim((string) ($booking['userId'] ?? ''));
        $driverId = trim((string) ($booking['driverId'] ?? ''));

        // Rider: name copied onto the booking by the app, else the users/ record, else email/id.
        $customer = trim((string) ($booking['userName'] ?? ''));
        if ($customer === '' && $userId !== '' && isset($usersData[$userId]) && is_array($usersData[$userId])) {
            $customer = trim((string) ($usersData[$userId]['name'] ?? ($usersData[$userId]['email'] ?? '')));
        }
        if ($customer === '') { $customer = trim((string) ($booking['userEmail'] ?? '')); }
        if ($customer === '') { $customer = $userId !== '' ? $userId : '-'; }

        $driver = '';
        if ($driverId !== '' && isset($driversData[$driverId]) && is_array($driversData[$driverId])) {
            $driver = trim((string) ($driversData[$driverId]['name'] ?? ''));
        }
        if ($driver === '' && $driverId !== '') { $driver = $driverId; }

        $payment = trim((string) ($booking['paymentMethod'] ?? ''));
        if (stripos($payment, 'qr') !== false) {
            $paymentKey = 'qr'; $paymentLabel = 'QR';
        } elseif (stripos($payment, 'cash') !== false) {
            $paymentKey = 'cash'; $paymentLabel = 'Cash';
        } elseif ($payment === '') {
            $paymentKey = ''; $paymentLabel = '-';
        } else {
            $paymentKey = strtolower($payment); $paymentLabel = $payment;
        }

        $rides[] = [
            'id' => (string) $bookingId,
            'created_ms' => $createdMs,
            'created_label' => $createdMs > 0 ? date('d M Y, h:i A', (int) floor($createdMs / 1000)) : '-',
            'customer' => $customer,
            'customer_id' => $userId,
            'driver' => $driver,
            'driver_id' => $driverId,
            'pickup' => (string) firstBookingValue($booking, ['pickupAddress', 'pickupLocation'], '-'),
            'dropoff' => (string) firstBookingValue($booking, ['dropoffAddress', 'dropoffLocation'], '-'),
            'distance_km' => bookingDistanceKm($booking),
            'fare' => bookingFareAmount($booking),
            'package' => (string) ($booking['packageLabel'] ?? ''),
            'payment_key' => $paymentKey,
            'payment_label' => $paymentLabel,
            'status' => $status,
            'status_label' => bookingStatusLabel($status),
            'is_active' => in_array($status, bookingActiveStatuses(), true),
            'rating' => !empty($booking['rated']) && is_numeric($booking['rating'] ?? null) ? (int) $booking['rating'] : 0,
        ];
    }

    usort($rides, function ($a, $b) { return $b['created_ms'] <=> $a['created_ms']; });
} catch (Exception $e) {
    $loadError = $e->getMessage();
}
if ($loadError === '' && empty($rides) && firebaseLastError() !== '') {
    $loadError = firebaseLastError();
}

$statusCounts = array_fill_keys(bookingStatuses(), 0);
foreach ($rides as $ride) {
    $statusCounts[$ride['status']] = ($statusCounts[$ride['status']] ?? 0) + 1;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Rides - Urban Drive</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 250px;
            background: white;
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
            padding: 20px 0;
        }

        .sidebar-header {
            padding: 0 20px 20px;
            border-bottom: 1px solid #eee;
            margin-bottom: 20px;
        }

        .sidebar-header h2 {
            color: #333;
            font-size: 18px;
        }

        .nav-item {
            padding: 15px 20px;
            display: flex;
            align-items: center;
            color: #666;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }

        .nav-item:hover, .nav-item.active {
            background: #fff5f2;
            color: #ff6b35;
            border-left-color: #ff6b35;
        }

        .nav-item i {
            margin-right: 10px;
            width: 20px;
        }

        .main-content {
            flex: 1;
            padding: 20px;
        }

        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .header h1 {
            color: #333;
            margin-bottom: 10px;
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .metric-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            text-align: center;
        }

        .metric-card.blue { border-left: 4px solid #3498db; }
        .metric-card.green { border-left: 4px solid #2ecc71; }
        .metric-card.orange { border-left: 4px solid #ff6b35; }
        .metric-card.purple { border-left: 4px solid #9b59b6; }

        .metric-value {
            font-size: 32px;
            font-weight: bold;
            margin-bottom: 5px;
        }

        .metric-label {
            color: #666;
            font-size: 14px;
        }

        .content-area {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 20px;
        }

        .filters {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
        }

        .filter-group label {
            margin-bottom: 5px;
            color: #666;
            font-size: 14px;
        }

        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }

        .rides-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .rides-table th,
        .rides-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        .rides-table th {
            background: #f8f9fa;
            font-weight: 600;
            color: #333;
        }

        .rides-table tr:hover {
            background: #f8f9fa;
        }

        .status-badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-completed {
            background: #d4edda;
            color: #155724;
        }

        .status-ongoing {
            background: #fff3cd;
            color: #856404;
        }

        .status-cancel {
            background: #f8d7da;
            color: #721c24;
        }

        .logout-btn {
            position: absolute;
            bottom: 20px;
            left: 20px;
            right: 20px;
            padding: 10px;
            background: #ff6b35;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            display: block;
        }

        .logout-btn:hover {
            background: #e55a2b;
        }

        /* Added with the schema fix: app status vocabulary, route cell, actions */
        .status-badge { text-transform: none; }
        .status-pending { background: #fef3c7; color: #92400e; }
        .status-accepted, .status-arrived_pickup { background: #dbeafe; color: #1e40af; }
        .status-in_progress { background: #e0e7ff; color: #3730a3; }
        .status-cancelled { background: #fee2e2; color: #991b1b; }
        .nav-item.active { background: #fff5f2; color: #ff6b35; border-left: 4px solid #ff6b35; }
        .route-cell { max-width: 340px; }
        .route-line { display: flex; gap: 8px; align-items: flex-start; font-size: 13px; line-height: 1.35; }
        .route-line + .route-line { margin-top: 4px; }
        .route-line i { margin-top: 3px; font-size: 11px; }
        .pickup-dot { color: #10b981; }
        .dropoff-dot { color: #ff6b35; }
        .rating-star { color: #f59e0b; }
        .muted { color: #94a3b8; font-style: italic; }
        .small { font-size: 12px; font-style: normal; margin-top: 4px; }
        .empty-row { text-align: center; color: #64748b; padding: 30px !important; }
        .table-wrap { overflow-x: auto; }
        .filter-count { display: flex; flex-direction: column; justify-content: flex-end; color: #64748b; font-size: 14px; }
        .cancel-btn { padding: 6px 10px; border: none; border-radius: 6px; background: #fee2e2; color: #991b1b; cursor: pointer; font-size: 12px; white-space: nowrap; }
        .cancel-btn:hover { background: #fecaca; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; border-radius: 8px; padding: 12px 16px; margin-bottom: 16px; font-size: 14px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Administrator</h2>
            </div>
            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-tachometer-alt"></i>
                Rides Management
            </a>
            <a href="rides-management.php" class="nav-item active">
                <i class="fas fa-route"></i>
                All Rides
            </a>
            <a href="driver-requests.php" class="nav-item">
                <i class="fas fa-user-plus"></i>
                Driver Request
            </a>
            <a href="users-drivers.php" class="nav-item">
                <i class="fas fa-users"></i>
                Users & Driver
            </a>
            <a href="driver-commission.php" class="nav-item">
                <i class="fas fa-dollar-sign"></i>
                Driver Commission
            </a>
            <a href="fare-settings.php" class="nav-item">
                <i class="fas fa-money-bill-wave"></i>
                Fare Settings
            </a>
            <a href="customer-feedback.php" class="nav-item">
                <i class="fas fa-comments"></i>
                Customer Feedback
            </a>
            <a href="reports.php" class="nav-item">
                <i class="fas fa-chart-line"></i>
                Reports
            </a>
            <a href="logout.php" class="logout-btn">
                <i class="fas fa-sign-out-alt"></i> Logout
            </a>
        </div>
        <div class="main-content">
            <div class="header">
                <h1>All Rides</h1>
            </div>
            <div class="metrics-grid">
                <div class="metric-card blue">
                    <div class="metric-value"><?php echo h($metrics['total_rides_today']); ?></div>
                    <div class="metric-label">Total Rides Today</div>
                </div>
                <div class="metric-card green">
                    <div class="metric-value"><?php echo h($metrics['total_revenue']); ?></div>
                    <div class="metric-label">Total Revenue</div>
                </div>
                <div class="metric-card orange">
                    <div class="metric-value"><?php echo h($metrics['active_rides']); ?></div>
                    <div class="metric-label">Active Rides</div>
                </div>
                <div class="metric-card purple">
                    <div class="metric-value"><?php echo h($metrics['average_rating']); ?></div>
                    <div class="metric-label">Average Rating</div>
                </div>
            </div>
            <div class="content-area">
                <?php if ($loadError !== ''): ?>
                <div class="alert-error"><i class="fas fa-exclamation-triangle"></i> Could not load rides: <?php echo h($loadError); ?></div>
                <?php endif; ?>
                <div class="filters">
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" placeholder="Rider, driver, address or booking ID">
                    </div>
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status">
                            <option value="">All Status (<?php echo count($rides); ?>)</option>
                            <?php foreach (bookingStatuses() as $statusKey): ?>
                            <option value="<?php echo h($statusKey); ?>"><?php echo h(bookingStatusLabel($statusKey)); ?> (<?php echo (int) $statusCounts[$statusKey]; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="methods">Payment</label>
                        <select id="methods">
                            <option value="">All Methods</option>
                            <option value="cash">Cash</option>
                            <option value="qr">QR</option>
                        </select>
                    </div>
                    <div class="filter-group filter-count">
                        <label>&nbsp;</label>
                        <div>Showing <strong id="shownCount"><?php echo count($rides); ?></strong> of <?php echo count($rides); ?> rides</div>
                    </div>
                </div>
                <div class="table-wrap">
                <table class="rides-table" id="ridesTable">
                    <thead>
                        <tr>
                            <th>Date &amp; Time</th>
                            <th>Customer</th>
                            <th>Driver</th>
                            <th>Route</th>
                            <th>Distance</th>
                            <th>Fare</th>
                            <th>Payment</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rides)): ?>
                        <tr><td colspan="9" class="empty-row">No rides yet. Bookings made in the app will appear here.</td></tr>
                        <?php else: foreach ($rides as $ride): ?>
                        <tr data-booking-id="<?php echo h($ride['id']); ?>" data-status="<?php echo h($ride['status']); ?>" data-payment="<?php echo h($ride['payment_key']); ?>">
                            <td>
                                <?php echo h($ride['created_label']); ?>
                                <div class="muted small">ID: <?php echo h($ride['id']); ?></div>
                            </td>
                            <td><?php echo h($ride['customer']); ?></td>
                            <td>
                                <?php if ($ride['driver'] !== ''): ?>
                                    <?php echo h($ride['driver']); ?>
                                <?php else: ?>
                                    <span class="muted">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td class="route-cell">
                                <div class="route-line"><i class="fas fa-circle pickup-dot"></i><span><?php echo h($ride['pickup']); ?></span></div>
                                <div class="route-line"><i class="fas fa-map-marker-alt dropoff-dot"></i><span><?php echo h($ride['dropoff']); ?></span></div>
                                <?php if ($ride['package'] !== ''): ?><div class="muted small"><?php echo h($ride['package']); ?></div><?php endif; ?>
                            </td>
                            <td><?php echo $ride['distance_km'] > 0 ? h(number_format($ride['distance_km'], 2)) . ' km' : '<span class="muted">-</span>'; ?></td>
                            <td><strong>RM <?php echo h(number_format($ride['fare'], 2)); ?></strong></td>
                            <td><?php echo h($ride['payment_label']); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo h($ride['status']); ?>"><?php echo h($ride['status_label']); ?></span>
                                <?php if ($ride['rating'] > 0): ?><div class="muted small"><i class="fas fa-star rating-star"></i> <?php echo (int) $ride['rating']; ?>/5</div><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ride['is_active']): ?>
                                <button type="button" class="cancel-btn" onclick="cancelRide('<?php echo h($ride['id']); ?>')"><i class="fas fa-ban"></i> Cancel</button>
                                <?php else: ?>
                                <span class="muted">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>

    <script>
        const searchInput = document.getElementById('search');
        const statusSelect = document.getElementById('status');
        const methodSelect = document.getElementById('methods');
        const shownCount = document.getElementById('shownCount');
        const rows = Array.from(document.querySelectorAll('#ridesTable tbody tr[data-booking-id]'));

        function applyFilters() {
            const q = searchInput.value.trim().toLowerCase();
            const status = statusSelect.value;
            const method = methodSelect.value;
            let shown = 0;
            rows.forEach(row => {
                const ok = (!q || row.textContent.toLowerCase().includes(q))
                    && (!status || row.dataset.status === status)
                    && (!method || row.dataset.payment === method);
                row.style.display = ok ? '' : 'none';
                if (ok) shown++;
            });
            shownCount.textContent = shown;
        }

        searchInput.addEventListener('input', applyFilters);
        statusSelect.addEventListener('change', applyFilters);
        methodSelect.addEventListener('change', applyFilters);

        function cancelRide(bookingId) {
            if (!confirm('Cancel this ride? The rider and driver will be notified in the app.')) return;
            fetch('update-booking-status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ bookingId: bookingId, status: 'cancelled' })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    location.reload();
                } else {
                    alert('Could not cancel ride: ' + (data.message || 'Unknown error'));
                }
            })
            .catch(err => alert('Could not cancel ride: ' + err));
        }
    </script>
</body>
</html>

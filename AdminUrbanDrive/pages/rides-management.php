<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Get real data from Firebase, fallback to mock data if Firebase is not configured
try {
    $rides = getRidesData();
    $metrics = getMetrics();
    
    // Convert Firebase data to array format if needed
    if (!is_array($rides)) {
        $rides = [];
    }
    
    // If rides is associative array (Firebase format), convert to indexed array for display
    $ridesArray = [];
    if (!empty($rides)) {
        foreach ($rides as $rideId => $ride) {
            $ride['id'] = $rideId; // Add the Firebase key as ID
            $ridesArray[] = $ride;
        }
    }
    $rides = $ridesArray;
    
} catch (Exception $e) {
    // Fallback to mock data if Firebase fails
    $rides = [
        [
            'date_time' => '10/5/2025 10.00AM',
            'customer' => 'Devi',
            'driver' => 'Mamu',
            'destination' => 'KL to Genting',
            'fare' => 'RM 11.00',
            'status' => 'Completed',
            'payment' => 'Cash'
        ],
        [
            'date_time' => '11/5/2025 10.00AM',
            'customer' => 'Polo',
            'driver' => 'Mami',
            'destination' => 'KL to Shah aley',
            'fare' => 'RM 6.00',
            'status' => 'On Going',
            'payment' => 'Qr'
        ],
        [
            'date_time' => '10/5/2025 10.00AM',
            'customer' => 'Mat',
            'driver' => 'Mamo',
            'destination' => 'Perda to KL',
            'fare' => 'RM 15000',
            'status' => 'Cancel',
            'payment' => 'Cash'
        ]
    ];
    
    $metrics = [
        'total_rides_today' => 12,
        'total_revenue' => 'RM 250.00',
        'active_rides' => 5,
        'average_rating' => 4.5
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rides Management - PerdaRide</title>
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
                <h1>Administrator</h1>
            </div>

            <div class="metrics-grid">
                <div class="metric-card blue">
                    <div class="metric-value"><?php echo $metrics['total_rides_today']; ?></div>
                    <div class="metric-label">Total Rides Today</div>
                </div>
                <div class="metric-card green">
                    <div class="metric-value"><?php echo $metrics['total_revenue']; ?></div>
                    <div class="metric-label">Total Revenue</div>
                </div>
                <div class="metric-card orange">
                    <div class="metric-value"><?php echo $metrics['active_rides']; ?></div>
                    <div class="metric-label">Active Rides</div>
                </div>
                <div class="metric-card purple">
                    <div class="metric-value"><?php echo $metrics['average_rating']; ?></div>
                    <div class="metric-label">Average Rating</div>
                </div>
            </div>

            <div class="content-area">
                <div class="filters">
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" placeholder="Search rides...">
                    </div>
                    <div class="filter-group">
                        <label for="status">Status</label>
                        <select id="status">
                            <option value="">All Status</option>
                            <option value="completed">Completed</option>
                            <option value="ongoing">On Going</option>
                            <option value="cancel">Cancel</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="methods">Methods</label>
                        <select id="methods">
                            <option value="">All Methods</option>
                            <option value="cash">Cash</option>
                            <option value="qr">QR</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="date">Date</label>
                        <input type="date" id="date">
                    </div>
                </div>

                <table class="rides-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Customer</th>
                            <th>Driver</th>
                            <th>Destination</th>
                            <th>Fare</th>
                            <th>Status</th>
                            <th>Payment</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rides as $ride): ?>
                        <tr>
                            <td><?php echo $ride['date_time']; ?></td>
                            <td><?php echo $ride['customer']; ?></td>
                            <td><?php echo $ride['driver']; ?></td>
                            <td><?php echo $ride['destination']; ?></td>
                            <td><?php echo $ride['fare']; ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($ride['status']); ?>">
                                    <?php echo $ride['status']; ?>
                                </span>
                            </td>
                            <td><?php echo $ride['payment']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        // Add search functionality
        document.getElementById('search').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const rows = document.querySelectorAll('.rides-table tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });

        // Add status filter functionality
        document.getElementById('status').addEventListener('change', function() {
            const status = this.value.toLowerCase();
            const rows = document.querySelectorAll('.rides-table tbody tr');
            
            rows.forEach(row => {
                const statusCell = row.querySelector('.status-badge');
                if (status === '' || statusCell.textContent.toLowerCase().includes(status)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>

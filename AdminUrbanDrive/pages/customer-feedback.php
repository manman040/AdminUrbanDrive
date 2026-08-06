<?php
session_start();
require_once 'firebase-config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
	header('Location: index.php');
	exit;
}

// Fetch ratings from Firebase: ratings/{driverId}/{ratingId}
$ratingsData = [];
try {
	$data = getFirebaseData('ratings');
	if (is_array($data)) {
		foreach ($data as $driverId => $driverRatings) {
			if (!is_array($driverRatings)) continue;
			foreach ($driverRatings as $ratingId => $entry) {
				$ratingsData[] = [
					'rating_id' => $ratingId,
					'driver_id' => $driverId,
					'booking_id' => $entry['bookingId'] ?? '',
					'comment' => $entry['comment'] ?? '',
					'rating' => floatval($entry['rating'] ?? 0),
					'timestamp' => $entry['timestamp'] ?? ''
				];
			}
		}
	}
} catch (Exception $e) {}

// Fetch driver names from drivers and driver_approvals
$driversData = [];
$driverApprovalsData = [];
try {
	$driversData = getFirebaseData('drivers');
	if (!is_array($driversData)) $driversData = [];
} catch (Exception $e) {
	$driversData = [];
}

try {
	$driverApprovalsData = getFirebaseData('driver_approvals');
	if (!is_array($driverApprovalsData)) $driverApprovalsData = [];
} catch (Exception $e) {
	$driverApprovalsData = [];
}

// Fetch bookings for ride details
$bookingsData = [];
try {
	$bookingsData = getFirebaseData('bookings');
	if (!is_array($bookingsData)) $bookingsData = [];
} catch (Exception $e) {
	$bookingsData = [];
}

// Helper function to get driver name
function getDriverName($driverId, $driversData, $driverApprovalsData) {
	if (isset($driversData[$driverId]) && is_array($driversData[$driverId])) {
		return $driversData[$driverId]['name'] ?? $driversData[$driverId]['driverName'] ?? $driversData[$driverId]['fullName'] ?? 'Unknown Driver';
	}
	if (isset($driverApprovalsData[$driverId]) && is_array($driverApprovalsData[$driverId])) {
		return $driverApprovalsData[$driverId]['name'] ?? $driverApprovalsData[$driverId]['driverName'] ?? $driverApprovalsData[$driverId]['fullName'] ?? 'Unknown Driver';
	}
	return 'Unknown Driver';
}

// Group ratings by driver ID and calculate stats
$driverStats = [];
foreach ($ratingsData as $rating) {
	$driverId = $rating['driver_id'];
	if (!isset($driverStats[$driverId])) {
		$driverStats[$driverId] = [
			'driver_id' => $driverId,
			'driver_name' => getDriverName($driverId, $driversData, $driverApprovalsData),
			'total_reviews' => 0,
			'total_rating' => 0.0,
			'average_rating' => 0.0,
			'comment_count' => 0,
			'ratings' => []
		];
	}
	
	$driverStats[$driverId]['total_reviews']++;
	$driverStats[$driverId]['total_rating'] += $rating['rating'];
	if (!empty($rating['comment'])) {
		$driverStats[$driverId]['comment_count']++;
	}
	$driverStats[$driverId]['ratings'][] = $rating;
}

// Calculate average ratings
foreach ($driverStats as &$stats) {
	if ($stats['total_reviews'] > 0) {
		$stats['average_rating'] = round($stats['total_rating'] / $stats['total_reviews'], 2);
	}
}
unset($stats);

// Sort by driver name
usort($driverStats, function($a, $b) {
	return strcmp($a['driver_name'], $b['driver_name']);
});

// Calculate overall metrics
$totalRatings = count($ratingsData);
$averageRating = 0.0;
if ($totalRatings > 0) {
	$totalRatingSum = array_sum(array_column($ratingsData, 'rating'));
	$averageRating = round($totalRatingSum / $totalRatings, 2);
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Customer Feedback - PerdaRide</title>
	<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body { font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height: 100vh; }
		.container { display: flex; min-height: 100vh; }
		.sidebar { width: 280px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); box-shadow: 4px 0 20px rgba(0,0,0,0.08); padding: 24px 0; position: relative; border-right: 1px solid rgba(0,0,0,0.05); }
		.sidebar-header { padding: 0 24px 24px; border-bottom: 2px solid #f1f5f9; margin-bottom: 24px; }
		.sidebar-header h2 { color: #1e293b; font-size: 20px; font-weight: 700; letter-spacing: -0.5px; }
		.nav-item { padding: 16px 24px; display: flex; align-items: center; color: #64748b; text-decoration: none; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); border-left: 4px solid transparent; font-weight: 500; font-size: 14px; }
		.nav-item:hover { background: linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%); color: #ff6b35; border-left-color: #ff6b35; transform: translateX(4px); }
		.nav-item.active { background: linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%); color: #ff6b35; border-left-color: #ff6b35; font-weight: 600; }
		.nav-item i { margin-right: 12px; width: 22px; font-size: 18px; }
		.logout-btn { position: absolute; bottom: 24px; left: 24px; right: 24px; padding: 14px; background: linear-gradient(135deg, #ff6b35 0%, #ff8c5a 100%); color: white; border: none; border-radius: 12px; cursor: pointer; text-decoration: none; text-align: center; display: flex; align-items: center; justify-content: center; gap: 8px; font-weight: 600; font-size: 14px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3); }
		.logout-btn:hover { background: linear-gradient(135deg, #e55a2b 0%, #ff6b35 100%); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4); }
		.logout-btn:active { transform: translateY(0); }
		.main-content { flex: 1; padding: 32px; overflow-x: auto; margin-top: 70px; }

		/* Top Navigation Bar */
		.top-nav {
			position: fixed;
			top: 0;
			left: 0;
			right: 0;
			height: 70px;
			background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
			box-shadow: 0 2px 10px rgba(0,0,0,0.08);
			display: flex;
			align-items: center;
			padding: 0 32px;
			z-index: 1000;
			border-bottom: 1px solid rgba(0,0,0,0.05);
		}

		.top-nav-brand {
			font-size: 24px;
			font-weight: 700;
			color: #ff6b35;
			margin-right: 40px;
			letter-spacing: -0.5px;
		}

		.top-nav-menu {
			display: flex;
			align-items: center;
			gap: 8px;
			flex: 1;
		}

		.top-nav-item {
			padding: 10px 20px;
			color: #64748b;
			text-decoration: none;
			font-weight: 500;
			font-size: 14px;
			border-radius: 10px;
			transition: all 0.2s ease;
			display: flex;
			align-items: center;
			gap: 8px;
		}

		.top-nav-item:hover {
			background: linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%);
			color: #ff6b35;
		}

		.top-nav-item.active {
			background: linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%);
			color: #ff6b35;
			font-weight: 600;
		}

		.top-nav-item i {
			font-size: 16px;
		}

		.top-nav-user {
			margin-left: auto;
			display: flex;
			align-items: center;
			gap: 12px;
		}

		.top-nav-user-name {
			color: #1e293b;
			font-weight: 600;
			font-size: 14px;
		}

		.top-nav-logout {
			padding: 8px 16px;
			background: linear-gradient(135deg, #ff6b35 0%, #ff8c5a 100%);
			color: white;
			border: none;
			border-radius: 10px;
			cursor: pointer;
			text-decoration: none;
			font-weight: 600;
			font-size: 13px;
			transition: all 0.2s ease;
			display: flex;
			align-items: center;
			gap: 6px;
		}

		.top-nav-logout:hover {
			background: linear-gradient(135deg, #e55a2b 0%, #ff6b35 100%);
			transform: translateY(-1px);
		}
		.header { background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 32px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 28px; border: 1px solid rgba(0,0,0,0.05); }
		.header h1 { color: #1e293b; margin-bottom: 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px; }
		.metrics { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 24px; margin-bottom: 32px; }
		.metric { background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 28px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border: 1px solid rgba(0,0,0,0.05); position: relative; overflow: hidden; }
		.metric::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #ff6b35, #ff8c5a); transform: scaleX(0); transition: transform 0.3s ease; }
		.metric:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,0.12); }
		.metric:hover::before { transform: scaleX(1); }
		.metric h3 { font-size: 13px; color: #64748b; margin-bottom: 8px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
		.metric .value { font-size: 36px; font-weight: 800; color: #1e293b; line-height: 1; letter-spacing: -1px; }
		.content-card { background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 28px; border: 1px solid rgba(0,0,0,0.05); }
		.filters { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
		.filters input { padding: 10px 16px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 14px; background: white; color: #1e293b; font-weight: 500; transition: all 0.2s ease; flex: 1; min-width: 200px; }
		.filters input:hover { border-color: #ff6b35; }
		.filters input:focus { outline: none; border-color: #ff6b35; box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1); }
		table { width: 100%; border-collapse: separate; border-spacing: 0; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
		th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
		th { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); font-weight: 700; color: #1e293b; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: 0; z-index: 10; }
		tr { transition: all 0.2s ease; }
		tr:hover { background: linear-gradient(90deg, #fff5f2 0%, #ffffff 100%); transform: scale(1.001); box-shadow: 0 2px 8px rgba(255, 107, 53, 0.1); }
		tr:last-child td { border-bottom: none; }
		.badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
		.badge-rating { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; border: 1px solid #93c5fd; }
		.driver-name-link { color: #ff6b35; font-weight: 600; cursor: pointer; text-decoration: none; transition: all 0.2s ease; }
		.driver-name-link:hover { color: #e55a2b; text-decoration: underline; }

		/* Modal Styles */
		.modal-overlay {
			position: fixed;
			inset: 0;
			background: rgba(15, 23, 42, 0.6);
			display: none;
			z-index: 2000;
			backdrop-filter: blur(4px);
			align-items: center;
			justify-content: center;
			padding: 20px;
		}
		.modal-overlay.active {
			display: flex;
		}
		.modal-content {
			background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
			border-radius: 20px;
			box-shadow: 0 20px 60px rgba(0,0,0,0.3);
			max-width: 900px;
			width: 100%;
			max-height: 90vh;
			overflow: hidden;
			display: flex;
			flex-direction: column;
			transform: scale(0.9);
			transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
		}
		.modal-overlay.active .modal-content {
			transform: scale(1);
		}
		.modal-header {
			padding: 24px 28px;
			border-bottom: 2px solid #f1f5f9;
			display: flex;
			align-items: center;
			justify-content: space-between;
			background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
		}
		.modal-header h2 {
			font-size: 24px;
			color: #1e293b;
			font-weight: 700;
			letter-spacing: -0.5px;
			margin: 0;
		}
		.modal-close {
			background: transparent;
			border: none;
			color: #64748b;
			font-size: 28px;
			cursor: pointer;
			width: 36px;
			height: 36px;
			display: flex;
			align-items: center;
			justify-content: center;
			border-radius: 8px;
			transition: all 0.2s ease;
		}
		.modal-close:hover {
			background: #f1f5f9;
			color: #1e293b;
		}
		.modal-body {
			padding: 24px 28px;
			overflow-y: auto;
			flex: 1;
		}
		.ride-detail-item {
			background: white;
			border-radius: 12px;
			padding: 20px;
			margin-bottom: 16px;
			border: 1px solid #e2e8f0;
			transition: all 0.2s ease;
		}
		.ride-detail-item:hover {
			box-shadow: 0 4px 12px rgba(0,0,0,0.1);
			transform: translateY(-2px);
		}
		.ride-detail-header {
			display: flex;
			justify-content: space-between;
			align-items: center;
			margin-bottom: 12px;
		}
		.ride-detail-header h3 {
			font-size: 16px;
			font-weight: 600;
			color: #1e293b;
			margin: 0;
		}
		.ride-detail-info {
			display: grid;
			grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
			gap: 12px;
			margin-top: 12px;
		}
		.ride-detail-info-item {
			display: flex;
			flex-direction: column;
			gap: 4px;
		}
		.ride-detail-info-label {
			font-size: 12px;
			color: #64748b;
			font-weight: 500;
			text-transform: uppercase;
			letter-spacing: 0.5px;
		}
		.ride-detail-info-value {
			font-size: 14px;
			color: #1e293b;
			font-weight: 600;
		}
		.ride-comment {
			margin-top: 12px;
			padding: 12px;
			background: #f8fafc;
			border-radius: 8px;
			border-left: 4px solid #ff6b35;
		}
		.ride-comment-text {
			font-size: 14px;
			color: #1e293b;
			font-style: italic;
		}
	</style>
</head>
<body>
	<!-- Top Navigation Bar -->
	<nav class="top-nav">
		<div class="top-nav-brand">PerdaRide</div>
		<div class="top-nav-menu">
			<a href="dashboard.php" class="top-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>">
				<i class="fas fa-tachometer-alt"></i>
				<span>Rides Management</span>
			</a>
			<a href="driver-requests.php" class="top-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'driver-requests.php' ? 'active' : ''; ?>">
				<i class="fas fa-user-plus"></i>
				<span>Driver Request</span>
			</a>
			<a href="users-drivers.php" class="top-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'users-drivers.php' ? 'active' : ''; ?>">
				<i class="fas fa-users"></i>
				<span>Users & Driver</span>
			</a>
			<a href="driver-commission.php" class="top-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'driver-commission.php' ? 'active' : ''; ?>">
				<i class="fas fa-dollar-sign"></i>
				<span>Driver Commission</span>
			</a>
			<a href="customer-feedback.php" class="top-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'customer-feedback.php' ? 'active' : ''; ?>">
				<i class="fas fa-comments"></i>
				<span>Customer Feedback</span>
			</a>
			<a href="reports.php" class="top-nav-item <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>">
				<i class="fas fa-chart-line"></i>
				<span>Reports</span>
			</a>
		</div>
		<div class="top-nav-user">
			<span class="top-nav-user-name">Admin</span>
			<a href="logout.php" class="top-nav-logout">
				<i class="fas fa-sign-out-alt"></i>
				<span>Logout</span>
			</a>
		</div>
	</nav>

	<div class="container">
		<div class="sidebar">
			<div class="sidebar-header">
				<h2>PerdaRide</h2>
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
			<a href="customer-feedback.php" class="nav-item active">
				<i class="fas fa-comments"></i>
				Customer Feedback
			</a>
			<a href="reports.php" class="nav-item">
				<i class="fas fa-chart-line"></i>
				Reports
			</a>

			<a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
		</div>

		<div class="main-content">
			<div class="header">
				<h1>Customer Feedback</h1>
			</div>

			<div class="metrics">
				<div class="metric">
					<h3>Total Ratings</h3>
					<div class="value"><?php echo h($totalRatings); ?></div>
				</div>
				<div class="metric">
					<h3>Average Rating</h3>
					<div class="value"><?php echo h($averageRating); ?> / 5</div>
				</div>
				<div class="metric">
					<h3>Total Drivers</h3>
					<div class="value"><?php echo h(count($driverStats)); ?></div>
				</div>
			</div>

			<div class="content-card">
				<div class="filters">
					<input type="text" id="search" placeholder="Search by driver name...">
				</div>

				<table id="driversTable">
					<thead>
						<tr>
							<th>Driver Name</th>
							<th>Total Reviews</th>
							<th>Average Rating</th>
							<th>Comments</th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($driverStats)): ?>
						<tr><td colspan="4" style="text-align:center;color:#666;">No driver ratings found</td></tr>
						<?php else: foreach ($driverStats as $stats): ?>
						<tr>
							<td>
								<a href="#" class="driver-name-link" onclick="showDriverDetails('<?php echo h($stats['driver_id']); ?>', '<?php echo h(addslashes($stats['driver_name'])); ?>'); return false;">
									<?php echo h($stats['driver_name']); ?>
								</a>
							</td>
							<td><?php echo h($stats['total_reviews']); ?></td>
							<td><span class="badge badge-rating"><?php echo h($stats['average_rating']); ?></span></td>
							<td><?php echo h($stats['comment_count']); ?></td>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- Modal for Driver Details -->
	<div class="modal-overlay" id="driverModal">
		<div class="modal-content">
			<div class="modal-header">
				<h2 id="modalDriverName">Driver Details</h2>
				<button class="modal-close" onclick="closeDriverModal()">&times;</button>
			</div>
			<div class="modal-body" id="modalBody">
				<!-- Content will be loaded here -->
			</div>
		</div>
	</div>

	<script>
		const driverStats = <?php echo json_encode($driverStats); ?>;
		const bookingsData = <?php echo json_encode($bookingsData); ?>;

		function showDriverDetails(driverId, driverName) {
			const stats = driverStats.find(s => s.driver_id === driverId);
			if (!stats) return;

			document.getElementById('modalDriverName').textContent = driverName + ' - Ride Details';
			const modalBody = document.getElementById('modalBody');
			modalBody.innerHTML = '';

			if (stats.ratings.length === 0) {
				modalBody.innerHTML = '<p style="text-align:center;color:#666;padding:40px;">No ride details available</p>';
			} else {
				stats.ratings.forEach((rating, index) => {
					const booking = bookingsData[rating.booking_id] || {};
					const rideItem = document.createElement('div');
					rideItem.className = 'ride-detail-item';
					
					const pickup = booking.pickupLocation || booking.pickup_address || 'N/A';
					const dropoff = booking.dropoffLocation || booking.dropoff_address || 'N/A';
					const fare = booking.fare || booking.totalFare || booking.amount || 'N/A';
					const status = booking.status || 'N/A';
					const date = rating.timestamp || 'N/A';

					rideItem.innerHTML = `
						<div class="ride-detail-header">
							<h3>Ride #${index + 1}</h3>
							<span class="badge badge-rating">${rating.rating} / 5</span>
						</div>
						<div class="ride-detail-info">
							<div class="ride-detail-info-item">
								<span class="ride-detail-info-label">Booking ID</span>
								<span class="ride-detail-info-value">${rating.booking_id || 'N/A'}</span>
							</div>
							<div class="ride-detail-info-item">
								<span class="ride-detail-info-label">Date & Time</span>
								<span class="ride-detail-info-value">${date}</span>
							</div>
							<div class="ride-detail-info-item">
								<span class="ride-detail-info-label">Status</span>
								<span class="ride-detail-info-value">${status}</span>
							</div>
							<div class="ride-detail-info-item">
								<span class="ride-detail-info-label">Fare</span>
								<span class="ride-detail-info-value">${typeof fare === 'number' ? 'RM ' + fare.toFixed(2) : fare}</span>
							</div>
						</div>
						<div class="ride-detail-info">
							<div class="ride-detail-info-item" style="grid-column: 1 / -1;">
								<span class="ride-detail-info-label">Pickup Location</span>
								<span class="ride-detail-info-value">${pickup}</span>
							</div>
							<div class="ride-detail-info-item" style="grid-column: 1 / -1;">
								<span class="ride-detail-info-label">Dropoff Location</span>
								<span class="ride-detail-info-value">${dropoff}</span>
							</div>
						</div>
						${rating.comment ? `
							<div class="ride-comment">
								<div class="ride-detail-info-label" style="margin-bottom:6px;">Customer Comment</div>
								<div class="ride-comment-text">"${rating.comment}"</div>
							</div>
						` : ''}
					`;
					modalBody.appendChild(rideItem);
				});
			}

			document.getElementById('driverModal').classList.add('active');
		}

		function closeDriverModal() {
			document.getElementById('driverModal').classList.remove('active');
		}

		// Close modal on overlay click
		document.getElementById('driverModal').addEventListener('click', function(e) {
			if (e.target === this) {
				closeDriverModal();
			}
		});

		// Close modal on Escape key
		document.addEventListener('keydown', function(e) {
			if (e.key === 'Escape') {
				closeDriverModal();
			}
		});

		// Search functionality
		const search = document.getElementById('search');
		search.addEventListener('input', function() {
			const q = (this.value || '').toLowerCase();
			document.querySelectorAll('#driversTable tbody tr').forEach(row => {
				const txt = row.textContent.toLowerCase();
				row.style.display = txt.includes(q) ? '' : 'none';
			});
		});
	</script>
</body>
</html>

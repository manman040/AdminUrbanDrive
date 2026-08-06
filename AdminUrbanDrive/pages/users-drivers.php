<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
	header('Location: index.php');
	exit;
}

// Fetch Users and Drivers from Firebase with safe fallback
try {
	$usersData = getFirebaseData('users');
	$driversData = getFirebaseData('drivers');
	
	// Also fetch driver_approvals to get complete application data (car, plate, capacity)
	$driverApprovalsData = [];
	try {
		$driverApprovalsData = getFirebaseData('driver_approvals');
		if (!is_array($driverApprovalsData)) {
			$driverApprovalsData = [];
		}
	} catch (Exception $e) {
		$driverApprovalsData = [];
	}

	// Ensure data is array
	if ($usersData === null || !is_array($usersData)) {
		$usersData = [];
	}
	if ($driversData === null || !is_array($driversData)) {
		$driversData = [];
	}

	$users = [];
	if (is_array($usersData) && !empty($usersData)) {
		foreach ($usersData as $userId => $user) {
			// Skip if user is not an array
			if (!is_array($user)) {
				continue;
			}
			$user['id'] = $userId;
			$users[] = $user;
		}
	}

	$drivers = [];
	if (is_array($driversData) && !empty($driversData)) {
		foreach ($driversData as $driverId => $driver) {
			// Skip if driver is not an array
			if (!is_array($driver)) {
				continue;
			}
			$driver['id'] = $driverId;
			
			// Merge with driver_approvals data if available (for car, plate, capacity info)
			if (isset($driverApprovalsData[$driverId]) && is_array($driverApprovalsData[$driverId])) {
				$approvalData = $driverApprovalsData[$driverId];
				
				// Merge car information from approvals if not in driver data
				if (empty($driver['car_type']) && empty($driver['carType'])) {
					$vehicleBrand = $approvalData['vehicleBrand'] ?? $approvalData['vehicle_brand'] ?? '';
					$vehicleModel = $approvalData['vehicleModel'] ?? $approvalData['vehicle_model'] ?? '';
					if (!empty($vehicleBrand) || !empty($vehicleModel)) {
						$driver['car_type'] = trim($vehicleBrand . ' ' . $vehicleModel);
					}
				}
				
				// Merge plate from approvals if not in driver data
				if (empty($driver['plate'])) {
					$driver['plate'] = $approvalData['carPlateNumber'] ?? $approvalData['plateNumber'] ?? $approvalData['vehiclePlate'] ?? $approvalData['car_plate_number'] ?? '';
				}
				
				// Merge capacity from approvals if not in driver data
				if (empty($driver['capacity'])) {
					$driver['capacity'] = $approvalData['passengerCapacity'] ?? $approvalData['passenger_capacity'] ?? '';
				}
			}
			
			// Normalize field names - check multiple possible field names
			// Car: vehicleBrand + vehicleModel, or carType, or car_type, or vehicle
			if (empty($driver['car_type']) && empty($driver['carType'])) {
				$vehicleBrand = $driver['vehicleBrand'] ?? $driver['vehicle_brand'] ?? '';
				$vehicleModel = $driver['vehicleModel'] ?? $driver['vehicle_model'] ?? '';
				if (!empty($vehicleBrand) || !empty($vehicleModel)) {
					$driver['car_type'] = trim($vehicleBrand . ' ' . $vehicleModel);
				} else {
					$driver['car_type'] = $driver['vehicle'] ?? $driver['car'] ?? '';
				}
			} else {
				$driver['car_type'] = $driver['car_type'] ?? $driver['carType'] ?? '';
			}
			
			// Plate: carPlateNumber, or plate, or plateNumber
			if (empty($driver['plate'])) {
				$driver['plate'] = $driver['carPlateNumber'] ?? $driver['car_plate_number'] ?? $driver['plateNumber'] ?? '';
			}
			
			// Capacity: passengerCapacity, or capacity, or passenger_capacity
			if (empty($driver['capacity'])) {
				$driver['capacity'] = $driver['passengerCapacity'] ?? $driver['passenger_capacity'] ?? '';
			}
			
			// Rating: rating (check if it's a number or object)
			if (isset($driver['rating'])) {
				if (is_array($driver['rating'])) {
					$driver['rating'] = $driver['rating']['average'] ?? $driver['rating']['value'] ?? $driver['rating']['total'] ?? '';
				}
			}
			
			$driverStatus = strtolower($driver['status'] ?? 'pending');
			if (in_array($driverStatus, ['approved', 'active'], true)) {
				$drivers[] = $driver;
			}
		}
	}

} catch (Exception $e) {
	$users = [];
	$drivers = [];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Users & Drivers - PerdaRide</title>
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

		.main-content { flex: 1; padding: 32px; overflow-x: auto; }
		.header { background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 32px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); margin-bottom: 28px; border: 1px solid rgba(0,0,0,0.05); }
		.header h1 { color: #1e293b; margin-bottom: 0; font-size: 28px; font-weight: 700; letter-spacing: -0.5px; }

		.tabs { display: flex; gap: 8px; border-bottom: 2px solid #f1f5f9; margin-bottom: 24px; }
		.tab { padding: 12px 20px; cursor: pointer; border-bottom: 3px solid transparent; border-radius: 10px 10px 0 0; font-weight: 600; font-size: 14px; color: #64748b; transition: all 0.2s ease; }
		.tab:hover { background: #f8fafc; color: #ff6b35; }
		.tab.active { color: #ff6b35; border-bottom-color: #ff6b35; background: linear-gradient(135deg, #fff5f2 0%, #ffffff 100%); }

		.content-card { background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); padding: 28px; border: 1px solid rgba(0,0,0,0.05); }
		.filters { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
		.filters input, .filters select { padding: 10px 16px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 14px; background: white; color: #1e293b; font-weight: 500; transition: all 0.2s ease; }
		.filters input:hover, .filters select:hover { border-color: #ff6b35; }
		.filters input:focus, .filters select:focus { outline: none; border-color: #ff6b35; box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1); }

		table { width: 100%; border-collapse: separate; border-spacing: 0; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
		th, td { padding: 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
		th { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); font-weight: 700; color: #1e293b; font-size: 13px; text-transform: uppercase; letter-spacing: 0.5px; position: sticky; top: 0; z-index: 10; }
		tr { transition: all 0.2s ease; }
		tr:hover { background: linear-gradient(90deg, #fff5f2 0%, #ffffff 100%); transform: scale(1.001); box-shadow: 0 2px 8px rgba(255, 107, 53, 0.1); }
		tr:last-child td { border-bottom: none; }

		/* Compact tables: only Name, Phone, Status, Actions stay visible - the rest is reachable via the eye-button details modal */
		#usersTable th:nth-child(2), #usersTable td:nth-child(2),
		#usersTable th:nth-child(5), #usersTable td:nth-child(5) { display: none; }
		#driversTable th:nth-child(2), #driversTable td:nth-child(2),
		#driversTable th:nth-child(4), #driversTable td:nth-child(4),
		#driversTable th:nth-child(5), #driversTable td:nth-child(5),
		#driversTable th:nth-child(6), #driversTable td:nth-child(6),
		#driversTable th:nth-child(8), #driversTable td:nth-child(8) { display: none; }

		.badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
		.badge-success { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; border: 1px solid #10b981; }
		.badge-warning { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; border: 1px solid #f59e0b; }
		.badge-info { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; border: 1px solid #3b82f6; }

		.action-btn { padding: 8px 16px; border: none; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600; margin-right: 8px; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
		.action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
		.action-btn:active { transform: translateY(0); }
		.view-btn { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #fff; }
		.view-btn:hover { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
		.disable-btn { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #fff; }
		.disable-btn:hover { background: linear-gradient(135deg, #d97706 0%, #b45309 100%); }
		.enable-btn { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; }
		.enable-btn:hover { background: linear-gradient(135deg, #059669 0%, #047857 100%); }
		.delete-btn { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: #fff; }
		.delete-btn:hover { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); }

		/* Delete Confirmation Modal */
		.delete-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); display: none; z-index: 1300; backdrop-filter: blur(4px); }
		.delete-overlay.active { display: flex; align-items: center; justify-content: center; }
		.delete-modal { width: 100%; max-width: 420px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius: 18px; padding: 28px 32px; box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25); text-align: center; animation: scaleIn 0.2s ease; border: 1px solid rgba(0,0,0,0.05); }
		.delete-modal header { margin-bottom: 16px; }
		.delete-modal .icon { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
		.delete-modal .icon i { font-size: 32px; color: #dc2626; }
		.delete-modal h3 { font-size: 20px; color: #1e293b; margin-bottom: 8px; font-weight: 700; }
		.delete-modal p { font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 24px; }
		.delete-modal .name { font-weight: 700; color: #1e293b; }
		.delete-actions { display: flex; justify-content: center; gap: 14px; }
		.delete-btn-modal { min-width: 120px; border: none; border-radius: 999px; padding: 12px 20px; font-weight: 600; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; font-size: 14px; }
		.delete-btn-modal:active { transform: scale(0.97); }
		.delete-btn-modal.cancel { background: #f1f5f9; color: #475569; }
		.delete-btn-modal.cancel:hover { background: #e2e8f0; }
		.delete-btn-modal.confirm { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: #fff; box-shadow: 0 10px 30px rgba(239, 68, 68, 0.35); }
		.delete-btn-modal.confirm:hover { background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); box-shadow: 0 12px 40px rgba(239, 68, 68, 0.45); }
		.delete-btn-modal:disabled { opacity: 0.7; cursor: not-allowed; transform: none; }
		@keyframes scaleIn {
			from { transform: scale(0.92); opacity: 0; }
			to { transform: scale(1); opacity: 1; }
		}

		/* View Details Modal */
		.view-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); display: none; z-index: 1300; backdrop-filter: blur(4px); }
		.view-overlay.active { display: flex; align-items: center; justify-content: center; }
		.view-modal { width: 100%; max-width: 500px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius: 18px; padding: 28px 32px; box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25); animation: scaleIn 0.2s ease; border: 1px solid rgba(0,0,0,0.05); }
		.view-modal header { margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; }
		.view-modal header h3 { font-size: 22px; color: #1e293b; font-weight: 700; margin: 0; }
		.view-modal header .close-btn { width: 32px; height: 32px; border-radius: 8px; background: #f1f5f9; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #64748b; transition: all 0.2s ease; }
		.view-modal header .close-btn:hover { background: #e2e8f0; color: #1e293b; }
		.view-details { display: flex; flex-direction: column; gap: 16px; }
		.detail-row { display: flex; align-items: center; padding: 12px 0; border-bottom: 1px solid #f1f5f9; }
		.detail-row:last-child { border-bottom: none; }
		.detail-row label { font-weight: 600; color: #475569; min-width: 120px; font-size: 14px; }
		.detail-row span { color: #1e293b; font-size: 14px; flex: 1; }

		/* Confirmation Modal */
		.confirm-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); display: none; z-index: 1300; backdrop-filter: blur(4px); }
		.confirm-overlay.active { display: flex; align-items: center; justify-content: center; }
		.confirm-modal { width: 100%; max-width: 420px; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius: 18px; padding: 28px 32px; box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25); text-align: center; animation: scaleIn 0.2s ease; border: 1px solid rgba(0,0,0,0.05); }
		.confirm-modal header { margin-bottom: 16px; }
		.confirm-modal .icon { width: 64px; height: 64px; border-radius: 50%; background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
		.confirm-modal .icon i { font-size: 32px; color: #3b82f6; }
		.confirm-modal h3 { font-size: 20px; color: #1e293b; margin-bottom: 8px; font-weight: 700; }
		.confirm-modal p { font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 24px; }
		.confirm-actions { display: flex; justify-content: center; gap: 14px; }
		.confirm-btn-modal { min-width: 120px; border: none; border-radius: 999px; padding: 12px 20px; font-weight: 600; cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; font-size: 14px; }
		.confirm-btn-modal:active { transform: scale(0.97); }
		.confirm-btn-modal.cancel { background: #f1f5f9; color: #475569; }
		.confirm-btn-modal.cancel:hover { background: #e2e8f0; }
		.confirm-btn-modal.confirm { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #fff; box-shadow: 0 10px 30px rgba(59, 130, 246, 0.35); }
		.confirm-btn-modal.confirm:hover { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); box-shadow: 0 12px 40px rgba(59, 130, 246, 0.45); }

		/* Toast Notification */
		.app-toast { position: fixed; top: 24px; right: 24px; min-width: 240px; background: #0f172a; color: #fff; padding: 14px 18px; border-radius: 12px; box-shadow: 0 15px 35px rgba(15, 23, 42, 0.25); opacity: 0; transform: translateY(-10px); pointer-events: none; transition: opacity 0.2s ease, transform 0.2s ease; z-index: 1500; font-size: 14px; }
		.app-toast.visible { opacity: 1; transform: translateY(0); }
		.app-toast.success { background: #16a34a; }
		.app-toast.error { background: #dc2626; }

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

		/* Adjust main content for top nav */
		.main-content {
			margin-top: 70px;
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
			<a href="users-drivers.php" class="nav-item active">
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

			<a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
		</div>

		<div class="main-content">
			<div class="header">
				<h1>Users & Drivers</h1>
			</div>

			<div class="content-card">
				<div class="tabs">
					<div class="tab active" data-tab="users" onclick="switchTab(event, 'users')">Users</div>
					<div class="tab" data-tab="drivers" onclick="switchTab(event, 'drivers')">Drivers</div>
				</div>

				<!-- Users Tab -->
				<div id="tab-users" class="tab-pane active">
					<div class="filters">
						<input type="text" id="userSearch" placeholder="Search users by name/email...">
						<select id="userStatus">
							<option value="">All Status</option>
							<option value="active">Active</option>
							<option value="disabled">Disabled</option>
						</select>
					</div>

					<table id="usersTable">
						<thead>
							<tr>
								<th>Name</th>
								<th>Email</th>
								<th>Phone</th>
								<th>Status</th>
								<th>Created</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php if (empty($users)): ?>
							<tr><td colspan="6" style="text-align:center;color:#666;">No users found</td></tr>
							<?php else: foreach ($users as $u): ?>
							<tr data-user-id="<?php echo $u['id']; ?>">
								<td><?php echo htmlspecialchars($u['name'] ?? ''); ?></td>
								<td><?php echo htmlspecialchars($u['email'] ?? ''); ?></td>
								<td><?php echo htmlspecialchars($u['phone'] ?? ''); ?></td>
								<td>
									<?php $status = strtolower($u['status'] ?? 'active'); ?>
									<span class="badge <?php echo $status === 'active' ? 'badge-success' : 'badge-warning'; ?>"><?php echo ucfirst($status); ?></span>
								</td>
								<td><?php echo htmlspecialchars($u['created_at'] ?? ''); ?></td>
								<td>
									<button class="action-btn view-btn" onclick="viewUser('<?php echo $u['id']; ?>')" title="View Details"><i class="fas fa-eye"></i></button>
									<?php if (($u['status'] ?? 'active') === 'active'): ?>
										<button class="action-btn disable-btn" onclick="toggleUser('<?php echo $u['id']; ?>','disabled')" title="Disable User"><i class="fas fa-ban"></i> Disable</button>
									<?php else: ?>
										<button class="action-btn enable-btn" onclick="toggleUser('<?php echo $u['id']; ?>','active')" title="Enable User"><i class="fas fa-check"></i> Enable</button>
									<?php endif; ?>
									<button class="action-btn delete-btn" onclick="deleteUser('<?php echo $u['id']; ?>', '<?php echo htmlspecialchars(addslashes($u['name'] ?? 'User')); ?>')" title="Delete User"><i class="fas fa-trash"></i> Delete</button>
								</td>
							</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>

				<!-- Drivers Tab -->
				<div id="tab-drivers" class="tab-pane" style="display:none;">
					<div class="filters">
						<input type="text" id="driverSearch" placeholder="Search drivers by name/email/plate...">
					</div>

					<table id="driversTable">
						<thead>
							<tr>
								<th>Name</th>
								<th>Email</th>
								<th>Phone</th>
								<th>Car</th>
								<th>Plate</th>
								<th>Capacity</th>
								<th>Status</th>
								<th>Rating</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php if (empty($drivers)): ?>
							<tr><td colspan="9" style="text-align:center;color:#666;">No drivers found</td></tr>
							<?php else: foreach ($drivers as $d): ?>
							<tr data-driver-id="<?php echo $d['id']; ?>">
								<td><?php echo htmlspecialchars($d['name'] ?? ''); ?></td>
								<td><?php echo htmlspecialchars($d['email'] ?? ''); ?></td>
								<td><?php echo htmlspecialchars($d['phone'] ?? ''); ?></td>
								<td>
									<?php 
									$carType = trim($d['car_type'] ?? '');
									if (!empty($carType)) {
										echo htmlspecialchars($carType);
									} else {
										echo '<span style="color: #94a3b8; font-style: italic;">N/A</span>';
									}
									?>
								</td>
								<td>
									<?php 
									$plate = trim($d['plate'] ?? '');
									if (!empty($plate)) {
										echo '<span style="font-weight: 600; color: #1e293b;">' . htmlspecialchars($plate) . '</span>';
									} else {
										echo '<span style="color: #94a3b8; font-style: italic;">N/A</span>';
									}
									?>
								</td>
								<td>
									<?php 
									$capacity = $d['capacity'] ?? '';
									if (!empty($capacity) && $capacity > 0) {
										echo '<span style="display: inline-flex; align-items: center; gap: 6px;"><i class="fas fa-users" style="color: #64748b; font-size: 12px;"></i>' . htmlspecialchars((string)$capacity) . '</span>';
									} else {
										echo '<span style="color: #94a3b8; font-style: italic;">N/A</span>';
									}
									?>
								</td>
								<td>
									<span class="badge badge-success">Active</span>
								</td>
								<td>
									<?php 
									$rating = $d['rating'] ?? '';
									if (!empty($rating) && is_numeric($rating) && $rating > 0) {
										$ratingValue = (float)$rating;
										$stars = min(5, max(0, round($ratingValue)));
										echo '<span style="display: inline-flex; align-items: center; gap: 4px;">';
										for ($i = 0; $i < 5; $i++) {
											if ($i < $stars) {
												echo '<i class="fas fa-star" style="color: #fbbf24; font-size: 12px;"></i>';
											} else {
												echo '<i class="far fa-star" style="color: #cbd5e1; font-size: 12px;"></i>';
											}
										}
										echo '<span style="margin-left: 4px; font-weight: 600; color: #1e293b;">' . number_format($ratingValue, 1) . '</span>';
										echo '</span>';
									} else {
										echo '<span style="color: #94a3b8; font-style: italic;">N/A</span>';
									}
									?>
								</td>
								<td>
									<button class="action-btn view-btn" onclick="viewDriver('<?php echo $d['id']; ?>')" title="View Details"><i class="fas fa-eye"></i></button>
									<button class="action-btn delete-btn" onclick="deleteDriver('<?php echo $d['id']; ?>', '<?php echo htmlspecialchars(addslashes($d['name'] ?? 'Driver')); ?>')" title="Delete Driver"><i class="fas fa-trash"></i> Delete</button>
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
	function switchTab(evt, tab) {
		// tabs header
		document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
		evt.currentTarget.classList.add('active');
		// panes
		document.getElementById('tab-users').style.display = tab === 'users' ? '' : 'none';
		document.getElementById('tab-drivers').style.display = tab === 'drivers' ? '' : 'none';
	}

	// Simple client-side filters
	document.getElementById('userSearch').addEventListener('input', function() {
		const q = this.value.toLowerCase();
		document.querySelectorAll('#usersTable tbody tr').forEach(row => {
			row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
		});
	});
	document.getElementById('userStatus').addEventListener('change', function() {
		const v = this.value.toLowerCase();
		document.querySelectorAll('#usersTable tbody tr').forEach(row => {
			const badge = row.querySelector('td:nth-child(4) .badge');
			row.style.display = !v || (badge && badge.textContent.toLowerCase() === v) ? '' : 'none';
		});
	});

	document.getElementById('driverSearch').addEventListener('input', function() {
		const q = this.value.toLowerCase();
		document.querySelectorAll('#driversTable tbody tr').forEach(row => {
			row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
		});
	});

	// View functions
	let viewTargetId = null;
	let viewTargetType = null;
	let viewTargetData = null;

	function viewUser(id) {
		// Find user data from table
		const row = document.querySelector(`#usersTable tbody tr[data-user-id="${id}"]`);
		if (!row) {
			showToast('User data not found', 'error');
			return;
		}
		
		const userData = {
			id: id,
			name: row.cells[0].textContent.trim(),
			email: row.cells[1].textContent.trim(),
			phone: row.cells[2].textContent.trim(),
			status: row.cells[3].querySelector('.badge')?.textContent.trim() || 'active',
			created: row.cells[4].textContent.trim() || 'N/A'
		};
		
		viewTargetType = 'user';
		viewTargetData = userData;
		openViewModal('user', userData);
	}

	function viewDriver(id) {
		// Find driver data from table
		const row = document.querySelector(`#driversTable tbody tr[data-driver-id="${id}"]`);
		if (!row) {
			showToast('Driver data not found', 'error');
			return;
		}
		
		const driverData = {
			id: id,
			name: row.cells[0].textContent.trim(),
			email: row.cells[1].textContent.trim(),
			phone: row.cells[2].textContent.trim(),
			car: row.cells[3].textContent.trim() || 'N/A',
			plate: row.cells[4].textContent.trim() || 'N/A',
			capacity: row.cells[5].textContent.trim() || 'N/A',
			status: row.cells[6].querySelector('.badge')?.textContent.trim() || 'pending',
			rating: row.cells[7].textContent.trim() || 'N/A'
		};
		
		viewTargetType = 'driver';
		viewTargetData = driverData;
		openViewModal('driver', driverData);
	}

	function openViewModal(type, data) {
		const overlay = document.getElementById('viewOverlay');
		const modal = document.getElementById('viewModal');
		const title = document.getElementById('viewTitle');
		const content = document.getElementById('viewContent');
		
		title.textContent = 'View ' + (type === 'user' ? 'User' : 'Driver') + ' Details';
		
		let html = '<div class="view-details">';
		
		if (type === 'user') {
			html += `
				<div class="detail-row">
					<label>Name:</label>
					<span>${data.name || 'N/A'}</span>
				</div>
				<div class="detail-row">
					<label>Email:</label>
					<span>${data.email || 'N/A'}</span>
				</div>
				<div class="detail-row">
					<label>Phone:</label>
					<span>${data.phone || 'N/A'}</span>
				</div>
				<div class="detail-row">
					<label>Status:</label>
					<span class="badge ${(data.status || '').toLowerCase() === 'active' ? 'badge-success' : 'badge-warning'}">${data.status || 'active'}</span>
				</div>
				<div class="detail-row">
					<label>Created:</label>
					<span>${data.created || 'N/A'}</span>
				</div>
			`;
		} else {
			html += `
				<div class="detail-row">
					<label>Name:</label>
					<span>${data.name || 'N/A'}</span>
				</div>
				<div class="detail-row">
					<label>Email:</label>
					<span>${data.email || 'N/A'}</span>
				</div>
				<div class="detail-row">
					<label>Phone:</label>
					<span>${data.phone || 'N/A'}</span>
				</div>
				<div class="detail-row">
					<label>Car:</label>
					<span>${data.car || 'N/A'}</span>
				</div>
				<div class="detail-row">
					<label>Plate Number:</label>
					<span style="font-weight: 600;">${data.plate || 'N/A'}</span>
				</div>
				<div class="detail-row">
					<label>Capacity:</label>
					<span><i class="fas fa-users"></i> ${data.capacity || 'N/A'}</span>
				</div>
				<div class="detail-row">
					<label>Status:</label>
					<span class="badge badge-success">${data.status || 'Active'}</span>
				</div>
				<div class="detail-row">
					<label>Rating:</label>
					<span>${data.rating || 'N/A'}</span>
				</div>
			`;
		}
		
		html += '</div>';
		content.innerHTML = html;
		overlay.classList.add('active');
	}

	function closeViewModal() {
		const overlay = document.getElementById('viewOverlay');
		overlay.classList.remove('active');
		viewTargetId = null;
		viewTargetType = null;
		viewTargetData = null;
	}

	// Status update functions
	function toggleUser(id, status) {
		const action = status === 'active' ? 'enable' : 'disable';
		openConfirmModal(
			`${action.charAt(0).toUpperCase() + action.slice(1)} User`,
			`Are you sure you want to ${action} this user?`,
			() => {
				fetch('update-user-status.php', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
					},
					body: JSON.stringify({
						userId: id,
						status: status
					})
				})
				.then(response => response.json())
				.then(data => {
					if (data.success) {
						showToast(data.message || 'User status updated successfully', 'success');
						setTimeout(() => location.reload(), 1000);
					} else {
						showToast(data.message || 'Error updating user status', 'error');
					}
				})
				.catch(error => {
					console.error('Error:', error);
					showToast('An error occurred while updating user status', 'error');
				});
			}
		);
	}


	// Generic confirmation modal
	let confirmCallback = null;
	function openConfirmModal(title, message, callback) {
		const overlay = document.getElementById('confirmOverlay');
		const modal = document.getElementById('confirmModal');
		const modalTitle = document.getElementById('confirmTitle');
		const modalMessage = document.getElementById('confirmMessage');
		
		modalTitle.textContent = title;
		modalMessage.textContent = message;
		confirmCallback = callback;
		
		overlay.classList.add('active');
	}

	function closeConfirmModal() {
		const overlay = document.getElementById('confirmOverlay');
		overlay.classList.remove('active');
		confirmCallback = null;
	}

	function executeConfirm() {
		if (confirmCallback) {
			confirmCallback();
			closeConfirmModal();
		}
	}

	// Delete functions
	let deleteTargetId = null;
	let deleteTargetType = null;

	function deleteUser(id, name) {
		deleteTargetId = id;
		deleteTargetType = 'user';
		openDeleteModal('user', name, id);
	}

	function deleteDriver(id, name) {
		deleteTargetId = id;
		deleteTargetType = 'driver';
		openDeleteModal('driver', name, id);
	}

	function openDeleteModal(type, name, id) {
		const overlay = document.getElementById('deleteOverlay');
		const modal = document.getElementById('deleteModal');
		const title = document.getElementById('deleteTitle');
		const message = document.getElementById('deleteMessage');
		
		title.textContent = 'Delete ' + (type === 'user' ? 'User' : 'Driver');
		message.innerHTML = 'Are you sure you want to delete <span class="name">' + name + '</span>?<br><br>This action cannot be undone.';
		
		overlay.classList.add('active');
	}

	function closeDeleteModal() {
		const overlay = document.getElementById('deleteOverlay');
		overlay.classList.remove('active');
		deleteTargetId = null;
		deleteTargetType = null;
	}

	function confirmDelete() {
		if (!deleteTargetId || !deleteTargetType) return;
		
		const confirmBtn = document.getElementById('confirmDeleteBtn');
		confirmBtn.disabled = true;
		confirmBtn.textContent = 'Deleting...';
		
		fetch('delete-user-driver.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
			},
			body: JSON.stringify({
				id: deleteTargetId,
				type: deleteTargetType
			})
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				showToast(data.message || (deleteTargetType === 'user' ? 'User' : 'Driver') + ' deleted successfully', 'success');
				closeDeleteModal();
				setTimeout(() => location.reload(), 1000);
			} else {
				showToast(data.message || 'Error deleting ' + deleteTargetType, 'error');
				confirmBtn.disabled = false;
				confirmBtn.textContent = 'Delete';
			}
		})
		.catch(error => {
			console.error('Error:', error);
			showToast('An error occurred while deleting', 'error');
			confirmBtn.disabled = false;
			confirmBtn.textContent = 'Delete';
		});
	}

	function showToast(message, type = 'success') {
		const toast = document.getElementById('toast');
		toast.textContent = message;
		toast.className = 'app-toast ' + type + ' visible';
		setTimeout(() => {
			toast.classList.remove('visible');
		}, 3000);
	}

	// Close modal on overlay click
	document.getElementById('deleteOverlay').addEventListener('click', function(e) {
		if (e.target === this) {
			closeDeleteModal();
		}
	});

	// Close modal on Escape key
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			closeDeleteModal();
			closeViewModal();
			closeConfirmModal();
		}
	});

	// Close view modal on overlay click
	document.getElementById('viewOverlay').addEventListener('click', function(e) {
		if (e.target === this) {
			closeViewModal();
		}
	});

	// Close confirm modal on overlay click
	document.getElementById('confirmOverlay').addEventListener('click', function(e) {
		if (e.target === this) {
			closeConfirmModal();
		}
	});
	</script>

	<!-- Delete Confirmation Modal -->
	<div id="deleteOverlay" class="delete-overlay">
		<div id="deleteModal" class="delete-modal" onclick="event.stopPropagation()">
			<header>
				<div class="icon">
					<i class="fas fa-exclamation-triangle"></i>
				</div>
				<h3 id="deleteTitle">Delete User</h3>
				<p id="deleteMessage"></p>
			</header>
			<div class="delete-actions">
				<button class="delete-btn-modal cancel" onclick="closeDeleteModal()">Cancel</button>
				<button class="delete-btn-modal confirm" id="confirmDeleteBtn" onclick="confirmDelete()">Delete</button>
			</div>
		</div>
	</div>

	<!-- View Details Modal -->
	<div id="viewOverlay" class="view-overlay">
		<div id="viewModal" class="view-modal" onclick="event.stopPropagation()">
			<header>
				<h3 id="viewTitle">View Details</h3>
				<button class="close-btn" onclick="closeViewModal()">
					<i class="fas fa-times"></i>
				</button>
			</header>
			<div id="viewContent"></div>
		</div>
	</div>

	<!-- Confirmation Modal -->
	<div id="confirmOverlay" class="confirm-overlay">
		<div id="confirmModal" class="confirm-modal" onclick="event.stopPropagation()">
			<header>
				<div class="icon">
					<i class="fas fa-question-circle"></i>
				</div>
				<h3 id="confirmTitle">Confirm Action</h3>
				<p id="confirmMessage"></p>
			</header>
			<div class="confirm-actions">
				<button class="confirm-btn-modal cancel" onclick="closeConfirmModal()">Cancel</button>
				<button class="confirm-btn-modal confirm" onclick="executeConfirm()">Confirm</button>
			</div>
		</div>
	</div>

	<!-- Toast Notification -->
	<div id="toast" class="app-toast"></div>
</body>
</html>

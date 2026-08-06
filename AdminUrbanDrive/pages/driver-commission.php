<?php
session_start();
require_once 'firebase-config.php';

// Protect page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
	header('Location: index.php');
	exit;
}

// Configurable commission rate (company cut)
$companyCommissionRate = 20; // percent

// Fetch driver commissions data
try {
	$driverCommissionsData = getFirebaseData('driver_commissions');
} catch (Exception $e) {
	$driverCommissionsData = [];
}

// Fetch commission_payment data to get actual paid amounts
$commissionPayments = [];
try {
	$commissionPaymentData = getFirebaseData('commission_payment');
	if (is_array($commissionPaymentData)) {
		$commissionPayments = $commissionPaymentData;
	}
} catch (Exception $e) {
	$commissionPayments = [];
}

// Calculate actual paid amounts from commission_payment table
// Also track which jobs are paid using FIFO (First-In-First-Out) allocation
$actualPaidByDriver = [];
$paidJobIdsByDriver = []; // Track which booking IDs are paid for each driver

if (is_array($commissionPayments)) {
	foreach ($commissionPayments as $driverId => $payments) {
		if (!is_array($payments)) continue;
		
		$totalPaidForDriver = 0.0;
		$paidJobIds = []; // Booking IDs that have been paid
		
		// Sort payments by date (oldest first) for FIFO allocation
		$sortedPayments = [];
		foreach ($payments as $paymentId => $payment) {
			if (!is_array($payment)) continue;
			$status = strtolower($payment['status'] ?? '');
			// The app also accepts 'success'/'completed' as paid-equivalent statuses,
			// so this must match or a driver's outstanding balance disagrees between
			// the app and this dashboard for the exact same payment record.
			if (in_array($status, ['paid', 'success', 'completed'], true)) {
				$paymentTime = $payment['paid_at'] ?? $payment['created_at'] ?? $payment['timestamp'] ?? 0;
				if (is_array($paymentTime)) {
					$paymentTime = $paymentTime['time'] ?? 0;
				}
				$sortedPayments[] = [
					'id' => $paymentId,
					'amount' => floatval($payment['amount'] ?? 0),
					'time' => $paymentTime,
					'job_ids' => $payment['job_ids'] ?? $payment['booking_ids'] ?? [] // If payment already has job IDs
				];
			}
		}
		
		// Sort by time (oldest first)
		usort($sortedPayments, function($a, $b) {
			return $a['time'] <=> $b['time'];
		});
		
		foreach ($sortedPayments as $payment) {
			$amount = $payment['amount'];
			$totalPaidForDriver += $amount;
			
			// If payment already has job_ids, use them
			if (!empty($payment['job_ids']) && is_array($payment['job_ids'])) {
				foreach ($payment['job_ids'] as $jobId) {
					$paidJobIds[$jobId] = true;
				}
			}
		}
		
		if ($totalPaidForDriver > 0) {
			$actualPaidByDriver[$driverId] = $totalPaidForDriver;
			$paidJobIdsByDriver[$driverId] = $paidJobIds;
		}
	}
}

// Fetch active drivers to filter out deleted ones
$activeDrivers = [];
try {
	$driversData = getFirebaseData('drivers');
	if (is_array($driversData)) {
		$activeDrivers = array_keys($driversData);
	}
} catch (Exception $e) {
	$activeDrivers = [];
}

// Fetch bookings to count actual jobs per driver, and to compute each driver's
// real total fare/commission live from completed rides. The driver_commissions
// aggregate (commission_summary.total_earnings/total_commission) is only ever
// written once, when the driver is approved -- the app never updates it as rides
// complete, so it goes stale. Bookings is the source of truth instead.
$driverJobCounts = [];
$driverFareTotals = [];
try {
	$bookings = getFirebaseData('bookings');
	if (is_array($bookings)) {
		foreach ($bookings as $bookingId => $booking) {
			if (!is_array($booking)) continue;
			$status = strtolower($booking['status'] ?? '');
			// Only count completed/confirmed bookings
			if (in_array($status, ['completed', 'confirmed', 'finished', 'done'])) {
				$driverId = $booking['driverId'] ?? null;
				if ($driverId) {
					if (!isset($driverJobCounts[$driverId])) {
						$driverJobCounts[$driverId] = 0;
						$driverFareTotals[$driverId] = 0.0;
					}
					$driverJobCounts[$driverId]++;
					$driverFareTotals[$driverId] += bookingFareAmount($booking);
				}
			}
		}
	}
} catch (Exception $e) {
	// If bookings fetch fails, job counts will remain empty
	$driverJobCounts = [];
	$driverFareTotals = [];
}

// Process driver commissions data
$driverCommissions = [];
$totalCommissionCents = 0;
$totalPaidCents = 0;
$totalUnpaidCents = 0;

if (is_array($driverCommissionsData)) {
	foreach ($driverCommissionsData as $driverId => $data) {
		// Skip commissions for drivers that no longer exist
		if (!empty($activeDrivers) && !in_array($driverId, $activeDrivers, true)) {
			continue;
		}
		$driverInfo = $data['driver_info'] ?? [];
		$recentJobs = $data['recent_jobs'] ?? [];

		$driverName = $driverInfo['driver_name'] ?? 'Unknown Driver';
		// Computed live from completed bookings -- see comment above $driverFareTotals.
		$totalFare = floatval($driverFareTotals[$driverId] ?? 0);
		$totalCommissionAmount = $totalFare * ($companyCommissionRate / 100);
		$totalCommissionForDriverCents = (int) round($totalCommissionAmount * 100);
		
		// Get actual paid amount from commission_payment table
		$paidCommissionRaw = isset($actualPaidByDriver[$driverId]) ? $actualPaidByDriver[$driverId] : 0.0;
		$paidCommissionCents = (int) round($paidCommissionRaw * 100);
		if ($paidCommissionCents > $totalCommissionForDriverCents && $totalCommissionForDriverCents > 0) {
			$paidCommissionCents = $totalCommissionForDriverCents;
		}
		
		// Calculate unpaid in cents to avoid floating point jitter
		$unpaidCommissionCentsForDriver = max($totalCommissionForDriverCents - $paidCommissionCents, 0);
		
		// Allocate payments to jobs using FIFO (if not already allocated in payment records)
		$paidJobIds = $paidJobIdsByDriver[$driverId] ?? [];
		$remainingPaymentCents = $paidCommissionCents;
		
		// Sort jobs by date (oldest first) for FIFO allocation
		$jobsArray = [];
		foreach ($recentJobs as $jobKey => $job) {
			$jobDate = 0;
			if (isset($job['job_date'])) {
				$jobDate = is_numeric($job['job_date']) ? $jobDate : strtotime($job['job_date']);
			} elseif (isset($job['completed_at'])) {
				$completedAt = $job['completed_at'];
				if (is_array($completedAt)) {
					$jobDate = $completedAt['time'] ?? 0;
				} elseif (is_numeric($completedAt)) {
					$jobDate = $completedAt;
				}
			}
			$jobsArray[] = [
				'key' => $jobKey,
				'job' => $job,
				'date' => $jobDate
			];
		}
		usort($jobsArray, function($a, $b) {
			return $a['date'] <=> $b['date'];
		});
		
		// Allocate payments to jobs (FIFO)
		$processedJobs = [];
		foreach ($jobsArray as $jobItem) {
			$job = $jobItem['job'];
			$bookingId = $job['booking_id'] ?? $jobItem['key'] ?? '';
			$jobCommissionCents = (int) round((floatval($job['commission_amount'] ?? 0)) * 100);
			
			// Check if this job is already marked as paid in payment records
			if (!empty($paidJobIds) && isset($paidJobIds[$bookingId])) {
				$job['status'] = 'paid';
				$job['paid_date'] = $job['paid_date'] ?? date('Y-m-d');
			} elseif ($remainingPaymentCents > 0 && $jobCommissionCents > 0) {
				// Allocate payment to this job
				if ($remainingPaymentCents >= $jobCommissionCents) {
					$job['status'] = 'paid';
					$job['paid_date'] = $job['paid_date'] ?? date('Y-m-d');
					$remainingPaymentCents -= $jobCommissionCents;
				} else {
					// Partial payment - mark as partially paid
					$job['status'] = 'partial';
					$remainingPaymentCents = 0;
				}
			} else {
				// No payment left, job remains unpaid
				$job['status'] = $job['status'] ?? 'unpaid';
			}
			
			$processedJobs[$jobItem['key']] = $job;
		}
		
		// Calculate job count: prefer bookings count (source of truth), but use recent_jobs count if it's higher
		// This handles cases where recent_jobs might have more jobs than what's currently in bookings
		$bookingsJobCount = isset($driverJobCounts[$driverId]) ? $driverJobCounts[$driverId] : 0;
		$recentJobsCount = count($processedJobs);
		
		// Use the higher count to ensure accuracy
		// If recent_jobs is populated, it's likely more accurate for historical data
		// If recent_jobs is empty but we have commission, use bookings count
		if ($recentJobsCount > 0) {
			$jobCount = max($recentJobsCount, $bookingsJobCount);
		} else {
			// If recent_jobs is empty, use bookings count
			$jobCount = $bookingsJobCount;
		}
		
		// Get payment deadline
		$paymentDeadline = $data['payment_deadline'] ?? null;
		$deadlineTimestamp = null;
		$deadlineDate = null;
		$isOverdue = false;
		
		if ($paymentDeadline && is_array($paymentDeadline)) {
			$deadlineTimestamp = $paymentDeadline['time'] ?? null;
			$deadlineDate = $paymentDeadline['date'] ?? null;
			
			// Check if overdue (only if there's unpaid commission)
			if ($deadlineTimestamp && $unpaidCommissionCentsForDriver > 0) {
				$currentTime = time() * 1000; // Current time in milliseconds
				$isOverdue = $currentTime > $deadlineTimestamp;
			}
		}
		
		$driverCommissions[$driverId] = [
			'driver_id' => $driverId,
			'driver_name' => $driverName,
			'jobs' => $processedJobs,
			'total_fare' => $totalFare,
			'total_commission' => $totalCommissionForDriverCents / 100,
			'paid_commission' => $paidCommissionCents / 100,
			'unpaid_commission' => $unpaidCommissionCentsForDriver / 100,
			'job_count' => $jobCount,
			'payment_deadline' => [
				'timestamp' => $deadlineTimestamp,
				'date' => $deadlineDate,
				'is_overdue' => $isOverdue
			]
		];
		
		$totalCommissionCents += $totalCommissionForDriverCents;
		$totalPaidCents += $paidCommissionCents;
		$totalUnpaidCents += $unpaidCommissionCentsForDriver;
	}
}

// Convert to array for display
$rows = array_values($driverCommissions);

$totalCommission = $totalCommissionCents / 100;
$totalPaid = $totalPaidCents / 100;
$totalUnpaid = $totalUnpaidCents / 100;

function rm($n) { return 'RM ' . number_format((float)$n, 2); }
?>

<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Driver Commission - PerdaRide</title>
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
		.metric .value { font-size: 32px; font-weight: 800; color: #1e293b; line-height: 1; letter-spacing: -1px; }

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
		.badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
		.badge-success { background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color: #065f46; border: 1px solid #10b981; }
		.badge-warning { background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #92400e; border: 1px solid #f59e0b; }
		.badge-info { background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color: #1e40af; border: 1px solid #3b82f6; }
		.badge-danger { background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color: #991b1b; border: 1px solid #dc2626; }
		.action-btn { padding: 8px 16px; border: none; border-radius: 10px; cursor: pointer; font-size: 13px; font-weight: 600; margin-right: 8px; transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
		.action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
		.action-btn:active { transform: translateY(0); }
		.pay-btn { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: #fff; }
		.pay-btn:hover { background: linear-gradient(135deg, #059669 0%, #047857 100%); }
		.deadline-btn { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: #fff; }
		.deadline-btn:hover { background: linear-gradient(135deg, #d97706 0%, #b45309 100%); }
		.view-btn { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: #fff; }
		.view-btn:hover { background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }

		/* Slide-over (float page) */
		.drawer-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.55); display: none; z-index: 999; backdrop-filter: blur(4px); }
		.drawer-overlay.active { display: block; }
		.drawer { position: absolute; top: 0; right: 0; height: 100%; width: 900px; max-width: 90vw; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); box-shadow: -4px 0 30px rgba(0,0,0,0.3); transform: translateX(100%); transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); display: flex; flex-direction: column; border-left: 1px solid rgba(0,0,0,0.05); }
		.drawer.active { transform: translateX(0); }
		.drawer-header { padding: 24px 28px; border-bottom: 2px solid #f1f5f9; display: flex; align-items: center; justify-content: space-between; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); }
		.drawer-header h2 { font-size: 20px; color: #1e293b; font-weight: 700; letter-spacing: -0.5px; }
		.drawer-body { padding: 24px 28px; overflow: auto; flex: 1; }
		.drawer-actions { display: flex; gap: 12px; align-items: center; margin-bottom: 16px; flex-wrap: wrap; }
		.close-btn { background: transparent; border: none; color: #64748b; font-size: 24px; cursor: pointer; width: 36px; height: 36px; display: flex; align-items: center; justify-content: center; border-radius: 8px; transition: all 0.2s ease; }
		.close-btn:hover { background: #f1f5f9; color: #1e293b; }
		.tag { background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); color: #2563eb; padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; border: 1px solid #bfdbfe; }
		
		/* Jobs table inside drawer */
		#jobsTable { width: 100%; border-collapse: separate; border-spacing: 0; background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.04); }
		#jobsTable th, #jobsTable td { padding: 14px 16px; text-align: left; border-bottom: 1px solid #f1f5f9; }
		#jobsTable th { background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); font-weight: 700; color: #1e293b; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; }
		#jobsTable tr { transition: all 0.2s ease; }
		#jobsTable tr:hover { background: linear-gradient(90deg, #fff5f2 0%, #ffffff 100%); }
		#jobsTable tr:last-child td { border-bottom: none; }
		#jobsTable .badge { padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
		
		/* Select elements in drawer */
		.drawer-actions select { padding: 8px 12px; border: 2px solid #e2e8f0; border-radius: 10px; font-size: 13px; background: white; color: #1e293b; font-weight: 500; transition: all 0.2s ease; }
		.drawer-actions select:hover { border-color: #ff6b35; }
		.drawer-actions select:focus { outline: none; border-color: #ff6b35; box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1); }
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
			<a href="driver-commission.php" class="nav-item active">
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
				<h1>Driver Commission</h1>
			</div>

			<div class="metrics">
				<div class="metric">
					<h3>Total Commission</h3>
					<div class="value"><?php echo rm($totalCommission); ?></div>
				</div>
				<div class="metric">
					<h3>Unpaid</h3>
					<div class="value"><?php echo rm($totalUnpaid); ?></div>
				</div>
				<div class="metric">
					<h3>Paid</h3>
					<div class="value"><?php echo rm($totalPaid); ?></div>
				</div>
			</div>

			<div class="content-card">
				<div class="filters">
					<input type="text" id="search" placeholder="Search by driver/ride ID...">
					<select id="status">
						<option value="">All Status</option>
						<option value="paid">Paid</option>
						<option value="unpaid">Unpaid</option>
					</select>
				</div>

				<table id="commissionTable">
					<thead>
						<tr>
							<th>Driver Name</th>
							<th>Total Jobs</th>
							<th>Total Fare</th>
							<th>Total Commission</th>
							<th>Paid</th>
							<th>Unpaid</th>
							<th>Payment Deadline</th>
							<th>Status</th>
							<th>Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php if (empty($rows)): ?>
						<tr><td colspan="9" style="text-align:center;color:#666;">No commission records</td></tr>
						<?php else: foreach ($rows as $driver): ?>
						<tr>
							<td>
								<strong><?php echo htmlspecialchars($driver['driver_name']); ?></strong>
								<br><small style="color:#666;">ID: <?php echo htmlspecialchars($driver['driver_id']); ?></small>
							</td>
							<td><span class="badge badge-info"><?php echo $driver['job_count']; ?> jobs</span></td>
							<td><strong><?php echo rm($driver['total_fare']); ?></strong></td>
							<td><strong><?php echo rm($driver['total_commission']); ?></strong></td>
							<td><span class="badge badge-success"><?php echo rm($driver['paid_commission']); ?></span></td>
							<td><span class="badge badge-warning"><?php echo rm($driver['unpaid_commission']); ?></span></td>
							<td>
								<?php 
								$deadline = $driver['payment_deadline'] ?? null;
								if ($deadline && $deadline['timestamp']):
									$deadlineDate = $deadline['date'] ?? date('Y-m-d H:i:s', firebaseTimestampToSeconds($deadline['timestamp']));
									$isOverdue = $deadline['is_overdue'] ?? false;
									$badgeClass = $isOverdue ? 'badge-danger' : 'badge-info';
									$icon = $isOverdue ? 'fa-exclamation-triangle' : 'fa-calendar-alt';
								?>
									<span class="badge <?php echo $badgeClass; ?>" title="Payment Deadline">
										<i class="fas <?php echo $icon; ?>"></i>
										<?php echo htmlspecialchars($deadlineDate); ?>
									</span>
									<br><small style="color:#666;margin-top:4px;display:block;">
										<?php if ($isOverdue): ?>
											<span style="color:#dc2626;">Overdue</span>
										<?php else: ?>
											<span style="color:#059669;">Active</span>
										<?php endif; ?>
									</small>
								<?php else: ?>
									<span style="color:#94a3b8;">Not set</span>
								<?php endif; ?>
							</td>
							<td>
								<?php 
								$hasUnpaid = $driver['unpaid_commission'] > 0;
								$hasPaid = $driver['paid_commission'] > 0;
								if ($hasUnpaid && $hasPaid) {
									echo '<span class="badge badge-warning">Partial</span>';
								} elseif ($hasUnpaid) {
									echo '<span class="badge badge-warning">Unpaid</span>';
								} else {
									echo '<span class="badge badge-success">Paid</span>';
								}
								?>
							</td>
							<td>
								<button class="action-btn deadline-btn" onclick="setPaymentDeadline('<?php echo $driver['driver_id']; ?>', '<?php echo htmlspecialchars($driver['driver_name']); ?>', <?php echo $deadline ? ($deadline['timestamp'] ?? 'null') : 'null'; ?>)">
									<i class="fas fa-calendar-alt"></i> Set Deadline
								</button>
								<button class="action-btn view-btn" onclick="viewDriverJobs('<?php echo $driver['driver_id']; ?>', '<?php echo htmlspecialchars($driver['driver_name']); ?>')">
									<i class="fas fa-list"></i> View Jobs
								</button>
							</td>
						</tr>
						<?php endforeach; endif; ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>

	<!-- Slide-over for Driver Jobs -->
	<div id="jobsDrawerOverlay" class="drawer-overlay" onclick="closeJobsDrawer(event)">
		<div id="jobsDrawer" class="drawer" onclick="event.stopPropagation()">
			<div class="drawer-header">
				<h2 id="jobsDrawerTitle">Driver Jobs</h2>
				<button class="close-btn" onclick="closeJobsDrawer(event)"><i class="fas fa-times"></i></button>
			</div>
			<div class="drawer-body">
				<div class="drawer-actions">
					<span id="selectedDriverTag" class="tag"></span>
					<select id="yearFilter">
						<option value="">All years</option>
					</select>
					<select id="monthFilter">
						<option value="">All months</option>
					</select>
				</div>
				<div class="content-card" style="padding:0;">
					<table id="jobsTable">
						<thead>
							<tr>
								<th>Ride ID</th>
								<th>Date</th>
								<th>Fare</th>
								<th>Commission</th>
								<th>Status</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>

	<!-- Modal for Setting Payment Deadline -->
	<div id="deadlineModalOverlay" class="drawer-overlay" onclick="closeDeadlineModal(event)">
		<div id="deadlineModal" class="drawer" style="max-width:500px;" onclick="event.stopPropagation()">
			<div class="drawer-header">
				<h2 id="deadlineModalTitle">Set Payment Deadline</h2>
				<button class="close-btn" onclick="closeDeadlineModal(event)"><i class="fas fa-times"></i></button>
			</div>
			<div class="drawer-body">
				<div style="margin-bottom:20px;">
					<label style="display:block;margin-bottom:8px;font-weight:600;color:#1e293b;">Driver:</label>
					<div id="deadlineDriverName" style="padding:12px;background:#f8fafc;border-radius:8px;color:#1e293b;font-weight:600;"></div>
				</div>
				<div style="margin-bottom:20px;">
					<label style="display:block;margin-bottom:8px;font-weight:600;color:#1e293b;">Payment Deadline Date:</label>
					<input type="date" id="deadlineDate" style="width:100%;padding:10px;border:2px solid #e2e8f0;border-radius:8px;font-size:14px;">
				</div>
				<div style="margin-bottom:20px;">
					<label style="display:block;margin-bottom:8px;font-weight:600;color:#1e293b;">Payment Deadline Time:</label>
					<input type="time" id="deadlineTime" style="width:100%;padding:10px;border:2px solid #e2e8f0;border-radius:8px;font-size:14px;">
				</div>
				<div style="margin-bottom:20px;padding:12px;background:#fef3c7;border-radius:8px;border-left:4px solid #f59e0b;">
					<small style="color:#92400e;">
						<i class="fas fa-info-circle"></i> This deadline will be visible to the driver in the app and will be used to track payment compliance.
					</small>
				</div>
				<div style="display:flex;gap:12px;">
					<button class="action-btn pay-btn" onclick="savePaymentDeadline()" style="flex:1;">
						<i class="fas fa-save"></i> Save Deadline
					</button>
					<button class="action-btn" onclick="removePaymentDeadline()" style="flex:1;background:linear-gradient(135deg, #ef4444 0%, #dc2626 100%);color:#fff;">
						<i class="fas fa-trash"></i> Remove Deadline
					</button>
				</div>
			</div>
		</div>
	</div>

	<script>
	document.getElementById('search').addEventListener('input', function() {
		const q = this.value.toLowerCase();
		document.querySelectorAll('#commissionTable tbody tr').forEach(r => {
			r.style.display = r.textContent.toLowerCase().includes(q) ? '' : 'none';
		});
	});
	document.getElementById('status').addEventListener('change', function() {
		const v = this.value.toLowerCase();
		document.querySelectorAll('#commissionTable tbody tr').forEach(r => {
			const badge = r.querySelector('td:nth-child(7) .badge'); // Status is now column 7
			r.style.display = !v || (badge && badge.textContent.toLowerCase().includes(v)) ? '' : 'none';
		});
	});


	// Jobs slide-over implementation
	const driverJobsById = <?php
		$jobsMap = [];
		foreach ($rows as $driver) {
			$jobsMap[$driver['driver_id']] = array_values($driver['jobs'] ?? []);
		}
		echo json_encode($jobsMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	?>;
	
	// Driver payment status - to determine if all jobs should show as "paid"
	const driverPaymentStatus = <?php
		$paymentStatusMap = [];
		foreach ($rows as $driver) {
			// If unpaid_commission is 0 or very close to 0, driver has fully paid
			$paymentStatusMap[$driver['driver_id']] = [
				'unpaid_commission' => $driver['unpaid_commission'],
				'is_fully_paid' => ($driver['unpaid_commission'] <= 0.01)
			];
		}
		echo json_encode($paymentStatusMap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	?>;

	let currentDriverId = null;

	function viewDriverJobs(driverId, driverName) {
		currentDriverId = driverId;
		const overlay = document.getElementById('jobsDrawerOverlay');
		const drawer = document.getElementById('jobsDrawer');
		document.getElementById('jobsDrawerTitle').textContent = `Driver Jobs — ${driverName}`;
		document.getElementById('selectedDriverTag').textContent = `ID: ${driverId}`;
		populateYearFilter();
		populateMonthFilter();
		renderJobsTable();
		overlay.classList.add('active');
		setTimeout(() => drawer.classList.add('active'), 10);
	}

	function closeJobsDrawer(e) {
		const overlay = document.getElementById('jobsDrawerOverlay');
		const drawer = document.getElementById('jobsDrawer');
		drawer.classList.remove('active');
		setTimeout(() => overlay.classList.remove('active'), 200);
	}

	// Payment Deadline Functions
	let currentDeadlineDriverId = null;
	let currentDeadlineDriverName = null;

	function setPaymentDeadline(driverId, driverName, currentDeadlineTimestamp) {
		currentDeadlineDriverId = driverId;
		currentDeadlineDriverName = driverName;
		
		const overlay = document.getElementById('deadlineModalOverlay');
		const modal = document.getElementById('deadlineModal');
		document.getElementById('deadlineModalTitle').textContent = `Set Payment Deadline — ${driverName}`;
		document.getElementById('deadlineDriverName').textContent = driverName;
		
		// Set current deadline if exists
		const dateInput = document.getElementById('deadlineDate');
		const timeInput = document.getElementById('deadlineTime');
		
		if (currentDeadlineTimestamp && currentDeadlineTimestamp !== 'null') {
			const deadlineDate = new Date(parseInt(currentDeadlineTimestamp));
			dateInput.value = deadlineDate.toISOString().split('T')[0];
			timeInput.value = deadlineDate.toTimeString().slice(0, 5);
		} else {
			// Default to 7 days from now
			const defaultDate = new Date();
			defaultDate.setDate(defaultDate.getDate() + 7);
			dateInput.value = defaultDate.toISOString().split('T')[0];
			timeInput.value = '23:59';
		}
		
		overlay.classList.add('active');
		setTimeout(() => modal.classList.add('active'), 10);
	}

	function closeDeadlineModal(e) {
		const overlay = document.getElementById('deadlineModalOverlay');
		const modal = document.getElementById('deadlineModal');
		modal.classList.remove('active');
		setTimeout(() => overlay.classList.remove('active'), 200);
		currentDeadlineDriverId = null;
		currentDeadlineDriverName = null;
	}

	function savePaymentDeadline() {
		if (!currentDeadlineDriverId) return;
		
		const dateInput = document.getElementById('deadlineDate');
		const timeInput = document.getElementById('deadlineTime');
		
		if (!dateInput.value) {
			alert('Please select a deadline date');
			return;
		}
		
		fetch('set-payment-deadline.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify({
				driverId: currentDeadlineDriverId,
				deadlineDate: dateInput.value,
				deadlineTime: timeInput.value || '23:59:59'
			})
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				alert('Payment deadline set successfully!');
				location.reload();
			} else {
				alert('Error: ' + (data.message || 'Failed to set deadline'));
			}
		})
		.catch(error => {
			console.error('Error:', error);
			alert('Error setting payment deadline. Please try again.');
		});
	}

	function removePaymentDeadline() {
		if (!currentDeadlineDriverId) return;
		
		if (!confirm(`Remove payment deadline for ${currentDeadlineDriverName}?`)) {
			return;
		}
		
		fetch('set-payment-deadline.php', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify({
				driverId: currentDeadlineDriverId,
				deadlineDate: null
			})
		})
		.then(response => response.json())
		.then(data => {
			if (data.success) {
				alert('Payment deadline removed successfully!');
				location.reload();
			} else {
				alert('Error: ' + (data.message || 'Failed to remove deadline'));
			}
		})
		.catch(error => {
			console.error('Error:', error);
			alert('Error removing payment deadline. Please try again.');
		});
	}

	function populateYearFilter() {
		const yearSel = document.getElementById('yearFilter');
		const previous = yearSel.value;
		while (yearSel.options.length > 1) yearSel.remove(1);

		const jobs = driverJobsById[currentDriverId] || [];
		const years = new Set();
		const currentYear = String(new Date().getFullYear());
		years.add(currentYear);
		jobs.forEach(j => {
			const d = parseJobDate(j);
			if (d) years.add(String(d.getFullYear()));
		});

		Array.from(years).sort((a, b) => parseInt(a, 10) - parseInt(b, 10)).forEach(y => {
			const opt = document.createElement('option');
			opt.value = y;
			opt.textContent = y;
			yearSel.appendChild(opt);
		});

		yearSel.value = years.has(previous) ? previous : '';
		yearSel.onchange = () => {
			populateMonthFilter();
			renderJobsTable();
		};
	}

	function populateMonthFilter() {
		const monthSel = document.getElementById('monthFilter');
		const previous = monthSel.value;
		while (monthSel.options.length > 1) monthSel.remove(1);
		
		const jobs = driverJobsById[currentDriverId] || [];
		const monthsWithJobs = new Set();
		jobs.forEach(j => {
			const d = parseJobDate(j);
			if (d) monthsWithJobs.add(formatYYYYMM(d));
		});
		
		const yearSel = document.getElementById('yearFilter');
		const selectedYear = yearSel ? yearSel.value : '';
		const availableMonths = new Set();
		
		if (selectedYear) {
			// Add all 12 months for the selected year
			for (let month = 1; month <= 12; month++) {
				const monthStr = `${selectedYear}-${month.toString().padStart(2, '0')}`;
				availableMonths.add(monthStr);
			}
		} else {
			// Get current year
			const currentYear = new Date().getFullYear();
			for (let month = 1; month <= 12; month++) {
				const monthStr = `${currentYear}-${month.toString().padStart(2, '0')}`;
				availableMonths.add(monthStr);
			}
			// Include months from other years that have jobs
			monthsWithJobs.forEach(m => {
				availableMonths.add(m);
			});
		}
		
		// Sort months (year-month format sorts correctly)
		Array.from(availableMonths).sort().forEach(m => {
			const opt = document.createElement('option');
			opt.value = m;
			opt.textContent = humanMonth(m);
			monthSel.appendChild(opt);
		});
		
		monthSel.value = previous && availableMonths.has(previous) ? previous : '';
		monthSel.onchange = renderJobsTable;
	}

	function renderJobsTable() {
		const tbody = document.querySelector('#jobsTable tbody');
		tbody.innerHTML = '';
		const jobs = (driverJobsById[currentDriverId] || []).slice();
		const year = document.getElementById('yearFilter').value;
		const month = document.getElementById('monthFilter').value;
		const filtered = jobs.filter(j => {
			const d = parseJobDate(j);
			if (!d) {
				return !year && !month;
			}
			if (year && String(d.getFullYear()) !== year) {
				return false;
			}
			if (month) {
				return formatYYYYMM(d) === month;
			}
			return true;
		});
		// Sort by date desc
		filtered.sort((a, b) => {
			const da = parseJobDate(a)?.getTime() ?? 0;
			const db = parseJobDate(b)?.getTime() ?? 0;
			return db - da;
		});
		if (filtered.length === 0) {
			const tr = document.createElement('tr');
			const td = document.createElement('td');
			td.colSpan = 5;
			td.style.color = '#666';
			td.style.textAlign = 'center';
			td.textContent = 'No jobs for selected month';
			tr.appendChild(td);
			tbody.appendChild(tr);
			return;
		}
		filtered.forEach(j => {
			const tr = document.createElement('tr');
			const rideId = firstNonEmptyString(j, ['ride_id','rideId','booking_id','bookingId','trip_id','tripId','order_id','orderId','id']);
			const d = parseJobDate(j);
			const fare = firstNumber(j, ['fare','total_fare','totalFare','ride_fare','rideFare','trip_fare','price','amount','fare_amount','fareAmount','fare_total']);
			const commission = firstNumber(j, ['commission','commission_amount','commissionAmount','company_commission','companyCommission','admin_commission','adminCommission']);
			// Use the job's status (already calculated with FIFO allocation in PHP)
			let status = firstNonEmptyString(j, ['status','payment_status','paymentStatus','state']) || 'unpaid';
			// Style status badges
			let statusClass = 'badge-info';
			if (status === 'paid') statusClass = 'badge-success';
			else if (status === 'unpaid' || status === 'pending') statusClass = 'badge-warning';
			else if (status === 'partial') statusClass = 'badge-info';
			tr.innerHTML = `
				<td>${escapeHtml(rideId || '-')}</td>
				<td>${d ? d.toLocaleString() : '-'}</td>
				<td><strong>${formatRM(fare)}</strong></td>
				<td>${formatRM(commission)}</td>
				<td><span class="badge ${statusClass}">${escapeHtml(status)}</span></td>
			`;
			tbody.appendChild(tr);
		});
	}

	function parseJobDate(j) {
		const raw = j.completed_at ?? j.completedAt ?? j.end_time ?? j.endTime ?? j.date ?? j.created_at ?? j.createdAt ?? j.timestamp;
		if (!raw) return null;
		if (typeof raw === 'number') return new Date(raw);
		if (typeof raw === 'string') {
			// Try ISO or millis in string
			if (/^\d{10,13}$/.test(raw)) {
				const ms = raw.length === 13 ? parseInt(raw, 10) : parseInt(raw, 10) * 1000;
				return new Date(ms);
			}
			const d = new Date(raw);
			return isNaN(d.getTime()) ? null : d;
		}
		if (typeof raw === 'object' && raw !== null) {
			// { time: 1760621868243, ... } style
			const t = raw.time ?? raw.millis ?? raw.seconds*1000 ?? null;
			return typeof t === 'number' ? new Date(t) : null;
		}
		return null;
	}
	function formatYYYYMM(d) {
		const y = d.getFullYear();
		const m = (d.getMonth() + 1).toString().padStart(2,'0');
		return `${y}-${m}`;
	}
	function humanMonth(ym) {
		const [y, m] = ym.split('-').map(x => parseInt(x,10));
		return new Date(y, m - 1, 1).toLocaleDateString(undefined, { year:'numeric', month:'long' });
	}
	function toNumber(v) { const n = parseFloat(v ?? 0); return isNaN(n) ? 0 : n; }
	function formatRM(n) { return 'RM ' + Number((n||0)).toFixed(2); }
	function escapeHtml(s){ return (s??'').toString().replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m])); }
	function firstNumber(obj, keys) {
		for (const k of keys) {
			if (obj && Object.prototype.hasOwnProperty.call(obj, k)) {
				const n = toNumber(obj[k]);
				if (!isNaN(n) && isFinite(n)) return n;
			}
		}
		return 0;
	}
	function firstNonEmptyString(obj, keys) {
		for (const k of keys) {
			if (obj && Object.prototype.hasOwnProperty.call(obj, k)) {
				const v = obj[k];
				if (v !== undefined && v !== null && String(v).trim() !== '') return String(v);
			}
		}
		return '';
	}
	</script>
</body>
</html>

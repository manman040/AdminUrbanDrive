<?php
session_start();
require_once 'firebase-config.php';

// Protect page
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
	header('Location: index.php');
	exit;
}

// Helpers
function safe_number($v) { $n = floatval($v ?? 0); return is_finite($n) ? $n : 0.0; }

// Controls
$granularity = isset($_GET['view']) ? strtolower($_GET['view']) : 'monthly'; // daily | monthly | yearly
$selDate = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d'); // YYYY-MM-DD for daily
$selMonth = isset($_GET['month']) ? $_GET['month'] : substr($selDate, 0, 7); // YYYY-MM for daily buckets
$selYear = isset($_GET['year']) ? preg_replace('/[^0-9]/', '', $_GET['year']) : date('Y'); // YYYY for monthly
$selMonthForMonthly = isset($_GET['monthSelect']) && $_GET['monthSelect'] !== '' ? $_GET['monthSelect'] : null; // YYYY-MM for monthly view (null = all months)
$commissionRate = 20; // percent

// Fetch data
try { $bookings = getFirebaseData('bookings') ?: []; } catch (Exception $e) { $bookings = []; }
try { $drivers  = getFirebaseData('drivers') ?: []; } catch (Exception $e) { $drivers = []; }
try { $users    = getFirebaseData('users') ?: []; } catch (Exception $e) { $users = []; }
try { $driverCommissions = getFirebaseData('driver_commissions') ?: []; } catch (Exception $e) { $driverCommissions = []; }
try { $ratings  = getFirebaseData('ratings') ?: []; } catch (Exception $e) { $ratings = []; }
try { $driverApprovals = getFirebaseData('driver_approvals') ?: []; } catch (Exception $e) { $driverApprovals = []; }
try { $commissionPayments = getFirebaseData('commission_payment') ?: []; } catch (Exception $e) { $commissionPayments = []; }

// Build metrics
$now = time();
$activeStatuses = ['confirmed','on_ride','in_progress','assigned','accepted','started'];
$completedStatuses = ['completed','finish','finished','done'];

$totalActiveRides = 0;
$totalRevenue = 0.0;
$totalCommission = 0.0;
$totalRatings = 0;
$totalRatingValue = 0.0;
$ratingDistribution = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];

// Buckets based on granularity
$buckets = [];
if ($granularity === 'daily') {
	// Build days for selected month
	$base = strtotime($selMonth . '-01');
	$daysInMonth = intval(date('t', $base));
	for ($d = 1; $d <= $daysInMonth; $d++) {
		$key = date('Y-m-', $base) . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
		$buckets[$key] = ['rides' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'ratings' => 0, 'avgRating' => 0.0];
	}
} elseif ($granularity === 'yearly') {
	// Last 5 years
	for ($i = 4; $i >= 0; $i--) {
		$y = intval(date('Y', strtotime("-{$i} years", $now)));
		$buckets[(string)$y] = ['rides' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'ratings' => 0, 'avgRating' => 0.0];
	}
} else {
	// Monthly - Show all 12 months of selected year, or specific month if selected
	$y = intval($selYear ?: date('Y'));
	if ($selMonthForMonthly) {
		// Show only selected month
		$buckets[$selMonthForMonthly] = ['rides' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'ratings' => 0, 'avgRating' => 0.0];
	} else {
		// Show all 12 months of selected year
		for ($m = 1; $m <= 12; $m++) {
			$key = sprintf('%04d-%02d', $y, $m);
			$buckets[$key] = ['rides' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'ratings' => 0, 'avgRating' => 0.0];
		}
	}
}

// Activity counters
$driverRideCount = [];
$userRideCount = [];

foreach ($bookings as $id => $b) {
	$status = strtolower($b['status'] ?? '');
	$fare = bookingFareAmount($b);
	$driverId = $b['driverId'] ?? null;
	$userId = $b['userId'] ?? ($b['customerId'] ?? null);

	// Count active rides only if created within last 24 hours
	if (in_array($status, $activeStatuses, true)) {
		// Get booking creation timestamp
		$createdTs = null;
		if (isset($b['createdAt'])) { $createdTs = intval(firebaseTimestampValue($b['createdAt'])); }
		elseif (isset($b['timestamp'])) { $createdTs = is_array($b['timestamp']) ? intval($b['timestamp']['time'] ?? 0) : intval($b['timestamp']); }
		elseif (isset($b['created_at'])) { $createdTs = is_array($b['created_at']) ? intval($b['created_at']['time'] ?? 0) : intval($b['created_at']); }
		
		// Convert to seconds if in milliseconds
		if ($createdTs > 10000000000) { $createdTs = intval($createdTs / 1000); }
		
		// Only count if created within last 24 hours
		if ($createdTs && ($now - $createdTs) <= 86400) { // 86400 seconds = 24 hours
			$totalActiveRides++;
		}
	}

	if (in_array($status, $completedStatuses, true)) {
		// Determine timestamp
		$ts = null;
		if (isset($b['completedAt'])) { $ts = intval(firebaseTimestampValue($b['completedAt'])); }
		elseif (isset($b['createdAt'])) { $ts = intval(firebaseTimestampValue($b['createdAt'])); }
		elseif (isset($b['timestamp'])) { $ts = is_array($b['timestamp']) ? intval($b['timestamp']['time'] ?? 0) : intval($b['timestamp']); }
		elseif (isset($b['end_time'])) { $ts = is_array($b['end_time']) ? intval($b['end_time']['time'] ?? 0) : intval($b['end_time']); }
		if (!$ts) { $ts = $now * 1000; }

		$time = intval($ts / 1000);
		$bucketKey = null;
		if ($granularity === 'daily') {
			$bucketKey = date('Y-m-d', $time);
			// Skip if not in selected month
			if (strpos($bucketKey, $selMonth) !== 0) { $bucketKey = null; }
		} elseif ($granularity === 'yearly') {
			$bucketKey = date('Y', $time);
		} else { // monthly
			$bucketKey = date('Y-m', $time);
			// Only include if it's in our predefined buckets (selected year or selected month)
			if (!isset($buckets[$bucketKey])) {
				$bucketKey = null; // Skip data outside selected range
			}
		}

		// Only count revenue, driver/user counts, and add to buckets if within selected filter period
		if ($bucketKey !== null) {
			$totalRevenue += $fare; // Only count revenue for filtered period
			if ($driverId) { $driverRideCount[$driverId] = ($driverRideCount[$driverId] ?? 0) + 1; }
			if ($userId) { $userRideCount[$userId] = ($userRideCount[$userId] ?? 0) + 1; }
			
			if (!isset($buckets[$bucketKey])) {
				$buckets[$bucketKey] = ['rides' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'ratings' => 0, 'avgRating' => 0.0];
			}
			$buckets[$bucketKey]['rides'] += 1;
			$buckets[$bucketKey]['revenue'] += $fare;
			$buckets[$bucketKey]['commission'] += ($fare * $commissionRate) / 100.0;
		}
	}
}

// Process ratings data
if (is_array($ratings) && !empty($ratings)) {
	foreach ($ratings as $driverId => $driverRatings) {
		if (!is_array($driverRatings)) continue;
		foreach ($driverRatings as $ratingId => $entry) {
			$rating = floatval($entry['rating'] ?? 0);
			if ($rating > 0 && $rating <= 5) {
				// Determine timestamp for bucket
				$ts = null;
				if (isset($entry['timestamp']['time'])) { $ts = intval($entry['timestamp']['time']); }
				elseif (isset($entry['timestamp'])) { $ts = is_numeric($entry['timestamp']) ? intval($entry['timestamp']) : (is_array($entry['timestamp']) ? intval($entry['timestamp']['time'] ?? 0) : 0); }
				if (!$ts) { $ts = $now * 1000; }
				
				$time = intval($ts / 1000);
				$bucketKey = null;
				if ($granularity === 'daily') {
					$bucketKey = date('Y-m-d', $time);
					if (strpos($bucketKey, $selMonth) !== 0) { $bucketKey = null; }
				} elseif ($granularity === 'yearly') {
					$bucketKey = date('Y', $time);
				} else { // monthly
					$bucketKey = date('Y-m', $time);
					// Only include if it's in our predefined buckets (selected year or selected month)
					if (!isset($buckets[$bucketKey])) {
						$bucketKey = null; // Skip data outside selected range
					}
				}
				
				// Only count ratings within the selected filter period
				if ($bucketKey !== null) {
					$totalRatings++;
					$totalRatingValue += $rating;
					$starRating = intval(round($rating));
					if ($starRating >= 1 && $starRating <= 5) {
						$ratingDistribution[$starRating]++;
					}
					
					if (!isset($buckets[$bucketKey])) {
						$buckets[$bucketKey] = ['rides' => 0, 'revenue' => 0.0, 'commission' => 0.0, 'ratings' => 0, 'avgRating' => 0.0];
					}
					$buckets[$bucketKey]['ratings'] += 1;
					// Calculate average rating for this bucket
					$currentTotal = ($buckets[$bucketKey]['ratings'] - 1) * ($buckets[$bucketKey]['avgRating'] ?? 0) + $rating;
					$buckets[$bucketKey]['avgRating'] = $currentTotal / $buckets[$bucketKey]['ratings'];
				}
			}
		}
	}
}

// Commission total: from filtered buckets
foreach ($buckets as $k => $v) { $totalCommission += safe_number($v['commission'] ?? 0); }

// Calculate paid commission from commission_payment
$totalPaidCommission = 0.0;
if (is_array($commissionPayments)) {
	foreach ($commissionPayments as $driverId => $payments) {
		if (!is_array($payments)) continue;
		foreach ($payments as $paymentId => $payment) {
			if (!is_array($payment)) continue;
			$status = strtolower($payment['status'] ?? '');
			if (in_array($status, ['paid', 'success', 'completed'], true)) {
				$amount = safe_number($payment['amount'] ?? 0);
				$totalPaidCommission += $amount;
			}
		}
	}
}
$totalUnpaidCommission = max(0, $totalCommission - $totalPaidCommission);

// Calculate average rating
$averageRating = $totalRatings > 0 ? round($totalRatingValue / $totalRatings, 2) : 0.0;

// Collect driver ratings for modal
$driverRatingsList = [];
if (is_array($ratings) && !empty($ratings)) {
	foreach ($ratings as $driverId => $driverRatings) {
		if (!is_array($driverRatings)) continue;
		$driverTotalRating = 0.0;
		$driverRatingCount = 0;
		foreach ($driverRatings as $ratingId => $entry) {
			$rating = floatval($entry['rating'] ?? 0);
			if ($rating > 0 && $rating <= 5) {
				$driverTotalRating += $rating;
				$driverRatingCount++;
			}
		}
		if ($driverRatingCount > 0) {
			$driverAvgRating = round($driverTotalRating / $driverRatingCount, 2);
			
			// Try multiple sources for driver name
			$driverName = $driverId; // Default fallback
			
			// Check driver_commissions first (most reliable)
			if (isset($driverCommissions[$driverId]['driver_info']['driver_name'])) {
				$driverName = $driverCommissions[$driverId]['driver_info']['driver_name'];
			}
			// Check driver_approvals (applications)
			elseif (isset($driverApprovals[$driverId]['name'])) {
				$driverName = $driverApprovals[$driverId]['name'];
			}
			// Check drivers collection
			elseif (isset($drivers[$driverId])) {
				$driverName = $drivers[$driverId]['fullName'] ?? $drivers[$driverId]['name'] ?? $drivers[$driverId]['driver_name'] ?? $driverId;
			}
			// Check if driver name is in rating entry itself
			elseif (isset($driverRatings) && is_array($driverRatings)) {
				foreach ($driverRatings as $ratingEntry) {
					if (isset($ratingEntry['driver_name'])) {
						$driverName = $ratingEntry['driver_name'];
						break;
					}
				}
			}
			
			$driverRatingsList[] = [
				'driverId' => $driverId,
				'name' => $driverName,
				'avgRating' => $driverAvgRating,
				'totalRatings' => $driverRatingCount
			];
		}
	}
	// Sort by average rating (descending)
	usort($driverRatingsList, function($a, $b) {
		return $b['avgRating'] <=> $a['avgRating'];
	});
}

// Find most active driver/user
arsort($driverRideCount);
arsort($userRideCount);
$topDriverId = array_key_first($driverRideCount);
$topUserId = array_key_first($userRideCount);
$topDriverName = $topDriverId && isset($drivers[$topDriverId]) ? ($drivers[$topDriverId]['fullName'] ?? $drivers[$topDriverId]['name'] ?? $topDriverId) : ($topDriverId ?? '-');
// Get top user name with multiple fallbacks
$topUserName = '-';
if ($topUserId && isset($users[$topUserId])) {
	$userData = $users[$topUserId];
	$topUserName = $userData['name'] ?? $userData['fullName'] ?? $userData['displayName'] ?? $userData['email'] ?? $topUserId;
} elseif ($topUserId) {
	$topUserName = $topUserId;
}

// Build top-5 leaderboards
$topDrivers = [];
if (!empty($driverRideCount)) {
	$maxDriver = max($driverRideCount);
	$i = 0;
	foreach ($driverRideCount as $id => $cnt) {
		$name = isset($drivers[$id]) ? ($drivers[$id]['fullName'] ?? $drivers[$id]['name'] ?? $id) : $id;
		$topDrivers[] = ['id' => $id, 'name' => $name, 'count' => $cnt, 'pct' => $maxDriver ? round(($cnt / $maxDriver) * 100) : 0];
		if (++$i >= 5) break;
	}
}
$topUsers = [];
if (!empty($userRideCount)) {
	$maxUser = max($userRideCount);
	$i = 0;
	foreach ($userRideCount as $id => $cnt) {
		// Try multiple sources for user name
		$name = $id; // Default fallback
		
		if (isset($users[$id])) {
			$userData = $users[$id];
			$name = $userData['name'] ?? $userData['fullName'] ?? $userData['displayName'] ?? $userData['email'] ?? $id;
		}
		
		$topUsers[] = ['id' => $id, 'name' => $name, 'count' => $cnt, 'pct' => $maxUser ? round(($cnt / $maxUser) * 100) : 0];
		if (++$i >= 5) break;
	}
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Reports</title>
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
	<style>
		body { margin: 0; font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height: 100vh; }
		.container { display: grid; grid-template-columns: 280px 1fr; min-height: 100vh; }
		.sidebar { background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); border-right: 1px solid rgba(0,0,0,0.05); padding: 24px 0; position: relative; box-shadow: 4px 0 20px rgba(0,0,0,0.08); }
		.sidebar-header { padding: 0 24px 24px; border-bottom: 2px solid #f1f5f9; margin-bottom: 24px; }
		.sidebar-header h2 { font-size: 20px; margin: 0; font-weight: 700; letter-spacing: -0.5px; color: #1e293b; }
		.nav-item { display: flex; align-items: center; gap: 12px; padding: 16px 24px; color: #64748b; text-decoration: none; border-left: 4px solid transparent; font-weight: 500; font-size: 14px; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); }
		.nav-item:hover { background: linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%); color: #ff6b35; border-left-color: #ff6b35; transform: translateX(4px); }
		.nav-item.active { background: linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%); border-left-color: #ff6b35; color: #ff6b35; font-weight: 600; }
		.nav-item i { width: 22px; font-size: 18px; }
		.logout-btn { position: absolute; bottom: 24px; left: 24px; right: 24px; padding: 14px; background: linear-gradient(135deg, #ff6b35 0%, #ff8c5a 100%); color: white; text-align: center; text-decoration: none; border-radius: 12px; display: flex; align-items: center; justify-content: center; gap: 8px; font-weight: 600; font-size: 14px; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3); }
		.logout-btn:hover { background: linear-gradient(135deg, #e55a2b 0%, #ff6b35 100%); transform: translateY(-2px); box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4); }
		.logout-btn:active { transform: translateY(0); }

		.main-content { padding: 32px; overflow-x: auto; margin-top: 70px; }

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
		.header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 28px; gap: 16px; flex-wrap: wrap; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding: 24px; border-radius: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); border: 1px solid rgba(0,0,0,0.05); }
		.header h1 { font-size: 28px; margin: 0; display: flex; align-items: center; gap: 12px; font-weight: 700; letter-spacing: -0.5px; color: #1e293b; }
		.header-actions { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
		.btn { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 12px; border: 2px solid #e2e8f0; background: white; color: #1e293b; cursor: pointer; font-weight: 600; font-size: 14px; transition: all 0.2s ease; }
		.btn:hover { background: #f8fafc; border-color: #ff6b35; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
		.btn-primary { background: linear-gradient(135deg, #ff6b35 0%, #ff8c5a 100%); color: #fff; border-color: #ff6b35; box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3); }
		.btn-primary:hover { background: linear-gradient(135deg, #e55a2b 0%, #ff6b35 100%); box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4); }
		.filters { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
		.select, .input { padding: 10px 16px; border: 2px solid #e2e8f0; border-radius: 12px; background: white; color: #1e293b; font-weight: 500; font-size: 14px; transition: all 0.2s ease; }
		.select:hover, .input:hover { border-color: #ff6b35; }
		.select:focus, .input:focus { outline: none; border-color: #ff6b35; box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1); }

		.metrics-grid { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 24px; margin-bottom: 32px; }
		.metric { position: relative; overflow: hidden; background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid rgba(0,0,0,0.05); border-radius: 16px; padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
		.metric::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #ff6b35, #ff8c5a); transform: scaleX(0); transition: transform 0.3s ease; }
		.metric:hover { transform: translateY(-4px); box-shadow: 0 8px 30px rgba(0,0,0,0.12); }
		.metric:hover::before { transform: scaleX(1); }
		.metric .label { color: #64748b; font-size: 13px; display: flex; align-items: center; gap: 6px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; }
		.metric .hint { color: #94a3b8; font-size: 12px; margin-top: 4px; }
		.metric .value { font-size: 32px; font-weight: 800; margin-top: 8px; letter-spacing: -1px; color: #1e293b; line-height: 1; }
		.metric .icon { position: absolute; right: 16px; top: 16px; color: #cbd5e1; font-size: 32px; transition: transform 0.3s ease; }
		.metric:hover .icon { transform: scale(1.1); }
		.metric.info { background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); border-color: #bfdbfe; }
		.metric.info::before { background: linear-gradient(90deg, #2563eb, #3b82f6); }
		.metric.success { background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%); border-color: #bbf7d0; }
		.metric.success::before { background: linear-gradient(90deg, #16a34a, #22c55e); }
		.metric.warning { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-color: #fde68a; }
		.metric.warning::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
		.metric.purple { background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%); border-color: #e9d5ff; }
		.metric.purple::before { background: linear-gradient(90deg, #9333ea, #a855f7); }
		.metric.rating { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-color: #fde68a; }
		.metric.rating::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
		.badge { padding: 2px 8px; background: #eef5ff; color: #1e6bd6; border-radius: 999px; font-size: 11px; font-weight: 600; }
		.help { color: #9aa3af; cursor: help; }

		.cards { display: grid; grid-template-columns: 1.2fr 1fr; gap: 24px; }
		.card { background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border: 1px solid rgba(0,0,0,0.05); border-radius: 20px; padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); transition: all 0.3s ease; }
		.card:hover { box-shadow: 0 8px 30px rgba(0,0,0,0.12); transform: translateY(-2px); }
		.card h3 { margin: 0 0 20px; font-size: 18px; font-weight: 700; color: #1e293b; letter-spacing: -0.5px; }
		.kv { display: grid; grid-template-columns: 1fr auto; row-gap: 8px; }
		.kv .k { color: #666; }
		.kv .v { font-weight: 600; }

		.leaderboard { display: grid; grid-template-columns: 1fr; gap: 10px; }
		.tabs { display: flex; gap: 8px; margin-bottom: 16px; }
		.tab { padding: 10px 16px; border-radius: 12px; border: 2px solid #e2e8f0; background: white; cursor: pointer; font-size: 13px; font-weight: 600; transition: all 0.2s ease; }
		.tab:hover { border-color: #ff6b35; background: #fff5f2; }
		.tab.active { background: linear-gradient(135deg, #2563eb 0%, #3b82f6 100%); color: #fff; border-color: #2563eb; box-shadow: 0 4px 12px rgba(37, 99, 235, 0.3); }
		.lb-list { list-style: none; padding: 0; margin: 0; }
		.lb-item { display: grid; grid-template-columns: 32px 1fr auto; gap: 12px; align-items: center; padding: 12px; border-radius: 12px; transition: all 0.2s ease; }
		.lb-item:hover { background: linear-gradient(90deg, #fff5f2 0%, #ffffff 100%); transform: translateX(4px); }
		.rank { width: 32px; height: 32px; border-radius: 10px; background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%); color: #2563eb; display: flex; align-items: center; justify-content: center; font-weight: 700; font-size: 14px; border: 2px solid #bfdbfe; }
		.bar { height: 16px; background: #f1f5f9; border-radius: 999px; position: relative; overflow: hidden; width: 100%; min-width: 150px; margin-top: 4px; display: block; border: 1px solid #e2e8f0; }
		.bar > span { position: absolute; left: 0; top: 0; bottom: 0; height: 100% !important; background: linear-gradient(90deg, #2563eb, #3b82f6) !important; border-radius: 999px; transition: width 0.5s ease; display: block !important; visibility: visible !important; opacity: 1 !important; }
		.lb-meta { display: grid; grid-template-columns: 1fr; gap: 8px; flex: 1; min-width: 0; }
		.lb-name { font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-bottom: 2px; }
		.lb-count { color: #6b7280; font-size: 12px; font-weight: 600; }

		@media (max-width: 1400px) {
			.metrics-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
		}
		@media (max-width: 1100px) {
			.metrics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
			.cards { grid-template-columns: 1fr; }
		}
		.stars { color: #ffc107; font-size: 14px; margin-top: 4px; }
		.stars i.far { color: #e0e0e0; }

		/* Ratings Modal */
		.ratings-modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); overflow: auto; }
		.ratings-modal.active { display: flex; align-items: center; justify-content: center; }
		.ratings-modal-content { background: #fff; border-radius: 16px; padding: 24px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto; box-shadow: 0 10px 40px rgba(0,0,0,0.2); position: relative; }
		.ratings-modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; padding-bottom: 16px; border-bottom: 1px solid #e9eef7; }
		.ratings-modal-header h3 { margin: 0; font-size: 20px; color: #333; display: flex; align-items: center; gap: 10px; }
		.ratings-modal-close { background: none; border: none; font-size: 28px; color: #999; cursor: pointer; padding: 0; width: 32px; height: 32px; display: flex; align-items: center; justify-content: center; border-radius: 6px; transition: all 0.2s; }
		.ratings-modal-close:hover { background: #f5f5f5; color: #333; }
		.ratings-list { list-style: none; padding: 0; margin: 0; }
		.ratings-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f0f0f0; }
		.ratings-item:last-child { border-bottom: none; }
		.ratings-item-left { flex: 1; }
		.ratings-item-name { font-weight: 600; color: #333; margin-bottom: 4px; }
		.ratings-item-stars { color: #ffc107; font-size: 14px; }
		.ratings-item-right { text-align: right; }
		.ratings-item-value { font-size: 18px; font-weight: 700; color: #333; margin-bottom: 4px; }
		.ratings-item-count { font-size: 12px; color: #6b7280; }

		/* Print styles (Export PDF) */
		@media print {
			.sidebar, .header-actions, .top-nav { display: none !important; }
			.container { grid-template-columns: 1fr; }
			.main-content { margin-top: 0 !important; padding: 20px !important; }
			.metric { break-inside: avoid; }
			canvas { max-height: 300px !important; }
			.card { break-inside: avoid; page-break-inside: avoid; }
			.leaderboard { break-inside: avoid; page-break-inside: avoid; }
			.tabs { display: none !important; }
			#boardDrivers, #boardUsers { 
				display: block !important; 
				margin-bottom: 24px !important;
			}
			.print-section-title { 
				display: block !important; 
				margin: 0 0 12px 0 !important;
				font-size: 16px !important;
				font-weight: 600 !important;
				color: #1e293b !important;
				padding-bottom: 8px !important;
				border-bottom: 2px solid #e2e8f0 !important;
			}
			.lb-item { 
				break-inside: avoid; 
				page-break-inside: avoid; 
				margin-bottom: 12px !important;
				padding: 10px !important;
				border: 1px solid #e2e8f0 !important;
				border-radius: 8px !important;
			}
			.lb-meta {
				gap: 8px !important;
			}
			.bar { 
				background: #e2e8f0 !important; 
				height: 18px !important;
				border-radius: 9px !important;
				width: 100% !important;
				min-width: 200px !important;
				border: 1px solid #cbd5e1 !important;
				margin-top: 6px !important;
				margin-bottom: 4px !important;
				overflow: hidden !important;
				position: relative !important;
				-webkit-print-color-adjust: exact !important;
				print-color-adjust: exact !important;
				color-adjust: exact !important;
			}
			.bar > span { 
				background: #2563eb !important;
				background-color: #2563eb !important;
				background-image: linear-gradient(90deg, #2563eb, #3b82f6) !important;
				height: 100% !important;
				border-radius: 9px !important;
				display: block !important;
				visibility: visible !important;
				opacity: 1 !important;
				min-width: 3px !important;
				position: absolute !important;
				left: 0 !important;
				top: 0 !important;
				-webkit-print-color-adjust: exact !important;
				print-color-adjust: exact !important;
				color-adjust: exact !important;
			}
			/* Force inline styles to be respected in print */
			.bar > span[style] {
				/* Inline styles should override */
			}
			.rank {
				background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%) !important;
				border: 2px solid #bfdbfe !important;
			}
			.lb-name {
				font-weight: 600 !important;
				color: #1e293b !important;
			}
			.lb-count {
				font-weight: 600 !important;
				color: #2563eb !important;
			}
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
			<a href="fare-settings.php" class="nav-item">
				<i class="fas fa-money-bill-wave"></i>
				Fare Settings
			</a>
			<a href="customer-feedback.php" class="nav-item">
				<i class="fas fa-comments"></i>
				Customer Feedback
			</a>
			<a href="reports.php" class="nav-item active">
				<i class="fas fa-chart-line"></i>
				Reports
			</a>

			<a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
		</div>

		<div class="main-content">
			<div class="header">
				<h1><i class="fas fa-chart-line" style="color:#ff6b35;"></i> Reports</h1>
				<form class="header-actions" method="get">
					<div class="filters">
						<select name="view" id="view" class="select" onchange="toggleFilterInputs()">
							<option value="daily" <?php echo $granularity==='daily'?'selected':''; ?>>Daily</option>
							<option value="monthly" <?php echo $granularity==='monthly'?'selected':''; ?>>Monthly</option>
							<option value="yearly" <?php echo $granularity==='yearly'?'selected':''; ?>>Yearly</option>
						</select>
						<input type="date" class="input" name="date" id="dateInput" value="<?php echo htmlspecialchars($selDate); ?>" <?php echo $granularity==='daily'?'':'style="display:none"'; ?> />
						<select name="year" id="yearInput" class="select" <?php echo $granularity==='monthly'?'':'style="display:none"'; ?>>
							<?php
								$yearNow = intval(date('Y'));
								for ($y = $yearNow; $y >= $yearNow - 6; $y--) {
									$sel = ((string)$y === (string)$selYear) ? 'selected' : '';
									echo "<option value=\"{$y}\" {$sel}>{$y}</option>";
								}
							?>
						</select>
						<select name="monthSelect" id="monthInput" class="select" <?php echo $granularity==='monthly'?'':'style="display:none"'; ?>>
							<option value="">All Months</option>
							<?php
								$months = [
									'01' => 'January', '02' => 'February', '03' => 'March', '04' => 'April',
									'05' => 'May', '06' => 'June', '07' => 'July', '08' => 'August',
									'09' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'
								];
								$currentYear = intval($selYear ?: date('Y'));
								foreach ($months as $num => $name) {
									$monthValue = sprintf('%04d-%02d', $currentYear, $num);
									$sel = ($selMonthForMonthly === $monthValue) ? 'selected' : '';
									echo "<option value=\"{$monthValue}\" {$sel}>{$name}</option>";
								}
							?>
						</select>
						<button class="btn" type="submit"><i class="fas fa-filter"></i> Apply</button>
					</div>
					<button class="btn" type="button" onclick="location.href='reports.php'"><i class="fas fa-rotate"></i> Reset</button>
					<button class="btn btn-primary" type="button" onclick="window.print()"><i class="fas fa-file-pdf"></i> Export PDF / Print</button>
				</form>
			</div>

			<div class="metrics-grid">
				<div class="metric info">
					<i class="fas fa-taxi icon"></i>
					<div class="label">Active rides <i class="fas fa-circle-question help" title="Rides currently in progress (created within last 24 hours): confirmed/accepted/assigned/in_progress/on_ride/started"></i></div>
					<div class="value"><?php echo number_format($totalActiveRides); ?></div>
					<div class="hint">Ongoing trips (last 24 hours)</div>
				</div>
				<div class="metric success">
					<i class="fas fa-sack-dollar icon"></i>
					<div class="label">Total revenue <span class="badge">completed rides</span></div>
					<div class="value">RM <?php echo number_format($totalRevenue, 2); ?></div>
				</div>
				<div class="metric warning">
					<i class="fas fa-hand-holding-dollar icon"></i>
					<div class="label">Total commission</div>
					<div class="value">RM <?php echo number_format($totalCommission, 2); ?></div>
					<div class="hint" style="margin-top: 8px; font-size: 13px;">
						<span style="color: #10b981;">Paid: RM <?php echo number_format($totalPaidCommission, 2); ?></span> | 
						<span style="color: #ef4444;">Unpaid: RM <?php echo number_format($totalUnpaidCommission, 2); ?></span>
					</div>
				</div>
				<div class="metric purple">
					<i class="fas fa-users icon"></i>
					<div class="label">Drivers / Users</div>
					<div class="value">
						<?php 
						// Count only valid driver entries
						$validDrivers = 0;
						if (is_array($drivers)) {
							foreach ($drivers as $d) {
								if (is_array($d)) $validDrivers++;
							}
						}
						// Count only valid user entries
						$validUsers = 0;
						if (is_array($users)) {
							foreach ($users as $u) {
								if (is_array($u)) $validUsers++;
							}
						}
						echo number_format($validDrivers) . ' / ' . number_format($validUsers);
						?>
					</div>
				</div>
				<div class="metric rating">
					<i class="fas fa-star icon" style="color: #ffc107;"></i>
					<div class="label">Average rating <i class="fas fa-circle-question help" onclick="showRatingsModal()" style="cursor: pointer;" title="Click to view driver ratings details"></i></div>
					<div class="value"><?php echo number_format($averageRating, 1); ?></div>
					<div class="hint"><?php echo number_format($totalRatings); ?> total ratings</div>
					<div class="stars">
						<?php
						$fullStars = floor($averageRating);
						$hasHalf = ($averageRating - $fullStars) >= 0.5;
						for ($i = 1; $i <= 5; $i++) {
							if ($i <= $fullStars) {
								echo '<i class="fas fa-star"></i>';
							} elseif ($i == $fullStars + 1 && $hasHalf) {
								echo '<i class="fas fa-star-half-alt"></i>';
							} else {
								echo '<i class="far fa-star"></i>';
							}
						}
						?>
					</div>
				</div>
			</div>

			<div class="cards">
				<div class="card">
					<h3><i class="fas fa-calendar-week" style="color:#1e6bd6;margin-right:6px;"></i> 
						<?php
						if ($granularity === 'daily') {
							echo 'Daily Revenue & Rides (' . date('F Y', strtotime($selMonth . '-01')) . ')';
						} elseif ($granularity === 'yearly') {
							echo 'Yearly Revenue & Rides (Last 5 Years)';
						} else {
							if ($selMonthForMonthly) {
								$monthName = date('F Y', strtotime($selMonthForMonthly . '-01'));
								echo 'Monthly Revenue & Rides (' . $monthName . ')';
							} else {
								echo 'Monthly Revenue & Rides (' . $selYear . ')';
							}
						}
						?>
					</h3>
					<div style="position: relative; height: 300px; width: 100%; min-height: 300px;">
						<canvas id="revChart" style="display: block; width: 100%; height: 100%;"></canvas>
						<div id="chartError" style="display: none; padding: 20px; text-align: center; color: #999;">
							<p>Chart failed to load. Please check the browser console for errors.</p>
						</div>
					</div>
				</div>
				<div class="card">
					<h3><i class="fas fa-ranking-star" style="color:#ff6b35;margin-right:6px;"></i> Most Active</h3>
					<div class="leaderboard">
						<div class="tabs">
							<button class="tab active" id="tabDrivers" onclick="showBoard('drivers')"><i class="fas fa-id-card-clip"></i> Drivers</button>
							<button class="tab" id="tabUsers" onclick="showBoard('users')"><i class="fas fa-user"></i> Users</button>
						</div>
						<div id="boardDrivers">
							<h4 style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #1e293b; display: none;" class="print-section-title">Most Active Drivers</h4>
							<ul class="lb-list">
								<?php foreach ($topDrivers as $idx => $row): 
									$barWidth = $row['pct'] > 0 ? max(5, min(100, $row['pct'])) : 5; // Ensure between 5% and 100%
								?>
									<li class="lb-item">
										<div class="rank"><?php echo $idx + 1; ?></div>
										<div class="lb-meta">
											<div class="lb-name" title="<?php echo htmlspecialchars($row['name']); ?>"><?php echo htmlspecialchars($row['name']); ?></div>
											<div class="bar"><span data-width="<?php echo $barWidth; ?>" style="width: <?php echo $barWidth; ?>%; background: linear-gradient(90deg, #2563eb, #3b82f6);"></span></div>
										</div>
										<div class="lb-count"><?php echo number_format($row['count']); ?> rides</div>
									</li>
								<?php endforeach; ?>
								<?php if (empty($topDrivers)): ?>
									<li class="lb-item"><div class="lb-name">No driver activity yet</div></li>
								<?php endif; ?>
							</ul>
						</div>
						<div id="boardUsers" style="display:none;">
							<h4 style="margin: 0 0 12px 0; font-size: 16px; font-weight: 600; color: #1e293b; display: none;" class="print-section-title">Most Active Users</h4>
							<ul class="lb-list">
								<?php foreach ($topUsers as $idx => $row): 
									$barWidth = $row['pct'] > 0 ? max(5, min(100, $row['pct'])) : 5; // Ensure between 5% and 100%
								?>
									<li class="lb-item">
										<div class="rank"><?php echo $idx + 1; ?></div>
										<div class="lb-meta">
											<div class="lb-name" title="<?php echo htmlspecialchars($row['name']); ?>"><?php echo htmlspecialchars($row['name']); ?></div>
											<div class="bar"><span data-width="<?php echo $barWidth; ?>" style="width: <?php echo $barWidth; ?>%; background: linear-gradient(90deg, #2563eb, #3b82f6);"></span></div>
										</div>
										<div class="lb-count"><?php echo number_format($row['count']); ?> rides</div>
									</li>
								<?php endforeach; ?>
								<?php if (empty($topUsers)): ?>
									<li class="lb-item"><div class="lb-name">No user activity yet</div></li>
								<?php endif; ?>
							</ul>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- Ratings Modal -->
	<div id="ratingsModal" class="ratings-modal" onclick="closeRatingsModal(event)">
		<div class="ratings-modal-content" onclick="event.stopPropagation()">
			<div class="ratings-modal-header">
				<h3><i class="fas fa-star" style="color: #ffc107;"></i> Driver Ratings Details</h3>
				<button class="ratings-modal-close" onclick="closeRatingsModal()">&times;</button>
			</div>
			<ul class="ratings-list">
				<?php if (empty($driverRatingsList)): ?>
					<li class="ratings-item">
						<div class="ratings-item-left">
							<div class="ratings-item-name">No ratings available</div>
						</div>
					</li>
				<?php else: ?>
					<?php foreach ($driverRatingsList as $driver): ?>
						<li class="ratings-item">
							<div class="ratings-item-left">
								<div class="ratings-item-name"><?php echo htmlspecialchars($driver['name']); ?></div>
								<div class="ratings-item-stars">
									<?php
									$fullStars = floor($driver['avgRating']);
									$hasHalf = ($driver['avgRating'] - $fullStars) >= 0.5;
									for ($i = 1; $i <= 5; $i++) {
										if ($i <= $fullStars) {
											echo '<i class="fas fa-star"></i>';
										} elseif ($i == $fullStars + 1 && $hasHalf) {
											echo '<i class="fas fa-star-half-alt"></i>';
										} else {
											echo '<i class="far fa-star"></i>';
										}
									}
									?>
								</div>
							</div>
							<div class="ratings-item-right">
								<div class="ratings-item-value"><?php echo number_format($driver['avgRating'], 1); ?></div>
								<div class="ratings-item-count"><?php echo number_format($driver['totalRatings']); ?> rating<?php echo $driver['totalRatings'] != 1 ? 's' : ''; ?></div>
							</div>
						</li>
					<?php endforeach; ?>
				<?php endif; ?>
			</ul>
		</div>
	</div>

	<!-- Load Chart.js -->
	<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
	
	<script>
		// Prepare chart data - ensure all are arrays
		const chartLabels = <?php echo json_encode(array_keys($buckets)); ?> || [];
		const chartRevenue = <?php echo json_encode(array_values(array_map(function($x){ return round($x['revenue'] ?? 0, 2); }, $buckets))); ?> || [];
		const chartRides = <?php echo json_encode(array_values(array_map(function($x){ return intval($x['rides'] ?? 0); }, $buckets))); ?> || [];
		const chartAvgRatings = <?php echo json_encode(array_values(array_map(function($x){ return round($x['avgRating'] ?? 0, 2); }, $buckets))); ?> || [];
		
		// Ensure all are arrays
		const chartLabelsArray = Array.isArray(chartLabels) ? chartLabels : [];
		const chartRevenueArray = Array.isArray(chartRevenue) ? chartRevenue : [];
		const chartRidesArray = Array.isArray(chartRides) ? chartRides : [];
		const chartAvgRatingsArray = Array.isArray(chartAvgRatings) ? chartAvgRatings : [];

		function initChart() {
			try {
				// Check if Chart.js is loaded
				if (typeof Chart === 'undefined') {
					console.error('Chart.js is not loaded');
					document.getElementById('chartError').style.display = 'block';
					document.getElementById('revChart').style.display = 'none';
					return;
				}

				const ctx = document.getElementById('revChart');
				if (!ctx) {
					console.error('Chart canvas element not found');
					document.getElementById('chartError').style.display = 'block';
					return;
				}

				console.log('Initializing chart with data:', { 
					labels: chartLabelsArray, 
					revenue: chartRevenueArray, 
					rides: chartRidesArray, 
					ratings: chartAvgRatingsArray 
				});

				// Create chart - Chart.js v4 takes the canvas element directly
				const chart = new Chart(ctx, {
					type: 'bar',
					data: {
						labels: chartLabelsArray,
						datasets: [
							{ 
								type: 'bar', 
								label: 'Revenue (RM)', 
								data: chartRevenueArray, 
								backgroundColor: 'rgba(30, 144, 255, 0.5)', 
								borderColor: '#1e90ff', 
								borderWidth: 1, 
								yAxisID: 'y' 
							},
							{ 
								type: 'line', 
								label: 'Rides', 
								data: chartRidesArray, 
								borderColor: '#ff6b35', 
								backgroundColor: '#ff6b35', 
								tension: 0.25, 
								yAxisID: 'y1', 
								borderWidth: 2 
							},
							{ 
								type: 'line', 
								label: 'Avg Rating (×10)', 
								data: chartAvgRatingsArray.map(function(r) { return (r || 0) * 10; }), 
								borderColor: '#ffc107', 
								backgroundColor: '#ffc107', 
								tension: 0.25, 
								yAxisID: 'y1', 
								borderWidth: 2, 
								borderDash: [5, 5], 
								pointRadius: 4 
							}
						]
					},
					options: {
						responsive: true,
						maintainAspectRatio: false,
						scales: {
							y: { 
								type: 'linear', 
								position: 'left', 
								ticks: { 
									callback: function(v) { 
										return 'RM ' + Number(v).toFixed(0); 
									} 
								} 
							},
							y1: { 
								type: 'linear', 
								position: 'right', 
								grid: { 
									drawOnChartArea: false 
								}, 
								ticks: { 
									callback: function(v) { 
										return Number(v).toFixed(0); 
									} 
								} 
							}
						},
						plugins: { 
							legend: { 
								position: 'bottom' 
							},
							tooltip: {
								callbacks: {
									label: function(context) {
										var label = context.dataset.label || '';
										var value = context.parsed.y;
										
										if (label.indexOf('Revenue') !== -1) {
											return 'Revenue (RM): ' + Number(value).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
										}
										if (label.indexOf('Avg Rating') !== -1) {
											var actualRating = Number(value) / 10;
											return 'Avg Rating: ' + actualRating.toFixed(1) + ' / 5.0';
										}
										if (label.indexOf('Rides') !== -1) {
											return 'Rides: ' + Number(value).toLocaleString('en-US');
										}
										return label + ': ' + Number(value).toLocaleString('en-US');
									}
								}
							}
						}
					}
				});
				
				console.log('Chart initialized successfully');
				document.getElementById('chartError').style.display = 'none';
			} catch (error) {
				console.error('Error initializing chart:', error);
				console.error('Error message:', error.message);
				console.error('Error stack:', error.stack);
				document.getElementById('chartError').style.display = 'block';
				document.getElementById('revChart').style.display = 'none';
			}
		}

		// Wait for both DOM and Chart.js to be ready
		function waitForChart() {
			if (typeof Chart !== 'undefined' && document.getElementById('revChart')) {
				initChart();
			} else {
				setTimeout(waitForChart, 50);
			}
		}

		// Start initialization when page loads
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', waitForChart);
		} else {
			waitForChart();
		}

		function toggleFilterInputs() {
			var v = document.getElementById('view').value;
			var dateIn = document.getElementById('dateInput');
			var yearIn = document.getElementById('yearInput');
			var monthIn = document.getElementById('monthInput');
			if (dateIn) dateIn.style.display = (v === 'daily') ? '' : 'none';
			if (yearIn) yearIn.style.display = (v === 'monthly') ? '' : 'none';
			if (monthIn) monthIn.style.display = (v === 'monthly') ? '' : 'none';
		}
		toggleFilterInputs();

		// Update month picker values when year changes
		document.addEventListener('DOMContentLoaded', function() {
			var yearSelect = document.getElementById('yearInput');
			var monthSelect = document.getElementById('monthInput');
			if (yearSelect && monthSelect) {
				yearSelect.addEventListener('change', function() {
					var selectedYear = this.value;
					var months = ['January', 'February', 'March', 'April', 'May', 'June', 
					              'July', 'August', 'September', 'October', 'November', 'December'];
					var currentValue = monthSelect.value;
					monthSelect.innerHTML = '<option value="">All Months</option>';
					for (var i = 0; i < 12; i++) {
						var monthNum = String(i + 1).padStart(2, '0');
						var monthValue = selectedYear + '-' + monthNum;
						var selected = (currentValue === monthValue) ? 'selected' : '';
						monthSelect.innerHTML += '<option value="' + monthValue + '" ' + selected + '>' + months[i] + '</option>';
					}
				});
			}
		});

		function showBoard(which) {
			const d = document.getElementById('boardDrivers');
			const u = document.getElementById('boardUsers');
			const td = document.getElementById('tabDrivers');
			const tu = document.getElementById('tabUsers');
			if (which === 'drivers') {
				d.style.display = ''; u.style.display = 'none';
				td.classList.add('active'); tu.classList.remove('active');
			} else {
				d.style.display = 'none'; u.style.display = '';
				td.classList.remove('active'); tu.classList.add('active');
			}
		}

		// Ratings Modal Functions
		function showRatingsModal() {
			const modal = document.getElementById('ratingsModal');
			if (modal) {
				modal.classList.add('active');
				document.body.style.overflow = 'hidden'; // Prevent background scrolling
			}
		}

		function closeRatingsModal(event) {
			// If event is provided and target is the modal backdrop, close it
			if (event && event.target.id === 'ratingsModal') {
				const modal = document.getElementById('ratingsModal');
				if (modal) {
					modal.classList.remove('active');
					document.body.style.overflow = ''; // Restore scrolling
				}
			} else {
				// Close button clicked
				const modal = document.getElementById('ratingsModal');
				if (modal) {
					modal.classList.remove('active');
					document.body.style.overflow = ''; // Restore scrolling
				}
			}
		}

		// Close modal with Escape key
		document.addEventListener('keydown', function(event) {
			if (event.key === 'Escape') {
				const modal = document.getElementById('ratingsModal');
				if (modal && modal.classList.contains('active')) {
					closeRatingsModal();
				}
			}
		});

		// Function to ensure bars are visible and properly filled
		function ensureBarsVisible() {
			const bars = document.querySelectorAll('.bar > span');
			bars.forEach(function(bar) {
				// Get width from data attribute or inline style
				const dataWidth = bar.getAttribute('data-width');
				if (dataWidth) {
					bar.style.width = dataWidth + '%';
				}
				
				// Force the bar to be visible
				bar.style.display = 'block';
				bar.style.visibility = 'visible';
				bar.style.opacity = '1';
				bar.style.height = '100%';
				bar.style.position = 'absolute';
				bar.style.left = '0';
				bar.style.top = '0';
				
				// Ensure background is set
				if (!bar.style.background && !bar.style.backgroundColor) {
					bar.style.background = 'linear-gradient(90deg, #2563eb, #3b82f6)';
					bar.style.backgroundColor = '#2563eb';
				}
				
				// Ensure width is applied from inline style if present
				const inlineStyle = bar.getAttribute('style');
				if (inlineStyle && inlineStyle.includes('width:')) {
					const widthMatch = inlineStyle.match(/width:\s*([^;%]+)/);
					if (widthMatch) {
						bar.style.width = widthMatch[1].trim() + '%';
					}
				}
			});
		}

		// Ensure bars are visible on page load
		document.addEventListener('DOMContentLoaded', ensureBarsVisible);
		
		// Ensure bars are visible before print/PDF generation
		window.addEventListener('beforeprint', ensureBarsVisible);
		
		// Also ensure bars are visible when print dialog opens
		window.addEventListener('focus', function() {
			setTimeout(ensureBarsVisible, 100);
		});
	</script>
</body>
</html>
<?php
// EOF
?>


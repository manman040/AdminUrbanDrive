<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Read month/year filters from query
$selectedMonth = isset($_GET['month']) ? intval($_GET['month']) : intval(date('n')); // 1-12
$selectedYear = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));
$allTime = isset($_GET['all']) && $_GET['all'] === '1' ? true : false;

$commissionRate = 20; // percent used for commission calculations
$driverProfiles = [];
$userProfiles = [];
$bookingDetailsForJs = [];

// Get real data from Firebase, fallback to mock data if Firebase is not configured
try {
    $metrics = getMetrics();
    $bookings = getBookingsData();
    
    // Ensure bookings is an array
    if ($bookings === null || !is_array($bookings)) {
        $bookings = [];
    }
    
    // Calculate daily bookings for charts
    $daily_rides = [];
    $days = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
    foreach ($days as $day) {
        $daily_rides[$day] = 0;
    }
    
    // Calculate pie chart data from actual bookings.
    // Statuses match the app's actual ride lifecycle (pending -> accepted ->
    // arrived_pickup -> in_progress -> completed, or cancelled at any point)
    // -- the app never writes 'confirmed' or 'rejected' to a booking.
    $status_counts = [
        'pending' => 0,
        'accepted' => 0,
        'arrived_pickup' => 0,
        'in_progress' => 0,
        'completed' => 0,
        'cancelled' => 0
    ];
    
    $total_amount = 0;
    
    // Process bookings data for charts (filtered by month/year unless allTime)
    if (!empty($bookings) && is_array($bookings)) {
        foreach ($bookings as $bookingId => $booking) {
            // Skip if booking is not an array
            if (!is_array($booking)) {
                continue;
            }
            $tsMs = isset($booking['createdAt']) ? firebaseTimestampValue($booking['createdAt']) : null;
            if ($tsMs) {
                $t = firebaseTimestampToSeconds($tsMs);
                $bMonth = intval(date('n', $t));
                $bYear = intval(date('Y', $t));
                if (!$allTime && ($bMonth !== $selectedMonth || $bYear !== $selectedYear)) {
                    continue; // outside selected period
                }
            } else if (!$allTime) {
                // If no timestamp and not all-time, skip because we cannot classify
                continue;
            }
            // Daily rides calculation
            if ($tsMs) {
                $day = strtoupper(date('D', firebaseTimestampToSeconds($tsMs)));
                if (isset($daily_rides[$day])) {
                    $daily_rides[$day]++;
                }
            }
            
            // Status counts for pie chart
            $status = strtolower($booking['status'] ?? 'pending');
            if (isset($status_counts[$status])) {
                $status_counts[$status]++;
            } else {
                $status_counts['pending']++; // Default to pending for unknown statuses
            }
            
            // Total amount calculation
            $total_amount += bookingFareAmount($booking);
        }
    }
    
    // Calculate total bookings for percentage calculation
    $total_bookings = array_sum($status_counts);
    
    // Calculate percentages for pie chart
    $status_percentages = [];
    foreach ($status_counts as $status => $count) {
        $status_percentages[$status] = $total_bookings > 0 ? round(($count / $total_bookings) * 100, 1) : 0;
    }
    
} catch (Exception $e) {
    // Fallback to empty data if Firebase fails
    $metrics = [
        'total_rides_today' => 0,
        'total_revenue' => 'RM 0.00',
        'active_rides' => 0,
        'average_rating' => 0.0
    ];
    
    $daily_rides = [
        'MON' => 0, 'TUE' => 0, 'WED' => 0, 'THU' => 0, 'FRI' => 0, 'SAT' => 0, 'SUN' => 0
    ];
    
    $status_counts = [
        'completed' => 0, 'confirmed' => 0, 'pending' => 0, 'cancelled' => 0, 'rejected' => 0
    ];
    
    $status_percentages = [
        'completed' => 0, 'confirmed' => 0, 'pending' => 0, 'cancelled' => 0, 'rejected' => 0
    ];
    
    $total_amount = 0;
    $total_bookings = 0;
    $bookings = [];
}

// Ensure bookings is an array for downstream usage
if (!is_array($bookings)) {
    $bookings = [];
}

// Build a safe map of bookings for the JS modal (preserve Firebase key as ID)
foreach ($bookings as $bookingId => $bookingData) {
    if (!is_array($bookingData)) {
        continue;
    }
    $bookingCopy = $bookingData;
    $bookingCopy['__resolvedId'] = $bookingData['bookingId'] ?? $bookingId;
    $bookingDetailsForJs[$bookingId] = $bookingCopy;
}

// Fetch driver-related datasets
try {
    $driversData = getFirebaseData('drivers');
} catch (Exception $e) {
    $driversData = [];
}
if (!is_array($driversData)) {
    $driversData = [];
}

try {
    $driverApprovalsData = getFirebaseData('driver_approvals');
} catch (Exception $e) {
    $driverApprovalsData = [];
}
if (!is_array($driverApprovalsData)) {
    $driverApprovalsData = [];
}

try {
    $driverCommissionsData = getFirebaseData('driver_commissions');
} catch (Exception $e) {
    $driverCommissionsData = [];
}
if (!is_array($driverCommissionsData)) {
    $driverCommissionsData = [];
}

// Compose driver profiles with fallback values pulled from multiple collections
foreach ($driversData as $driverId => $driverData) {
    if (!is_array($driverData)) {
        continue;
    }
    $vehicleLabel = '';
    if (isset($driverData['carPlateNumber'])) {
        $vehicleLabel = $driverData['carPlateNumber'];
    } elseif (isset($driverData['plateNumber'])) {
        $vehicleLabel = $driverData['plateNumber'];
    } elseif (isset($driverData['vehiclePlate'])) {
        $vehicleLabel = $driverData['vehiclePlate'];
    } elseif (isset($driverData['vehicleNo'])) {
        $vehicleLabel = $driverData['vehicleNo'];
    } elseif (isset($driverData['vehicle_number'])) {
        $vehicleLabel = $driverData['vehicle_number'];
    } elseif (isset($driverData['vehicle']) && is_array($driverData['vehicle'])) {
        $vehicleLabel = $driverData['vehicle']['plate'] ?? $driverData['vehicle']['number'] ?? '';
    }
    $driverProfiles[$driverId] = [
        'id'      => $driverId,
        'name'    => $driverData['name'] ?? $driverData['fullName'] ?? $driverData['driverName'] ?? $driverData['driver_name'] ?? $driverId,
        'email'   => $driverData['email'] ?? '',
        'phone'   => $driverData['phone'] ?? $driverData['contact'] ?? $driverData['phoneNumber'] ?? '',
        'vehicle' => $vehicleLabel
    ];
    if (isset($driverData['rating'])) {
        $driverProfiles[$driverId]['rating'] = $driverData['rating'];
    }
}

foreach ($driverApprovalsData as $driverId => $driverData) {
    if (!is_array($driverData)) {
        continue;
    }
    if (!isset($driverProfiles[$driverId])) {
        $driverProfiles[$driverId] = [
            'id' => $driverId,
            'name' => '',
            'email' => '',
            'phone' => '',
            'vehicle' => ''
        ];
    }
    if (empty($driverProfiles[$driverId]['name'])) {
        $driverProfiles[$driverId]['name'] = $driverData['name'] ?? $driverData['fullName'] ?? $driverData['driverName'] ?? $driverData['driver_name'] ?? $driverId;
    }
    if (empty($driverProfiles[$driverId]['email']) && isset($driverData['email'])) {
        $driverProfiles[$driverId]['email'] = $driverData['email'];
    }
    if (empty($driverProfiles[$driverId]['phone'])) {
        $driverProfiles[$driverId]['phone'] = $driverData['phone'] ?? $driverData['contact'] ?? '';
    }
    if (empty($driverProfiles[$driverId]['vehicle'])) {
        $driverProfiles[$driverId]['vehicle'] = $driverData['carPlateNumber'] ?? $driverData['plateNumber'] ?? $driverData['vehiclePlate'] ?? $driverData['vehicleNo'] ?? $driverData['vehicle_number'] ?? '';
    }
}

foreach ($driverCommissionsData as $driverId => $commissionData) {
    if (!is_array($commissionData)) {
        continue;
    }
    if (!isset($driverProfiles[$driverId])) {
        $driverProfiles[$driverId] = [
            'id' => $driverId,
            'name' => '',
            'email' => '',
            'phone' => '',
            'vehicle' => ''
        ];
    }
    $info = $commissionData['driver_info'] ?? [];
    if (is_array($info)) {
        if (empty($driverProfiles[$driverId]['name']) && isset($info['driver_name'])) {
            $driverProfiles[$driverId]['name'] = $info['driver_name'];
        }
        if (empty($driverProfiles[$driverId]['phone']) && isset($info['driver_phone'])) {
            $driverProfiles[$driverId]['phone'] = $info['driver_phone'];
        }
        if (empty($driverProfiles[$driverId]['email']) && isset($info['driver_email'])) {
            $driverProfiles[$driverId]['email'] = $info['driver_email'];
        }
    }
}

foreach ($driverProfiles as $driverId => &$profile) {
    if (empty($profile['name'])) {
        $profile['name'] = $driverId;
    }
    if (!isset($profile['email'])) {
        $profile['email'] = '';
    }
    if (!isset($profile['phone'])) {
        $profile['phone'] = '';
    }
    if (!isset($profile['vehicle'])) {
        $profile['vehicle'] = '';
    }
}
unset($profile);

// Fetch user dataset for modal
try {
    $usersDataRaw = getFirebaseData('users');
} catch (Exception $e) {
    $usersDataRaw = [];
}
if (!is_array($usersDataRaw)) {
    $usersDataRaw = [];
}

foreach ($usersDataRaw as $userId => $userData) {
    if (!is_array($userData)) {
        continue;
    }
    $profileData = (isset($userData['profile']) && is_array($userData['profile'])) ? $userData['profile'] : [];
    $name = $userData['name'] ?? $userData['fullName'] ?? $userData['displayName'] ?? $userData['username'] ?? ($profileData['fullName'] ?? ($profileData['name'] ?? ($userData['email'] ?? '')));
    if (!$name) {
        $name = $userId;
    }
    $email = $userData['email'] ?? ($profileData['email'] ?? '');
    $phone = $userData['phone'] ?? $userData['phoneNumber'] ?? $userData['contact'] ?? ($profileData['phone'] ?? ($profileData['mobile'] ?? ''));
    $userProfiles[$userId] = [
        'id'    => $userId,
        'name'  => $name,
        'email' => $email ?: '',
        'phone' => $phone ?: ''
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PerdaRide - Admin Dashboard</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --brand:#ff6b35; --brand2:#1e90ff; }
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body { font-family:'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; color:#333; background:linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%); min-height:100vh; }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar { width:280px; background:linear-gradient(180deg, #ffffff 0%, #f8fafc 100%); box-shadow:4px 0 20px rgba(0,0,0,0.08); padding:24px 0; position:relative; border-right:1px solid rgba(0,0,0,0.05); }

        .sidebar-header {
            padding: 0 24px 24px;
            border-bottom: 2px solid #f1f5f9;
            margin-bottom: 24px;
        }

        .sidebar-header h2 { color:#1e293b; font-size:20px; font-weight:700; letter-spacing:-0.5px; }

        .nav-item { padding:16px 24px; display:flex; align-items:center; color:#64748b; text-decoration:none; transition:all 0.25s cubic-bezier(0.4, 0, 0.2, 1); border-left:4px solid transparent; font-weight:500; font-size:14px; }

        .nav-item:hover { background:linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%); color:#ff6b35; border-left-color:#ff6b35; transform:translateX(4px); }

        .nav-item.active { background:linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%); color:#ff6b35; border-left-color:#ff6b35; font-weight:600; }

        .nav-item i {
            margin-right: 12px;
            width: 22px;
            font-size: 18px;
        }

        .main-content { flex:1; padding:32px; overflow-x:auto; }

        .header { background:linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding:32px; border-radius:20px; box-shadow:0 4px 20px rgba(0,0,0,0.08); margin-bottom:28px; border:1px solid rgba(0,0,0,0.05); }

        .header h1 { color:#1e293b; margin-bottom:0; font-size:28px; font-weight:700; letter-spacing:-0.5px; }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .metric-card { background:linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); padding:28px; border-radius:20px; box-shadow:0 4px 20px rgba(0,0,0,0.08); text-align:center; border:1px solid rgba(0,0,0,0.05); transition:transform 0.2s ease, box-shadow 0.2s ease; }
        .metric-card:hover { transform:translateY(-4px); box-shadow:0 8px 30px rgba(0,0,0,0.12); }
        .metric-card.blue { border-left:5px solid #3b82f6; background:linear-gradient(135deg, #dbeafe 0%, #ffffff 100%); }
        .metric-card.green { border-left:5px solid #10b981; background:linear-gradient(135deg, #d1fae5 0%, #ffffff 100%); }
        .metric-card.orange { border-left:5px solid #ff6b35; background:linear-gradient(135deg, #fff5f2 0%, #ffffff 100%); }
        .metric-card.purple { border-left:5px solid #8b5cf6; background:linear-gradient(135deg, #ede9fe 0%, #ffffff 100%); }

        .metric-value { font-size:36px; font-weight:700; margin-bottom:8px; color:#1e293b; letter-spacing:-1px; }

        .metric-label { color:#64748b; font-size:14px; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }

        .content-area { background:linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius:20px; box-shadow:0 4px 20px rgba(0,0,0,0.08); padding:28px; border:1px solid rgba(0,0,0,0.05); }

        .tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid #f1f5f9;
            margin-bottom: 24px;
        }

        .tab { padding:12px 20px; cursor:pointer; border-bottom:3px solid transparent; border-radius:10px 10px 0 0; font-weight:600; font-size:14px; color:#64748b; transition:all 0.2s ease; }

        .tab:hover { background:#f8fafc; color:#ff6b35; }

        .tab.active { color:#ff6b35; border-bottom-color:#ff6b35; background:linear-gradient(135deg, #fff5f2 0%, #ffffff 100%); }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .rides-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }

        .rides-header h3 {
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .filter-group {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group select { padding:10px 16px; border:2px solid #e2e8f0; border-radius:12px; font-size:14px; background:#fff; color:#1e293b; font-weight:500; transition:all 0.2s ease; }
        .filter-group select:hover { border-color:#ff6b35; }
        .filter-group select:focus { outline:none; border-color:#ff6b35; box-shadow:0 0 0 3px rgba(255, 107, 53, 0.1); }

        .bookings-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
            margin-top: 20px;
        }

        .bookings-table th,
        .bookings-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }

        .bookings-table th { background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); font-weight:700; color:#1e293b; font-size:13px; text-transform:uppercase; letter-spacing:0.5px; position:sticky; top:0; z-index:10; }

        .bookings-table tr { transition:all 0.2s ease; }
        .bookings-table tr:hover { background:linear-gradient(90deg, #fff5f2 0%, #ffffff 100%); transform:scale(1.001); box-shadow:0 2px 8px rgba(255, 107, 53, 0.1); }
        .bookings-table tr:last-child td { border-bottom:none; }

        .location-info {
            max-width: 200px;
        }

        .location-text {
            font-weight: 500;
            margin-bottom: 4px;
        }

        .location-coords {
            font-size: 12px;
            color: #666;
        }

        .status-badge { padding:6px 14px; border-radius:20px; font-size:12px; font-weight:600; display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
        .status-confirmed, .status-accepted { background:linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%); color:#065f46; border:1px solid #10b981; }
        .status-pending { background:linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color:#92400e; border:1px solid #f59e0b; }
        .status-arrived_pickup { background:linear-gradient(135deg, #ede9fe 0%, #ddd6fe 100%); color:#5b21b6; border:1px solid #8b5cf6; }
        .status-in_progress { background:linear-gradient(135deg, #ccfbf1 0%, #99f6e4 100%); color:#115e59; border:1px solid #14b8a6; }
        .status-completed { background:linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%); color:#1e40af; border:1px solid #3b82f6; }
        .status-cancelled { background:linear-gradient(135deg, #fee2e2 0%, #fecaca 100%); color:#991b1b; border:1px solid #ef4444; }

        /* Booking detail modal */
        .detail-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.45);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 60px 16px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 1200;
        }

        .detail-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .detail-modal {
            width: min(640px, 100%);
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.35);
            overflow: hidden;
            transform: translateY(-20px);
            opacity: 0;
            transition: transform 0.3s ease, opacity 0.3s ease;
            max-height: calc(100vh - 120px);
            display: flex;
            flex-direction: column;
            position: relative;
        }

        .detail-overlay.active .detail-modal {
            transform: translateY(0);
            opacity: 1;
        }

        .detail-modal-header {
            padding: 28px 28px 12px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
        }

        .detail-modal-header h2 {
            font-size: 22px;
            margin-bottom: 6px;
            color: #0f172a;
        }

        .detail-modal-header p {
            font-size: 13px;
            color: #64748b;
        }

        .modal-close {
            position: absolute;
            top: 14px;
            right: 14px;
            background: #eef2ff;
            border: none;
            color: #475569;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .modal-close:hover {
            background: #e0e7ff;
            color: #1e3a8a;
        }

        .detail-modal-body {
            padding: 16px 28px 28px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .detail-section {
            background: #f8fafc;
            border-radius: 14px;
            padding: 18px;
            border: 1px solid rgba(148, 163, 184, 0.15);
        }

        .detail-section h3 {
            font-size: 16px;
            color: #1e293b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }

        .detail-label {
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #94a3b8;
            margin-bottom: 6px;
        }

        .detail-value {
            font-size: 15px;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.4;
        }

        .detail-sub {
            font-size: 12px;
            color: #94a3b8;
            margin-top: 4px;
        }

        .detail-status {
            padding: 6px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: #e2e8f0;
            color: #0f172a;
        }

        .detail-status.status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .detail-status.status-confirmed,
        .detail-status.status-accepted,
        .detail-status.status-assigned {
            background: #e0f2fe;
            color: #1d4ed8;
        }

        .detail-status.status-pending,
        .detail-status.status-ongoing,
        .detail-status.status-in_progress {
            background: #fef3c7;
            color: #92400e;
        }

        .detail-status.status-cancelled,
        .detail-status.status-rejected {
            background: #fee2e2;
            color: #b91c1c;
        }

        .detail-hint {
            font-size: 12px;
            color: #64748b;
            margin-top: 10px;
            display: block;
        }

        @media (max-width: 600px) {
            .detail-modal {
                max-height: calc(100vh - 80px);
            }

            .detail-modal-header {
                padding: 24px 20px 12px;
            }

            .detail-modal-body {
                padding: 14px 20px 24px;
            }

            .detail-section {
                padding: 16px;
            }
        }

        .action-btn { padding:8px 16px; border:none; border-radius:10px; cursor:pointer; font-size:13px; font-weight:600; margin-right:8px; transition:all 0.2s cubic-bezier(0.4, 0, 0.2, 1); display:inline-flex; align-items:center; gap:6px; box-shadow:0 2px 4px rgba(0,0,0,0.1); }
        .action-btn:hover { transform:translateY(-2px); box-shadow:0 4px 12px rgba(0,0,0,0.15); }
        .action-btn:active { transform:translateY(0); }
        .view-btn { background:linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color:#fff; }
        .view-btn:hover { background:linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%); }
        .complete-btn { background:linear-gradient(135deg, #10b981 0%, #059669 100%); color:#fff; }
        .complete-btn:hover { background:linear-gradient(135deg, #059669 0%, #047857 100%); }

        .charts-container {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            margin-top: 24px;
        }

        .chart { text-align: center; padding: 20px; background:linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius:16px; box-shadow:0 2px 8px rgba(0,0,0,0.04); border:1px solid rgba(0,0,0,0.05); }
        .chart h3 { color:#1e293b; font-size:18px; font-weight:700; margin-bottom:20px; }

        .pie-chart-container {
            position: relative;
            width: 220px;
            height: 220px;
            margin: 0 auto 24px;
        }

        .pie-chart { width:100%; height:100%; border-radius:50%; position:relative; background:linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%); box-shadow:inset 0 2px 8px rgba(0,0,0,0.1); }

        .pie-segment {
            position: absolute;
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background: conic-gradient(
                var(--color) 0deg var(--angle),
                transparent var(--angle) 360deg
            );
            transform: rotate(var(--start-angle));
            clip-path: polygon(50% 50%, 50% 0%, 100% 0%, 100% 100%, 50% 100%);
        }

        .pie-center { position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); text-align:center; background:linear-gradient(135deg, #ffffff 0%, #f8fafc 100%); border-radius:50%; width:90px; height:90px; display:flex; flex-direction:column; align-items:center; justify-content:center; box-shadow:0 4px 12px rgba(0,0,0,0.15); color:#1e293b; border:2px solid rgba(255,255,255,0.8); }

        .total-amount { font-size:14px; font-weight:700; color:#1e293b; line-height:1.2; }

        .total-bookings { font-size:11px; color:#64748b; margin-top:4px; font-weight:500; }

        .pie-legend {
            margin-top: 20px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            margin-bottom: 8px;
            font-size: 12px;
        }

        .legend-color {
            width: 12px;
            height: 12px;
            border-radius: 2px;
            margin-right: 8px;
        }

        .legend-text {
            display: flex;
            flex-direction: column;
        }

        .legend-label { font-weight: 500; color: #333; }

        .legend-count { font-size: 10px; color: #666; }

        .bar-chart {
            height: 320px;
            display: flex;
            align-items: end;
            justify-content: space-around;
            padding: 24px 0;
        }

        .bar { width:48px; background:linear-gradient(to top, #ff6b35 0%, #ff8c5a 50%, #ffa877 100%); border-radius:8px 8px 0 0; position:relative; box-shadow:0 2px 8px rgba(255, 107, 53, 0.3); transition:transform 0.2s ease; }
        .bar:hover { transform:scaleY(1.05); }

        .bar-label {
            position: absolute;
            bottom: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 12px;
            color: #666;
        }

        .bar-value {
            position: absolute;
            top: -25px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 12px;
            font-weight: bold;
            color: #333;
        }

        .logout-btn {
            position: absolute;
            bottom: 24px;
            left: 24px;
            right: 24px;
            padding: 14px;
            background: linear-gradient(135deg, #ff6b35 0%, #ff8c5a 100%);
            color: white;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            text-decoration: none;
            text-align: center;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-weight: 600;
            font-size: 14px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            box-shadow: 0 4px 12px rgba(255, 107, 53, 0.3);
        }

        .logout-btn:hover { background:linear-gradient(135deg, #e55a2b 0%, #ff6b35 100%); transform:translateY(-2px); box-shadow:0 6px 20px rgba(255, 107, 53, 0.4); }
        .logout-btn:active { transform:translateY(0); }

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
            
            <a href="dashboard.php" class="nav-item active">
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
                <h1>PerdaRide</h1>
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
                <div class="metric-card purple">
                    <div class="metric-value"><?php echo $metrics['average_rating']; ?></div>
                    <div class="metric-label">Average Rating</div>
                </div>
            </div>

            <div class="content-area">
                <form method="get" style="display:flex; gap:12px; align-items:center; margin-bottom:16px; flex-wrap:wrap; padding:16px; background:linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%); border-radius:12px; border:1px solid #e2e8f0;">
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label for="month" style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Month</label>
                        <select id="month" name="month" style="padding:10px 16px; border:2px solid #e2e8f0; border-radius:12px; font-size:14px; background:#fff; color:#1e293b; font-weight:500; transition:all 0.2s ease; min-width:120px;">
                            <?php for ($m = 1; $m <= 12; $m++): $mn = date('M', mktime(0,0,0,$m,1)); ?>
                                <option value="<?php echo $m; ?>" <?php echo $selectedMonth === $m ? 'selected' : ''; ?>><?php echo $mn; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:4px;">
                        <label for="year" style="font-size:12px; font-weight:600; color:#64748b; text-transform:uppercase; letter-spacing:0.5px;">Year</label>
                        <select id="year" name="year" style="padding:10px 16px; border:2px solid #e2e8f0; border-radius:12px; font-size:14px; background:#fff; color:#1e293b; font-weight:500; transition:all 0.2s ease; min-width:100px;">
                            <?php $cy = intval(date('Y')); for ($y = $cy; $y >= $cy-5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $selectedYear === $y ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <label style="display:flex; align-items:center; gap:8px; padding:10px 16px; background:#fff; border-radius:12px; border:2px solid #e2e8f0; cursor:pointer; transition:all 0.2s ease; font-weight:500; color:#1e293b;">
                        <input type="checkbox" name="all" value="1" <?php echo $allTime ? 'checked' : ''; ?> onchange="this.form.submit()" style="width:18px; height:18px; cursor:pointer;"/>
                        All time
                    </label>
                    <button type="submit" class="action-btn view-btn" style="margin-top:20px;">Apply</button>
                </form>
                <div class="tabs">
                    <div class="tab active" onclick="showTab(event, 'overview')">Overview</div>
                    <div class="tab" onclick="showTab(event, 'rides')">Rides Management</div>
                </div>

                <div id="overview" class="tab-content active">
                    <div class="charts-container">
                        <div class="chart">
                            <div class="pie-chart-container">
                                <div class="pie-chart">
                                    <?php
                                    $current_angle = 0;
                                    $colors = [
                                        'pending' => '#f39c12',
                                        'accepted' => '#3498db',
                                        'arrived_pickup' => '#9b59b6',
                                        'in_progress' => '#1abc9c',
                                        'completed' => '#2ecc71',
                                        'cancelled' => '#e74c3c'
                                    ];
                                    
                                    foreach ($status_percentages as $status => $percentage):
                                        if ($percentage > 0):
                                            $angle = ($percentage / 100) * 360;
                                            $color = $colors[$status];
                                    ?>
                                    <div class="pie-segment" 
                                         style="--angle: <?php echo $angle; ?>deg; 
                                                --start-angle: <?php echo $current_angle; ?>deg; 
                                                --color: <?php echo $color; ?>;">
                                    </div>
                                    <?php
                                            $current_angle += $angle;
                                        endif;
                                    endforeach;
                                    ?>
                                </div>
                                <div class="pie-center">
                                    <div class="total-amount">RM <?php echo number_format($total_amount, 2); ?></div>
                                    <div class="total-bookings"><?php echo $total_bookings; ?> Total</div>
                                </div>
                            </div>
                            <div class="pie-legend">
                                <?php foreach ($status_counts as $status => $count): ?>
                                <?php if ($count > 0): ?>
                                <div class="legend-item">
                                    <div class="legend-color" style="background: <?php echo $colors[$status]; ?>;"></div>
                                    <div class="legend-text">
                                        <span class="legend-label"><?php echo ucwords(str_replace('_', ' ', $status)); ?></span>
                                        <span class="legend-count"><?php echo $count; ?> (<?php echo $status_percentages[$status]; ?>%)</span>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="chart">
                            <h3>Daily Ride Average</h3>
                            <div class="bar-chart">
                                <?php foreach ($daily_rides as $day => $count): ?>
                                <div class="bar" style="height: <?php echo ($count / 30) * 200; ?>px;">
                                    <div class="bar-value"><?php echo $count; ?></div>
                                    <div class="bar-label"><?php echo $day; ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="rides" class="tab-content">
                    <div class="rides-header">
                        <h3><i class="fas fa-car"></i> Booking Management</h3>
                        <div class="filter-group">
                            <select id="statusFilter">
                                <option value="">All Status</option>
                                <option value="confirmed">Confirmed</option>
                                <option value="pending">Pending</option>
                                <option value="completed">Completed</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                            <select id="sortOrder">
                                <option value="desc">Top to bottom (Newest first)</option>
                                <option value="asc">Bottom to top (Oldest first)</option>
                            </select>
                            <span>Showing <?php echo count($bookings); ?> bookings</span>
                        </div>
                    </div>

                    <table class="bookings-table">
                        <thead>
                            <tr>
                                <th>Booking ID</th>
                                <th>Pickup Location</th>
                                <th>Dropoff Location</th>
                                <th>Distance</th>
                                <th>Fare</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookings)): ?>
                            <tr><td colspan="8" style="text-align:center;color:#666;">No bookings found</td></tr>
                            <?php else: foreach ($bookings as $bookingId => $booking): ?>
                            <tr data-created="<?php echo isset($booking['createdAt']) ? intval(firebaseTimestampValue($booking['createdAt'])) : 0; ?>">
                                <td><?php echo htmlspecialchars($booking['bookingId'] ?? $bookingId); ?></td>
                                <td>
                                    <div class="location-info">
                                        <div class="location-text"><?php echo htmlspecialchars(firstBookingValue($booking, ['pickupLocation', 'pickupAddress'])); ?></div>
                                        <div class="location-coords"><?php echo htmlspecialchars(($booking['pickupLat'] ?? '') . ', ' . ($booking['pickupLng'] ?? '')); ?></div>
                                    </div>
                                </td>
                                <td>
                                    <div class="location-info">
                                        <div class="location-text"><?php echo htmlspecialchars(firstBookingValue($booking, ['dropoffLocation', 'dropoffAddress'])); ?></div>
                                        <div class="location-coords"><?php echo htmlspecialchars(($booking['dropoffLat'] ?? '') . ', ' . ($booking['dropoffLng'] ?? '')); ?></div>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars(round(bookingDistanceKm($booking), 2)) . ' km'; ?></td>
                                <td><?php echo 'RM ' . htmlspecialchars(number_format(bookingFareAmount($booking), 2)); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo strtolower($booking['status'] ?? 'pending'); ?>">
                                        <?php echo ucwords(str_replace('_', ' ', $booking['status'] ?? 'pending')); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    if (isset($booking['createdAt'])) {
                                        echo date('M d, Y H:i', firebaseTimestampToSeconds($booking['createdAt']));
                                    } else {
                                        echo 'N/A';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <button class="action-btn view-btn" onclick="viewBooking('<?php echo $bookingId; ?>')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (strtolower($booking['status'] ?? '') === 'in_progress'): ?>
                                        <button class="action-btn complete-btn" onclick="completeBooking('<?php echo $bookingId; ?>')">
                                            <i class="fas fa-check"></i>
                                        </button>
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

    <div id="bookingModalOverlay" class="detail-overlay" aria-hidden="true">
        <div class="detail-modal" role="dialog" aria-modal="true" aria-labelledby="bookingModalTitle">
            <button type="button" class="modal-close" id="bookingModalClose" aria-label="Close details">
                <i class="fas fa-times"></i>
            </button>
            <div class="detail-modal-header">
                <div>
                    <h2 id="bookingModalTitle">Ride details</h2>
                    <p id="bookingModalSubtitle"></p>
                </div>
                <span id="bookingModalStatus" class="detail-status">Status</span>
            </div>
            <div class="detail-modal-body" id="bookingModalBody"></div>
        </div>
    </div>

    <script>
        const BOOKINGS_DATA = <?php echo json_encode($bookingDetailsForJs, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
        const DRIVER_PROFILES = <?php echo json_encode($driverProfiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
        const USER_PROFILES = <?php echo json_encode($userProfiles, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?> || {};
        const COMMISSION_RATE = <?php echo json_encode($commissionRate); ?>;

        const bookingModalOverlay = document.getElementById('bookingModalOverlay');
        const bookingModalBody = document.getElementById('bookingModalBody');
        const bookingModalTitle = document.getElementById('bookingModalTitle');
        const bookingModalSubtitle = document.getElementById('bookingModalSubtitle');
        const bookingModalStatus = document.getElementById('bookingModalStatus');
        const bookingModalClose = document.getElementById('bookingModalClose');

        if (bookingModalClose) {
            bookingModalClose.addEventListener('click', closeBookingModal);
        }
        if (bookingModalOverlay) {
            bookingModalOverlay.addEventListener('click', function (event) {
                if (event.target === bookingModalOverlay) {
                    closeBookingModal();
                }
            });
        }
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeBookingModal();
            }
        });

        function showTab(evt, tabName) {
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => content.classList.remove('active'));

            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => tab.classList.remove('active'));

            const target = document.getElementById(tabName);
            if (target) {
                target.classList.add('active');
            }
            if (evt && evt.currentTarget && evt.currentTarget.classList) {
                evt.currentTarget.classList.add('active');
            }
        }

        document.addEventListener('DOMContentLoaded', function () {
            const statusFilter = document.getElementById('statusFilter');
            const sortOrder = document.getElementById('sortOrder');
            if (statusFilter) {
                statusFilter.addEventListener('change', function () {
                    const status = this.value.toLowerCase();
                    const rows = document.querySelectorAll('.bookings-table tbody tr');

                    rows.forEach(row => {
                        const statusCell = row.querySelector('.status-badge');
                        if (status === '' || (statusCell && statusCell.textContent.toLowerCase().includes(status))) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
            if (sortOrder) {
                const applySort = () => {
                    const tbody = document.querySelector('.bookings-table tbody');
                    const rows = Array.from(tbody.querySelectorAll('tr'));
                    const dir = sortOrder.value === 'asc' ? 1 : -1;
                    rows.sort((a, b) => {
                        const ta = parseInt(a.getAttribute('data-created') || '0', 10);
                        const tb = parseInt(b.getAttribute('data-created') || '0', 10);
                        return (ta - tb) * dir;
                    });
                    rows.forEach(r => tbody.appendChild(r));
                };
                sortOrder.addEventListener('change', applySort);
                applySort();
            }
        });

        function openBookingModal() {
            if (!bookingModalOverlay) return;
            bookingModalOverlay.classList.add('active');
            bookingModalOverlay.setAttribute('aria-hidden', 'false');
            if (document.body) {
                document.body.dataset.prevOverflow = document.body.style.overflow || '';
                document.body.style.overflow = 'hidden';
            }
        }

        function closeBookingModal() {
            if (!bookingModalOverlay) return;
            bookingModalOverlay.classList.remove('active');
            bookingModalOverlay.setAttribute('aria-hidden', 'true');
            if (document.body && Object.prototype.hasOwnProperty.call(document.body.dataset, 'prevOverflow')) {
                document.body.style.overflow = document.body.dataset.prevOverflow;
                delete document.body.dataset.prevOverflow;
            }
        }

        function escapeHtml(value) {
            if (value === null || value === undefined) return '';
            return String(value).replace(/[&<>"']/g, function (char) {
                const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
                return map[char] || char;
            });
        }

        function getNested(obj, path) {
            if (!obj || typeof obj !== 'object' || !path) return undefined;
            const keys = path.split('.');
            let current = obj;
            for (const key of keys) {
                if (current && Object.prototype.hasOwnProperty.call(current, key)) {
                    current = current[key];
                } else {
                    return undefined;
                }
            }
            return current;
        }

        function firstNonEmpty(obj, paths) {
            for (const path of paths) {
                let value = getNested(obj, path);
                if (value === undefined || value === null) {
                    continue;
                }
                if (typeof value === 'object') {
                    if (typeof value.address === 'string' && value.address.trim() !== '') {
                        value = value.address;
                    } else if (typeof value.formattedAddress === 'string' && value.formattedAddress.trim() !== '') {
                        value = value.formattedAddress;
                    } else if (typeof value.name === 'string' && value.name.trim() !== '') {
                        value = value.name;
                    } else {
                        continue;
                    }
                }
                if (typeof value === 'string') {
                    const trimmed = value.trim();
                    if (trimmed === '') continue;
                    return trimmed;
                }
                return value;
            }
            return '';
        }

        function toNumber(value) {
            if (value === null || value === undefined) return 0;
            const num = parseFloat(value);
            return Number.isFinite(num) ? num : 0;
        }

        function formatCurrency(value) {
            return 'RM ' + toNumber(value).toFixed(2);
        }

        function formatDistance(value) {
            const num = toNumber(value);
            return num ? num.toFixed(2) + ' km' : '-';
        }

        function capFirst(word) {
            if (!word) return '';
            return word.charAt(0).toUpperCase() + word.slice(1);
        }

        function toTitleCase(str) {
            return str.split(/\s+/).map(capFirst).join(' ');
        }

        function parseTimestamp(value) {
            if (typeof value === 'number') {
                return value > 1e12 ? value : value * 1000;
            }
            if (typeof value === 'string') {
                const trimmed = value.trim();
                if (/^\d{10,13}$/.test(trimmed)) {
                    const numeric = parseInt(trimmed, 10);
                    return trimmed.length === 13 ? numeric : numeric * 1000;
                }
                const date = new Date(trimmed);
                return isNaN(date.getTime()) ? NaN : date.getTime();
            }
            if (value && typeof value === 'object') {
                if (typeof value.time === 'number') {
                    return value.time;
                }
                if (typeof value.millis === 'number') {
                    return value.millis;
                }
                if (typeof value.seconds === 'number') {
                    return value.seconds * 1000;
                }
            }
            return NaN;
        }

        function firstTimestamp(obj, paths) {
            for (const path of paths) {
                const candidate = getNested(obj, path);
                const parsed = parseTimestamp(candidate);
                if (!Number.isNaN(parsed)) {
                    return parsed;
                }
            }
            return null;
        }

        function formatDateTime(milliseconds) {
            if (!milliseconds) return '';
            const date = new Date(milliseconds);
            if (Number.isNaN(date.getTime())) return '';
            return date.toLocaleString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
        }

        function renderProfileCard(label, id, profile) {
            if (!id) {
                return `<div>
                    <div class="detail-label">${escapeHtml(label)}</div>
                    <div class="detail-value">Not assigned</div>
                </div>`;
            }
            const pieces = [
                `<div class="detail-label">${escapeHtml(label)}</div>`,
                `<div class="detail-value">${escapeHtml(profile && profile.name ? profile.name : id)}</div>`,
                `<div class="detail-sub">ID: ${escapeHtml(id)}</div>`
            ];
            if (profile) {
                if (profile.phone) {
                    pieces.push(`<div class="detail-sub">Phone: ${escapeHtml(profile.phone)}</div>`);
                }
                if (profile.email) {
                    pieces.push(`<div class="detail-sub">Email: ${escapeHtml(profile.email)}</div>`);
                }
                if (profile.vehicle) {
                    pieces.push(`<div class="detail-sub">Vehicle: ${escapeHtml(profile.vehicle)}</div>`);
                }
                if (profile.rating) {
                    pieces.push(`<div class="detail-sub">Rating: ${escapeHtml(profile.rating)}</div>`);
                }
            }
            return `<div>${pieces.join('')}</div>`;
        }

        function viewBooking(bookingId) {
            if (!BOOKINGS_DATA || !Object.prototype.hasOwnProperty.call(BOOKINGS_DATA, bookingId)) {
                alert('Booking details not found for ID: ' + bookingId);
                return;
            }
            const booking = BOOKINGS_DATA[bookingId] || {};
            const rideId = booking.__resolvedId || booking.bookingId || bookingId;
            const createdMs = firstTimestamp(booking, [
                'createdAt.time',
                'createdAt',
                'created_at.time',
                'created_at',
                'timestamp.time',
                'timestamp',
                'completedAt.time',
                'completedAt'
            ]);
            const createdText = formatDateTime(createdMs);
            const statusRaw = (booking.status ?? '').toString().toLowerCase();
            const statusLabel = statusRaw ? toTitleCase(statusRaw.replace(/[_-]+/g, ' ')) : 'Unknown';

            const pickup = firstNonEmpty(booking, ['pickupLocation', 'pickupAddress', 'origin.address', 'origin.name', 'origin']);
            const dropoff = firstNonEmpty(booking, ['dropoffLocation', 'dropoffAddress', 'destination.address', 'destination.name', 'destination']);
            const pickupLat = firstNonEmpty(booking, ['pickupLat', 'pickup_lat', 'pickup.latitude', 'origin.lat', 'origin.latitude']);
            const pickupLng = firstNonEmpty(booking, ['pickupLng', 'pickup_lng', 'pickup.longitude', 'origin.lng', 'origin.longitude']);
            const dropLat = firstNonEmpty(booking, ['dropoffLat', 'dropoff_lat', 'dropoff.latitude', 'destination.lat', 'destination.latitude']);
            const dropLng = firstNonEmpty(booking, ['dropoffLng', 'dropoff_lng', 'dropoff.longitude', 'destination.lng', 'destination.longitude']);
            const pickupCoords = pickupLat && pickupLng ? `${pickupLat}, ${pickupLng}` : '';
            const dropoffCoords = dropLat && dropLng ? `${dropLat}, ${dropLng}` : '';

            const distanceValue = firstNonEmpty(booking, ['distanceKm', 'distance_km', 'tripDistance', 'distance']);
            const distanceText = formatDistance(distanceValue);

            const fareValue = toNumber(firstNonEmpty(booking, ['estimatedFare', 'fare', 'totalFare', 'price', 'amount', 'totalFareAmount']));
            const commissionValue = COMMISSION_RATE ? fareValue * (COMMISSION_RATE / 100) : 0;
            const paymentMethod = firstNonEmpty(booking, ['paymentMethod', 'payment', 'payment_type', 'paymentType', 'paymentMethodType']);

            const userId = firstNonEmpty(booking, ['userId', 'customerId', 'passengerId', 'riderId', 'uid']);
            const driverId = firstNonEmpty(booking, ['driverId', 'driver_id', 'assignedDriverId', 'captainId', 'providerId']);

            const userProfile = userId && USER_PROFILES ? USER_PROFILES[userId] : null;
            const driverProfile = driverId && DRIVER_PROFILES ? DRIVER_PROFILES[driverId] : null;

            if (bookingModalTitle) {
                bookingModalTitle.textContent = `Ride ${rideId}`;
            }

            if (bookingModalSubtitle) {
                const subtitleParts = [];
                if (createdText) subtitleParts.push(`Created ${createdText}`);
                if (paymentMethod) subtitleParts.push(`Payment ${paymentMethod}`);
                bookingModalSubtitle.textContent = subtitleParts.join(' • ');
                bookingModalSubtitle.style.display = subtitleParts.length ? 'block' : 'none';
            }

            if (bookingModalStatus) {
                const statusClassName = statusRaw ? `detail-status status-${statusRaw.replace(/\s+/g, '_')}` : 'detail-status';
                bookingModalStatus.className = statusClassName;
                bookingModalStatus.textContent = statusLabel;
                bookingModalStatus.style.display = statusLabel ? 'inline-flex' : 'none';
            }

            const routeSection = `
                <div class="detail-section">
                    <h3><i class="fas fa-route"></i> Route</h3>
                    <div class="detail-grid">
                        <div>
                            <div class="detail-label">Pickup</div>
                            <div class="detail-value">${escapeHtml(pickup || '-')}</div>
                            ${pickupCoords ? `<div class="detail-sub">${escapeHtml(pickupCoords)}</div>` : ''}
                        </div>
                        <div>
                            <div class="detail-label">Dropoff</div>
                            <div class="detail-value">${escapeHtml(dropoff || '-')}</div>
                            ${dropoffCoords ? `<div class="detail-sub">${escapeHtml(dropoffCoords)}</div>` : ''}
                        </div>
                    </div>
                </div>
            `;

            const commissionHint = fareValue > 0 ? '<span class="detail-hint">Commission is calculated automatically based on the recorded fare.</span>' : '';
            const summarySection = `
                <div class="detail-section">
                    <h3><i class="fas fa-file-invoice-dollar"></i> Trip Summary</h3>
                    <div class="detail-grid">
                        <div>
                            <div class="detail-label">Distance</div>
                            <div class="detail-value">${escapeHtml(distanceText)}</div>
                        </div>
                        <div>
                            <div class="detail-label">Fare</div>
                            <div class="detail-value">${escapeHtml(formatCurrency(fareValue))}</div>
                        </div>
                        <div>
                            <div class="detail-label">Commission (${COMMISSION_RATE}%)</div>
                            <div class="detail-value">${escapeHtml(formatCurrency(commissionValue))}</div>
                        </div>
                        <div>
                            <div class="detail-label">Payment Method</div>
                            <div class="detail-value">${escapeHtml(paymentMethod || '-')}</div>
                        </div>
                    </div>
                    ${commissionHint}
                </div>
            `;

            const participantsSection = `
                <div class="detail-section">
                    <h3><i class="fas fa-user-group"></i> Participants</h3>
                    <div class="detail-grid">
                        ${renderProfileCard('User', userId, userProfile)}
                        ${renderProfileCard('Driver', driverId, driverProfile)}
                    </div>
                </div>
            `;

            if (bookingModalBody) {
                bookingModalBody.innerHTML = routeSection + summarySection + participantsSection;
            }

            openBookingModal();
        }

        function completeBooking(bookingId) {
            if (confirm('Are you sure you want to mark this booking as completed?')) {
                fetch('update-booking-status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        bookingId: bookingId,
                        status: 'completed'
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Booking marked as completed!');
                        location.reload();
                    } else {
                        alert('Error updating booking: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error updating booking: ' + error);
                });
            }
        }
    </script>
</body>
</html>

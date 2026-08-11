<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

if (!function_exists('extractTimestampFromValue')) {
    function extractTimestampFromValue($value) {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = urldecode($value);
        if (preg_match('/_(\d{10,})\.(?:jpe?g|png)/i', $decoded, $matches)) {
            $ts = $matches[1];
            $intTs = (int) $ts;
            if (strlen($ts) <= 10) {
                return $intTs * 1000;
            }
            return $intTs;
        }
        return null;
    }
}

if (!function_exists('getDocumentSourceValues')) {
    function getDocumentSourceValues(array $app, string $docType) {
        $map = [
            'idCard' => ['icPhotoFrontPath', 'icPhotoBackPath', 'icPhotoPath'],
            'license' => ['licensePhotoFrontPath', 'licensePhotoBackPath', 'licensePhotoPath'],
            'carGrant' => ['grantPhotoPath'],
            'carImage' => ['carPhotoFrontPath', 'carPhotoBackPath', 'carPhotoLeftPath', 'carPhotoRightPath', 'carPhotoPath']
        ];
        $sources = [];
        foreach ($map[$docType] ?? [] as $field) {
            if (!empty($app[$field]) && is_string($app[$field])) {
                $sources[] = $app[$field];
            }
        }
        return $sources;
    }
}

if (!function_exists('documentHasNewUpload')) {
    function documentHasNewUpload(array $app, string $docType) {
        $rejectedMs = null;
        
        if (isset($app['rejectedAt'])) {
            $rejectedMs = (int) firebaseTimestampValue($app['rejectedAt']);
        } elseif (isset($app['rejected_at']) && is_string($app['rejected_at'])) {
            $rejectedTimestamp = strtotime($app['rejected_at']);
            if ($rejectedTimestamp !== false) {
                $rejectedMs = $rejectedTimestamp * 1000;
            }
        } elseif (isset($app['updatedAt'])) {
            $rejectedMs = (int) firebaseTimestampValue($app['updatedAt']);
        } elseif (isset($app['updated_at']) && is_string($app['updated_at'])) {
            $updatedTimestamp = strtotime($app['updated_at']);
            if ($updatedTimestamp !== false) {
                $rejectedMs = $updatedTimestamp * 1000;
            }
        }

        if ($rejectedMs === null) {
            return false;
        }
        
        $sources = getDocumentSourceValues($app, $docType);
        foreach ($sources as $source) {
            $uploadTs = extractTimestampFromValue($source);
            if ($uploadTs !== null && $uploadTs > $rejectedMs) {
                return true;
            }
        }
        return false;
    }
}

// Get real data from Firebase, fallback to mock data if Firebase is not configured
$errorMessage = null;
$debugInfo = [];
try {
    // Check multiple paths separately for debugging
    $testPaths = ['driver_approvals', 'driver_applications', 'driverApplications', 'applications'];
    foreach ($testPaths as $testPath) {
        $testData = getFirebaseData($testPath);
        $count = 0;
        if (is_array($testData)) {
            // Check if it's an error response
            if (isset($testData['error'])) {
                $debugInfo[$testPath . '_error'] = $testData['error'];
                $count = 0;
            } else {
                $count = count($testData);
            }
        }
        $debugInfo[$testPath . '_count'] = $count;
        $debugInfo[$testPath . '_type'] = gettype($testData);
    }
    
    // Get merged applications
    $applications = getDriverApplications();
    
    // Log what we got from Firebase
    error_log('Driver Applications fetched: ' . gettype($applications) . ' - Count: ' . (is_array($applications) ? count($applications) : 'N/A'));
    error_log('driver_approvals count: ' . $debugInfo['driver_approvals_count']);
    error_log('driver_applications count: ' . $debugInfo['driver_applications_count']);
    
    // Convert Firebase data to array format if needed
    if ($applications === null || !is_array($applications)) {
        $applications = [];
        error_log('Applications is null or not array, setting to empty array');
    }
    
    // If applications is associative array (Firebase format), convert to indexed array for display
    $applicationsArray = [];
    if (!empty($applications) && is_array($applications)) {
        foreach ($applications as $appId => $application) {
            // Skip if application is not an array
            if (!is_array($application)) {
                error_log("Skipping application $appId: not an array");
                continue;
            }
            $application['id'] = $appId; // Add the Firebase key as ID
            $applicationsArray[] = $application;
        }
    }
    $applications = $applicationsArray;
    
    error_log('Processed applications count: ' . count($applications));
    
    // Calculate summary
    $summary = [
        'total_applications' => count($applications),
        'approved' => count(array_filter($applications, function($app) { 
            return is_array($app) && isset($app['status']) && strtolower($app['status']) === 'approved'; 
        })),
        'pending' => count(array_filter($applications, function($app) { 
            return is_array($app) && isset($app['status']) && strtolower($app['status']) === 'pending'; 
        })),
        'waiting_for_resubmission' => count(array_filter($applications, function($app) { 
            return is_array($app) && isset($app['status']) && strtolower($app['status']) === 'waiting_for_resubmission'; 
        })),
        'rejected' => count(array_filter($applications, function($app) { 
            return is_array($app) && isset($app['status']) && strtolower($app['status']) === 'rejected'; 
        }))
    ];
    
} catch (Exception $e) {
    // If Firebase fails, ensure arrays are empty
    $errorMessage = $e->getMessage();
    error_log('Error fetching driver applications: ' . $errorMessage);
    error_log('Stack trace: ' . $e->getTraceAsString());
    $applications = [];
    $summary = [
        'total_applications' => 0,
        'approved' => 0,
        'pending' => 0,
        'waiting_for_resubmission' => 0,
        'rejected' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Driver Requests - PerdaRide</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        .container {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 280px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            box-shadow: 4px 0 20px rgba(0,0,0,0.08);
            padding: 24px 0;
            position: relative;
            border-right: 1px solid rgba(0,0,0,0.05);
        }

        .sidebar-header {
            padding: 0 24px 24px;
            border-bottom: 2px solid #f1f5f9;
            margin-bottom: 24px;
        }

        .sidebar-header h2 {
            color: #1e293b;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
            margin-bottom: 4px;
        }

        .sidebar-header p {
            color: #64748b;
            font-size: 12px;
            font-weight: 500;
            margin: 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .nav-item {
            padding: 16px 24px;
            display: flex;
            align-items: center;
            color: #64748b;
            text-decoration: none;
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            border-left: 4px solid transparent;
            font-weight: 500;
            font-size: 14px;
        }

        .nav-item:hover {
            background: linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%);
            color: #ff6b35;
            border-left-color: #ff6b35;
            transform: translateX(4px);
        }

        .nav-item.active {
            background: linear-gradient(90deg, #fff5f2 0%, #ffe5e0 100%);
            color: #ff6b35;
            border-left-color: #ff6b35;
            font-weight: 600;
        }

        .nav-item i {
            margin-right: 12px;
            width: 22px;
            font-size: 18px;
        }

        .main-content {
            flex: 1;
            padding: 32px;
            overflow-x: auto;
            margin-top: 70px;
        }

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

        .header {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            padding: 32px;
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            margin-bottom: 28px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .header h1 {
            color: #1e293b;
            margin-bottom: 8px;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 24px;
            margin-bottom: 32px;
        }

        .summary-card {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            padding: 28px;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            text-align: center;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 12px;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            border: 1px solid rgba(0,0,0,0.05);
            position: relative;
            overflow: hidden;
        }

        .summary-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #ff6b35, #ff8c5a);
            transform: scaleX(0);
            transition: transform 0.3s ease;
        }

        .summary-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }

        .summary-card:hover::before {
            transform: scaleX(1);
        }

        .summary-card i {
            font-size: 32px;
            color: #ff6b35;
            margin-bottom: 8px;
            transition: transform 0.3s ease;
        }

        .summary-card:hover i {
            transform: scale(1.1);
        }

        .summary-card.approved {
            background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
        }

        .summary-card.approved i { 
            color: #16a34a; 
        }

        .summary-card.approved::before {
            background: linear-gradient(90deg, #16a34a, #22c55e);
        }

        .summary-card.pending {
            background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
        }

        .summary-card.pending i { 
            color: #f59e0b; 
        }

        .summary-card.pending::before {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }

        .summary-card.rejected {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
        }

        .summary-card.rejected i { 
            color: #dc2626; 
        }

        .summary-card.rejected::before {
            background: linear-gradient(90deg, #dc2626, #ef4444);
        }

        .summary-card.total {
            background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);
        }

        .summary-card.total i { 
            color: #2563eb; 
        }

        .summary-card.total::before {
            background: linear-gradient(90deg, #2563eb, #3b82f6);
        }

        .summary-value {
            font-size: 36px;
            font-weight: 800;
            color: #1e293b;
            line-height: 1;
            letter-spacing: -1px;
        }

        .summary-label {
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .content-area {
            background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
            border-radius: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            padding: 28px;
            border: 1px solid rgba(0,0,0,0.05);
        }

        .content-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .content-header h3 {
            color: #1e293b;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 20px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .filter-group {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-group select {
            padding: 10px 16px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 14px;
            background: white;
            color: #1e293b;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .filter-group select:hover {
            border-color: #ff6b35;
        }

        .filter-group select:focus {
            outline: none;
            border-color: #ff6b35;
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.1);
        }

        .applications-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            margin-top: 20px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.04);
        }

        .applications-table th,
        .applications-table td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #f1f5f9;
        }

        .applications-table th {
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
            font-weight: 700;
            color: #1e293b;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        .applications-table tr {
            transition: all 0.2s ease;
        }

        .applications-table tr:hover {
            background: linear-gradient(90deg, #fff5f2 0%, #ffffff 100%);
            transform: scale(1.001);
            box-shadow: 0 2px 8px rgba(255, 107, 53, 0.1);
        }

        .applications-table tr:last-child td {
            border-bottom: none;
        }

        .status-badge {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-transform: capitalize;
            letter-spacing: 0.3px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .status-pending {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #92400e;
            border: 1px solid #fbbf24;
        }

        .status-approved {
            background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            color: #065f46;
            border: 1px solid #10b981;
        }

        .status-waiting_for_resubmission {
            background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            color: #b45309;
            border: 1px solid #f59e0b;
        }

        .status-rejected {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #991b1b;
            border: 1px solid #ef4444;
        }

        .doc-alert {
            font-size: 12px;
            font-weight: 600;
            padding: 10px 14px;
            border-radius: 10px;
            border: 2px solid transparent;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
            transition: all 0.2s ease;
        }

        .doc-alert:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.12);
        }

        .doc-alert i {
            font-size: 14px;
        }

        .doc-alert-danger {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #b91c1c;
            border-color: #fecaca;
        }

        .doc-alert-warning {
            background: linear-gradient(135deg, #fefce8 0%, #fef3c7 100%);
            color: #92400e;
            border-color: #fef3c7;
        }

        .doc-resubmitted-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fefce8;
            color: #92400e;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 999px;
            margin-left: 6px;
        }

        .doc-resubmitted-pill i {
            font-size: 10px;
        }

        .action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 10px;
            cursor: pointer;
            margin-right: 8px;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }

        .action-btn:active {
            transform: translateY(0);
        }

        .approve-btn {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }

        .approve-btn:hover {
            background: linear-gradient(135deg, #059669 0%, #047857 100%);
        }

        .reject-btn {
            background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            color: white;
        }

        .reject-btn:hover {
            background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
        }

        .view-btn {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            color: white;
        }

        .view-btn:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
        }

        .delete-btn {
            background: linear-gradient(135deg, #64748b 0%, #475569 100%);
            color: white;
        }

        .delete-btn:hover {
            background: linear-gradient(135deg, #475569 0%, #334155 100%);
        }

        .actions-cell {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .actions-cell .action-btn {
            margin-right: 0;
        }

        .details-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 60px 16px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 1200;
        }

        .details-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .details-modal {
            width: min(720px, 100%);
            max-height: calc(100vh - 120px);
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
            animation: scaleIn 0.2s ease;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .details-modal header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 16px;
            padding: 24px 28px;
            border-bottom: 1px solid #f1f5f9;
        }

        .details-modal header h3 {
            font-size: 20px;
            color: #0f172a;
            margin: 0 0 4px;
        }

        .details-modal header p {
            font-size: 13px;
            color: #64748b;
            margin: 0;
        }

        .details-close-btn {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #f1f5f9;
            border: none;
            cursor: pointer;
            font-size: 18px;
            line-height: 1;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s ease;
            flex-shrink: 0;
        }

        .details-close-btn:hover {
            background: #e2e8f0;
            color: #1e293b;
        }

        .details-modal-body {
            padding: 24px 28px;
            overflow-y: auto;
        }

        .details-modal footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 18px 28px;
            border-top: 1px solid #f1f5f9;
            background: #f8fafc;
        }

        .details-modal footer:empty {
            display: none;
        }

        .detail-block {
            margin-bottom: 20px;
        }

        .detail-block:last-child {
            margin-bottom: 0;
        }

        .detail-block h4 {
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #94a3b8;
            font-weight: 700;
            margin: 0 0 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #f1f5f9;
        }

        .detail-row {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 6px 0;
            font-size: 14px;
        }

        .detail-row label {
            font-weight: 600;
            color: #475569;
            min-width: 130px;
        }

        .detail-row span {
            color: #1e293b;
        }

        .confirm-modal .icon {
            width: 56px;
            height: 56px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 14px;
        }

        .confirm-modal .icon.danger {
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        }

        .confirm-modal .icon.danger i {
            font-size: 26px;
            color: #dc2626;
        }

        .delete-app-checkbox {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            text-align: left;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 13px;
            color: #475569;
            margin-bottom: 20px;
            cursor: pointer;
        }

        .delete-app-checkbox input {
            margin-top: 2px;
        }

        .reject-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 60px 16px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.25s ease;
            z-index: 1200;
        }

        .reject-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .confirm-overlay {
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.55);
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 24px;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            z-index: 1300;
        }

        .confirm-overlay.active {
            opacity: 1;
            pointer-events: auto;
        }

        .confirm-modal {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 18px;
            padding: 28px 32px;
            box-shadow: 0 25px 60px rgba(15, 23, 42, 0.25);
            text-align: center;
            animation: scaleIn 0.2s ease;
        }

        .confirm-modal header {
            margin-bottom: 12px;
        }

        .confirm-modal h3 {
            font-size: 20px;
            color: #0f172a;
            margin-bottom: 8px;
        }

        .confirm-modal p {
            font-size: 14px;
            color: #475569;
            line-height: 1.6;
            margin-bottom: 24px;
        }

        .confirm-actions {
            display: flex;
            justify-content: center;
            gap: 14px;
        }

        .confirm-btn {
            min-width: 120px;
            border: none;
            border-radius: 999px;
            padding: 12px 20px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .confirm-btn:active {
            transform: scale(0.97);
        }

        .confirm-btn.cancel {
            background: #ede9fe;
            color: #6d28d9;
        }

        .confirm-btn.primary {
            background: linear-gradient(90deg, #8b5cf6, #ec4899);
            color: #fff;
            box-shadow: 0 10px 30px rgba(236, 72, 153, 0.35);
        }

        .confirm-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        @keyframes scaleIn {
            from {
                transform: scale(0.92);
                opacity: 0;
            }
            to {
                transform: scale(1);
                opacity: 1;
            }
        }

        .app-toast {
            position: fixed;
            top: 24px;
            right: 24px;
            min-width: 240px;
            background: #0f172a;
            color: #fff;
            padding: 14px 18px;
            border-radius: 12px;
            box-shadow: 0 15px 35px rgba(15, 23, 42, 0.25);
            opacity: 0;
            transform: translateY(-10px);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
            z-index: 1500;
            font-size: 14px;
        }

        .app-toast.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .app-toast.success {
            background: #16a34a;
        }

        .app-toast.error {
            background: #dc2626;
        }

        .reject-modal {
            width: min(720px, 100%);
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

        .reject-overlay.active .reject-modal {
            transform: translateY(0);
            opacity: 1;
        }

        .reject-modal header {
            padding: 26px 28px 10px;
        }

        .reject-modal header h2 {
            margin: 0;
            font-size: 22px;
            color: #0f172a;
        }

        .reject-modal header p {
            margin: 6px 0 0;
            color: #64748b;
            font-size: 13px;
        }

        .reject-close-btn {
            position: absolute;
            top: 14px;
            right: 14px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            border: none;
            background: #eef2ff;
            color: #475569;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s ease, color 0.2s ease;
        }

        .reject-close-btn:hover {
            background: #e0e7ff;
            color: #1e3a8a;
        }

        .reject-modal-body {
            padding: 18px 28px 26px;
            overflow-y: auto;
        }

        .reject-doc-list {
            display: flex;
            flex-direction: column;
            gap: 14px;
            margin-bottom: 24px;
        }

        .reject-doc-item {
            display: flex;
            gap: 16px;
            padding: 14px;
            border-radius: 14px;
            border: 1px solid rgba(148, 163, 184, 0.18);
            background: #f8fafc;
            align-items: center;
        }

        .reject-doc-preview {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .reject-doc-preview img {
            width: 72px;
            height: 72px;
            object-fit: cover;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.15);
        }

        .reject-doc-placeholder {
            width: 72px;
            height: 72px;
            border-radius: 12px;
            background: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #64748b;
            font-size: 24px;
        }

        .reject-doc-label {
            font-weight: 600;
            color: #0f172a;
            font-size: 15px;
        }

        .reject-doc-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .reject-doc-actions label {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: #475569;
            background: #fff;
            border-radius: 999px;
            padding: 6px 14px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .reject-doc-actions input[type="radio"] {
            accent-color: #ff6b35;
        }

        .reject-doc-actions label:hover {
            border-color: #ff6b35;
            background: rgba(255, 107, 53, 0.08);
        }

        .reject-reason-group {
            margin-bottom: 20px;
        }

        .reject-reason-group h3 {
            font-size: 16px;
            color: #1e293b;
            margin-bottom: 10px;
        }

        .reject-reasons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin-bottom: 16px;
        }

        .reject-reasons label {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            background: #f1f5f9;
            border: 1px solid transparent;
            border-radius: 12px;
            padding: 10px 12px;
            font-size: 13px;
            color: #475569;
            cursor: pointer;
            transition: border-color 0.2s ease, background 0.2s ease;
        }

        .reject-reasons label:hover {
            border-color: #ff6b35;
            background: rgba(255, 107, 53, 0.08);
        }

        .reject-reasons input[type="radio"] {
            margin-top: 3px;
            accent-color: #ff6b35;
        }

        .reject-modal textarea {
            width: 100%;
            min-height: 110px;
            resize: vertical;
            border-radius: 12px;
            border: 1px solid rgba(148, 163, 184, 0.35);
            padding: 12px 14px;
            font-size: 14px;
            font-family: inherit;
            color: #0f172a;
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }

        .reject-modal textarea:focus {
            outline: none;
            border-color: #ff6b35;
            box-shadow: 0 0 0 3px rgba(255, 107, 53, 0.18);
        }

        .reject-modal footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
            padding: 14px 28px 24px;
            border-top: 1px solid rgba(148, 163, 184, 0.18);
        }

        .reject-modal footer .btn {
            border: none;
            border-radius: 10px;
            padding: 10px 18px;
            font-size: 14px;
            cursor: pointer;
            font-weight: 600;
        }

        .reject-modal footer .btn-secondary {
            background: #e2e8f0;
            color: #475569;
        }

        .reject-modal footer .btn-secondary:hover {
            background: #cbd5f5;
        }

        .reject-modal footer .btn-primary {
            background: #ff6b35;
            color: #fff;
            box-shadow: 0 8px 18px rgba(255, 107, 53, 0.25);
        }

        .reject-modal footer .btn-primary:hover {
            filter: brightness(0.95);
        }

        .view-btn {
            background: #3498db;
            color: white;
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

        .logout-btn:hover {
            background: linear-gradient(135deg, #e55a2b 0%, #ff6b35 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(255, 107, 53, 0.4);
        }

        .logout-btn:active {
            transform: translateY(0);
        }

        /* Image Modal Styles */
        .image-modal {
            display: none;
            position: fixed;
            z-index: 1400;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.9);
            overflow: auto;
        }

        .image-modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .image-modal-content {
            position: relative;
            max-width: 90%;
            max-height: 90%;
            margin: auto;
            background: white;
            border-radius: 10px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }

        .image-modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .image-modal-header h3 {
            margin: 0;
            color: #333;
        }

        .image-modal-footer {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid #eee;
        }

        .nav-arrow {
            padding: 10px 20px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }

        .nav-arrow:hover {
            background: #2980b9;
        }

        .nav-arrow:disabled {
            background: #ccc;
            cursor: not-allowed;
        }

        .image-modal-close {
            color: #aaa;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            background: none;
            border: none;
            padding: 0;
            width: 30px;
            height: 30px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .image-modal-close:hover {
            color: #000;
        }

        .image-modal-body {
            text-align: center;
        }

        .image-modal-body img {
            max-width: 100%;
            max-height: 70vh;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .image-loading {
            padding: 40px;
            color: #666;
        }

        .image-error {
            padding: 40px;
            color: #e74c3c;
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
                <p>Management Dashboard</p>
            </div>
            
            <a href="dashboard.php" class="nav-item">
                <i class="fas fa-car"></i>
                Rides Management
            </a>
            <a href="driver-requests.php" class="nav-item active">
                <i class="fas fa-user-plus"></i>
                Driver Requests
            </a>
            <a href="users-drivers.php" class="nav-item">
                <i class="fas fa-users"></i>
                Users & Drivers
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
                <h1>E-Hail Admin - Management Dashboard</h1>
            </div>

            <?php if ($errorMessage): ?>
            <div style="background: #f8d7da; color: #721c24; padding: 15px; margin: 20px; border-radius: 5px; border: 1px solid #f5c6cb;">
                <strong>Error loading driver applications:</strong> <?php echo htmlspecialchars($errorMessage); ?>
                <br><small>Check PHP error logs for more details.</small>
            </div>
            <?php endif; ?>
            
            <?php if (isset($debugInfo) && count($applications) === 0): ?>
            <div style="background: #fff3cd; color: #856404; padding: 15px; margin: 20px; border-radius: 5px; border: 1px solid #ffeaa7;">
                <strong>Debug Information:</strong><br>
                <?php 
                $pathsToShow = ['driverApplications', 'driver_applications', 'applications', 'driver_approvals'];
                foreach ($pathsToShow as $path): 
                    $countKey = $path . '_count';
                    $typeKey = $path . '_type';
                    $errorKey = $path . '_error';
                    if (isset($debugInfo[$countKey]) || isset($debugInfo[$errorKey])): 
                ?>
                    <br><strong><?php echo $path; ?>:</strong>
                    <?php if (isset($debugInfo[$errorKey])): ?>
                        <span style="color: #e74c3c;">Error: <?php echo htmlspecialchars($debugInfo[$errorKey]); ?></span>
                    <?php else: ?>
                        <?php echo $debugInfo[$countKey]; ?> items (<?php echo $debugInfo[$typeKey]; ?>)
                    <?php endif; ?>
                <?php endif; ?>
                <?php endforeach; ?>
                <br><br><small>Check PHP error logs for detailed logging from getDriverApplications() function.</small>
            </div>
            <?php endif; ?>

            <div class="summary-grid">
                <div class="summary-card total">
                    <i class="fas fa-users"></i>
                    <div>
                        <div class="summary-value"><?php echo $summary['total_applications']; ?></div>
                        <div class="summary-label">Total Applications</div>
                    </div>
                </div>
                <div class="summary-card approved">
                    <i class="fas fa-check-circle"></i>
                    <div>
                        <div class="summary-value"><?php echo $summary['approved']; ?></div>
                        <div class="summary-label">Approved</div>
                    </div>
                </div>
                <div class="summary-card pending">
                    <i class="fas fa-clock"></i>
                    <div>
                        <div class="summary-value"><?php echo $summary['pending']; ?></div>
                        <div class="summary-label">Pending</div>
                    </div>
                </div>
                <div class="summary-card rejected">
                    <i class="fas fa-times-circle"></i>
                    <div>
                        <div class="summary-value"><?php echo $summary['rejected']; ?></div>
                        <div class="summary-label">Rejected</div>
                    </div>
                </div>
            </div>

            <div class="content-area">
                <div class="content-header">
                    <h3><i class="fas fa-user"></i> Driver Applications</h3>
                    <div class="filter-group">
                        <select id="statusFilter">
                            <option value="">All Status</option>
                            <option value="pending">Pending</option>
                            <option value="waiting_for_resubmission">Waiting for Resubmission</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                        </select>
                        <span>Showing <?php echo count($applications); ?> of <?php echo count($applications); ?> applications</span>
                    </div>
                </div>

                <table class="applications-table">
                    <thead>
                        <tr>
                            <th>Driver Name</th>
                            <th>ID Number</th>
                            <th>Phone Number</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($applications)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: #666;">
                                <i class="fas fa-inbox" style="font-size: 48px; color: #ddd; margin-bottom: 15px; display: block;"></i>
                                <h3 style="margin: 10px 0; color: #999;">No Driver Applications Found</h3>
                                <p style="color: #aaa; margin: 5px 0;">There are currently no driver applications in the system.</p>
                                <p style="color: #aaa; margin: 5px 0; font-size: 12px;">
                                    <?php if (isFirebaseConfigured()): ?>
                                        Checking Firebase paths: <code>driver_approvals</code>, <code>driver_applications</code>, <code>driverApplications</code>, <code>applications</code>
                                        <?php if (isset($debugInfo)): ?>
                                            <?php 
                                            $pathsToShow = ['driver_approvals', 'driver_applications', 'driverApplications', 'applications'];
                                            foreach ($pathsToShow as $path): 
                                                $countKey = $path . '_count';
                                                $typeKey = $path . '_type';
                                                $errorKey = $path . '_error';
                                                if (isset($debugInfo[$countKey])): 
                                            ?>
                                                <br><?php echo $path; ?>: 
                                                <?php if (isset($debugInfo[$errorKey])): ?>
                                                    <span style="color: #e74c3c;">Error: <?php echo htmlspecialchars($debugInfo[$errorKey]); ?></span>
                                                <?php else: ?>
                                                    <?php echo $debugInfo[$countKey]; ?> items (<?php echo $debugInfo[$typeKey]; ?>)
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span style="color: #e74c3c;">⚠ Firebase is not properly configured. Please check firebase-config.php</span>
                                    <?php endif; ?>
                                </p>
                                <p style="margin-top: 20px;">
                                    <a href="test-driver-applications.php" target="_blank" style="color: #f39c12; text-decoration: none;">
                                        <i class="fas fa-bug"></i> Test Firebase Connection
                                    </a>
                                </p>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($applications as $app): 
                            // Get driver info first (available for all columns)
                            $driverId = $app['driverId'] ?? $app['id'] ?? '';
                            $driverName = htmlspecialchars($app['name'] ?? '');
                            $idCardNumber = $app['idCard'] ?? $app['id_card'] ?? '';
                            $documentsForRow = [];
                            $documentDecisions = is_array($app['document_decisions'] ?? null) ? $app['document_decisions'] : [];
                        ?>
                        <tr>
                            <td><strong><?php echo $driverName; ?></strong></td>
                            <td><?php echo !empty($idCardNumber) ? htmlspecialchars($idCardNumber) : '<span style="color:#999;">N/A</span>'; ?></td>
                            <td><?php echo htmlspecialchars($app['phone'] ?? ''); ?></td>
                            <td>
                                <span class="status-badge status-<?php echo strtolower($app['status'] ?? 'pending'); ?>">
                                    <?php
                                    $displayStatus = $app['status'] ?? 'pending';
                                    if ($displayStatus === 'waiting_for_resubmission') {
                                        echo 'Waiting for Resubmission';
                                    } else {
                                        echo ucfirst($displayStatus);
                                    }
                                    ?>
                                </span>
                            </td>
                            <?php ob_start(); ?>
                            <div class="detail-block">
                                <h4>Vehicle</h4>
                                <div class="detail-row"><label>Car Type:</label><span><?php $carTypeVal = trim(($app['vehicleBrand'] ?? '') . ' ' . ($app['vehicleModel'] ?? '')); echo $carTypeVal !== '' ? htmlspecialchars($carTypeVal) : 'N/A'; ?></span></div>
                                <div class="detail-row"><label>Plate Number:</label><span><?php $plateVal = $app['carPlateNumber'] ?? ($app['plateNumber'] ?? ($app['vehiclePlate'] ?? '')); echo $plateVal !== '' ? htmlspecialchars($plateVal) : 'N/A'; ?></span></div>
                                <div class="detail-row"><label>Capacity:</label><span><i class="fas fa-user"></i> <?php echo htmlspecialchars($app['passengerCapacity'] ?? '') ?: 'N/A'; ?></span></div>
                            </div>
                            <div class="detail-block">
                                <h4>Profile Picture</h4>
                                <?php
                                $profilePicturePaths = extractImagePathsFromApplication($app, 'profilePicture');
                                $profilePictureUrls = [];
                                foreach ($profilePicturePaths as $path) {
                                    $url = getImageUrl($path);
                                    if ($url) {
                                        $profilePictureUrls[] = $url;
                                    }
                                }
                                if (!empty($profilePictureUrls)): ?>
                                    <button class="action-btn view-btn" onclick="viewImageFromData(['<?php echo implode("', '", array_map('htmlspecialchars', $profilePictureUrls)); ?>'], 'Profile Picture - <?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', 'profilePicture')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                <?php else: ?>
                                    <span style="color: #999;">N/A</span>
                                <?php endif; ?>
                            </div>
                            <div class="detail-block">
                                <h4>ID Card</h4>
                                <?php

                                // Extract ALL possible image paths from the application data
                                $idCardPaths = extractImagePathsFromApplication($app, 'idCard');
                                
                                // If no paths found in data, try to construct paths based on known Storage structure
                                if (empty($idCardPaths) && !empty($driverId)) {
                                    // Get submittedAt timestamp to construct path
                                    $submittedAt = null;
                                    if (isset($app['submittedAt']['time'])) {
                                        $submittedAt = $app['submittedAt']['time'];
                                    } elseif (isset($app['submittedAt']) && is_numeric($app['submittedAt'])) {
                                        $submittedAt = $app['submittedAt'];
                                    }
                                    
                                // Construct possible paths based on Storage folder structure
                                // Format: ic_photos/{driverId}_ic_front_{timestamp}.jpg and ic_photos/{driverId}_ic_back_{timestamp}.jpg
                                if ($submittedAt) {
                                    // Try front and back with timestamp
                                    $idCardPaths[] = "ic_photos/{$driverId}_ic_front_{$submittedAt}.jpg";
                                    $idCardPaths[] = "ic_photos/{$driverId}_ic_back_{$submittedAt}.jpg";
                                    $idCardPaths[] = "ic_photos/{$driverId}_ic_front_{$submittedAt}.png";
                                    $idCardPaths[] = "ic_photos/{$driverId}_ic_back_{$submittedAt}.png";
                                    // Also try variations around timestamp (±1 day)
                                    $oneDay = 86400000;
                                    for ($i = -3; $i <= 3; $i++) {
                                        $ts = $submittedAt + ($i * $oneDay);
                                        $idCardPaths[] = "ic_photos/{$driverId}_ic_front_{$ts}.jpg";
                                        $idCardPaths[] = "ic_photos/{$driverId}_ic_back_{$ts}.jpg";
                                    }
                                }
                                // Fallback patterns (without front/back)
                                if (!empty($idCardNumber) && is_numeric($idCardNumber)) {
                                    $idCardPaths[] = "ic_photos/{$idCardNumber}.jpg";
                                    $idCardPaths[] = "ic_photos/{$idCardNumber}.png";
                                }
                                $idCardPaths[] = "ic_photos/{$driverId}_ic_front.jpg";
                                $idCardPaths[] = "ic_photos/{$driverId}_ic_back.jpg";
                                $idCardPaths[] = "ic_photos/{$driverId}_ic.jpg";
                                $idCardPaths[] = "ic_photos/{$driverId}_ic.png";
                                }
                                
                                // Convert paths to URLs
                                $idCardUrls = [];
                                foreach ($idCardPaths as $path) {
                                    $url = getImageUrl($path);
                                    if ($url) {
                                        $idCardUrls[] = $url;
                                    }
                                }
                                
                                $isIdCardRejected = isset($documentDecisions['idCard']) && $documentDecisions['idCard'] === 'rejected';
                                $idCardResubmitted = $isIdCardRejected && documentHasNewUpload($app, 'idCard');

                                $documentsForRow['idCard'] = [
                                    'label' => 'ID Card',
                                    'type' => 'idCard',
                                    'preview' => $idCardUrls[0] ?? '',
                                    'available' => !empty($idCardUrls),
                                    'decision' => $documentDecisions['idCard'] ?? 'accepted',
                                    'resubmitted' => $idCardResubmitted
                                ];
                                
                                if ($isIdCardRejected && $idCardResubmitted): ?>
                                    <div class="doc-alert doc-alert-warning">
                                        <i class="fas fa-sync-alt"></i>
                                        Driver uploaded a new ID Card. Review required.
                                    </div>
                                    <?php if (!empty($idCardUrls)): ?>
                                        <button class="action-btn view-btn" onclick="viewImageFromData(['<?php echo implode("', '", array_map('htmlspecialchars', $idCardUrls)); ?>'], 'ID Card - <?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', 'idCard')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #999;">Awaiting new upload</span>
                                    <?php endif; ?>
                                <?php elseif ($isIdCardRejected): ?>
                                    <div class="doc-alert doc-alert-danger">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Driver needs to resubmit ID Card
                                    </div>
                                <?php elseif (!empty($idCardUrls)): ?>
                                    <button class="action-btn view-btn" onclick="viewImageFromData(['<?php echo implode("', '", array_map('htmlspecialchars', $idCardUrls)); ?>'], 'ID Card - <?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', 'idCard')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                <?php else: ?>
                                    <span style="color: #999;">N/A</span>
                                    <small style="display: block; color: #999; font-size: 10px;">Path not in data</small>
                                <?php endif; ?>
                            </div>
                            <div class="detail-block">
                                <h4>Driving License</h4>
                                <?php
                                // Extract ALL possible image paths from the application data
                                $licensePaths = extractImagePathsFromApplication($app, 'license');
                                
                                // If no paths found, construct based on Storage structure
                                if (empty($licensePaths) && !empty($driverId)) {
                                    $submittedAt = null;
                                    if (isset($app['submittedAt']['time'])) {
                                        $submittedAt = $app['submittedAt']['time'];
                                    } elseif (isset($app['submittedAt']) && is_numeric($app['submittedAt'])) {
                                        $submittedAt = $app['submittedAt'];
                                    }
                                    
                                    // Format: license_photos/{driverId}_license_front_{timestamp}.jpg and license_photos/{driverId}_license_back_{timestamp}.jpg
                                    // Priority: Only generate the most likely paths first (front and back with exact timestamp)
                                    if ($submittedAt) {
                                        // PRIMARY: Try front and back with exact timestamp (most likely to exist)
                                        $licensePaths[] = "license_photos/{$driverId}_license_front_{$submittedAt}.jpg";
                                        $licensePaths[] = "license_photos/{$driverId}_license_back_{$submittedAt}.jpg";
                                        $licensePaths[] = "license_photos/{$driverId}_license_front_{$submittedAt}.png";
                                        $licensePaths[] = "license_photos/{$driverId}_license_back_{$submittedAt}.png";
                                        // SECONDARY: Try variations around timestamp (±1 day) - but only if primary fails
                                        $oneDay = 86400000;
                                        for ($i = -1; $i <= 1; $i++) { // Reduced from ±3 to ±1 day
                                            if ($i != 0) { // Skip 0 (already tried above)
                                                $ts = $submittedAt + ($i * $oneDay);
                                                $licensePaths[] = "license_photos/{$driverId}_license_front_{$ts}.jpg";
                                                $licensePaths[] = "license_photos/{$driverId}_license_back_{$ts}.jpg";
                                            }
                                        }
                                    }
                                    // FALLBACK: Only use these if front/back patterns don't work
                                    $licensePaths[] = "license_photos/{$driverId}_license_front.jpg";
                                    $licensePaths[] = "license_photos/{$driverId}_license_back.jpg";
                                    $licensePaths[] = "license_photos/{$driverId}_license.jpg";
                                }
                                
                                // Convert paths to URLs
                                $licenseUrls = [];
                                foreach ($licensePaths as $path) {
                                    $url = getImageUrl($path);
                                    if ($url) {
                                        $licenseUrls[] = $url;
                                    }
                                }
                                
                                $isLicenseRejected = isset($documentDecisions['license']) && $documentDecisions['license'] === 'rejected';
                                $licenseResubmitted = $isLicenseRejected && documentHasNewUpload($app, 'license');

                                $documentsForRow['license'] = [
                                    'label' => 'Driving License',
                                    'type' => 'license',
                                    'preview' => $licenseUrls[0] ?? '',
                                    'available' => !empty($licenseUrls),
                                    'decision' => $documentDecisions['license'] ?? 'accepted',
                                    'resubmitted' => $licenseResubmitted
                                ];

                                if ($isLicenseRejected && $licenseResubmitted): ?>
                                    <div class="doc-alert doc-alert-warning">
                                        <i class="fas fa-sync-alt"></i>
                                        Driver uploaded a new Driving License. Review required.
                                    </div>
                                    <?php if (!empty($licenseUrls)): ?>
                                        <button class="action-btn view-btn" onclick="viewImageFromData(['<?php echo implode("', '", array_map('htmlspecialchars', $licenseUrls)); ?>'], 'Driving License - <?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', 'license')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #999;">Awaiting new upload</span>
                                    <?php endif; ?>
                                <?php elseif ($isLicenseRejected): ?>
                                    <div class="doc-alert doc-alert-danger">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Driver needs to resubmit Driving License
                                    </div>
                                <?php elseif (!empty($licenseUrls)): ?>
                                    <button class="action-btn view-btn" onclick="viewImageFromData(['<?php echo implode("', '", array_map('htmlspecialchars', $licenseUrls)); ?>'], 'Driving License - <?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', 'license')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                <?php else: ?>
                                    <span style="color: #999;">N/A</span>
                                    <small style="display: block; color: #999; font-size: 10px;">Path not in data</small>
                                <?php endif; ?>
                            </div>
                            <div class="detail-block">
                                <h4>Car Grant</h4>
                                <?php
                                // Extract ALL possible image paths from the application data
                                $carGrantPaths = extractImagePathsFromApplication($app, 'carGrant');
                                
                                // If no paths found, construct based on Storage structure
                                if (empty($carGrantPaths) && !empty($driverId)) {
                                    $submittedAt = null;
                                    if (isset($app['submittedAt']['time'])) {
                                        $submittedAt = $app['submittedAt']['time'];
                                    } elseif (isset($app['submittedAt']) && is_numeric($app['submittedAt'])) {
                                        $submittedAt = $app['submittedAt'];
                                    }
                                    
                                    // Format: grant_photos/{driverId}_grant_{timestamp}.jpg
                                    if ($submittedAt) {
                                        $carGrantPaths[] = "grant_photos/{$driverId}_grant_{$submittedAt}.jpg";
                                        $carGrantPaths[] = "grant_photos/{$driverId}_grant_{$submittedAt}.png";
                                    }
                                    $carGrantPaths[] = "grant_photos/{$driverId}_grant.jpg";
                                    $carGrantPaths[] = "grant_photos/{$driverId}_grant.png";
                                }
                                
                                // Convert paths to URLs
                                $carGrantUrls = [];
                                foreach ($carGrantPaths as $path) {
                                    $url = getImageUrl($path);
                                    if ($url) {
                                        $carGrantUrls[] = $url;
                                    }
                                }
                                
                                $isGrantRejected = isset($documentDecisions['carGrant']) && $documentDecisions['carGrant'] === 'rejected';
                                $grantResubmitted = $isGrantRejected && documentHasNewUpload($app, 'carGrant');

                                $documentsForRow['carGrant'] = [
                                    'label' => 'Car Grant',
                                    'type' => 'carGrant',
                                    'preview' => $carGrantUrls[0] ?? '',
                                    'available' => !empty($carGrantUrls),
                                    'decision' => $documentDecisions['carGrant'] ?? 'accepted',
                                    'resubmitted' => $grantResubmitted
                                ];

                                if ($isGrantRejected && $grantResubmitted): ?>
                                    <div class="doc-alert doc-alert-warning">
                                        <i class="fas fa-sync-alt"></i>
                                        Driver uploaded a new Car Grant. Review required.
                                    </div>
                                    <?php if (!empty($carGrantUrls)): ?>
                                        <button class="action-btn view-btn" onclick="viewImageFromData(['<?php echo implode("', '", array_map('htmlspecialchars', $carGrantUrls)); ?>'], 'Car Grant - <?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', 'carGrant')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #999;">Awaiting new upload</span>
                                    <?php endif; ?>
                                <?php elseif ($isGrantRejected): ?>
                                    <div class="doc-alert doc-alert-danger">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Driver needs to resubmit Car Grant
                                    </div>
                                <?php elseif (!empty($carGrantUrls)): ?>
                                    <button class="action-btn view-btn" onclick="viewImageFromData(['<?php echo implode("', '", array_map('htmlspecialchars', $carGrantUrls)); ?>'], 'Car Grant - <?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', 'carGrant')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                <?php else: ?>
                                    <span style="color: #999;">N/A</span>
                                    <small style="display: block; color: #999; font-size: 10px;">Path not in data</small>
                                <?php endif; ?>
                            </div>
                            <div class="detail-block">
                                <h4>Car Image</h4>
                                <?php
                                // Extract ALL possible image paths from the application data
                                $carImagePaths = extractImagePathsFromApplication($app, 'carImage');
                                
                                // If no paths found, construct based on Storage structure (4 sides: front, back, right, left)
                                if (empty($carImagePaths) && !empty($driverId)) {
                                    $submittedAt = null;
                                    if (isset($app['submittedAt']['time'])) {
                                        $submittedAt = $app['submittedAt']['time'];
                                    } elseif (isset($app['submittedAt']) && is_numeric($app['submittedAt'])) {
                                        $submittedAt = $app['submittedAt'];
                                    }
                                    
                                    // 4 sides: front, back, right, left
                                    $sides = ['front', 'back', 'right', 'left'];
                                    
                                    // Format: car_photos/{driverId}_car_{side}_{timestamp}.jpg
                                    if ($submittedAt) {
                                        foreach ($sides as $side) {
                                            $carImagePaths[] = "car_photos/{$driverId}_car_{$side}_{$submittedAt}.jpg";
                                            $carImagePaths[] = "car_photos/{$driverId}_car_{$side}_{$submittedAt}.png";
                                            // Also try variations around the timestamp (±1 day, ±1 week)
                                            $oneDay = 86400000;
                                            for ($i = -7; $i <= 7; $i++) {
                                                $ts = $submittedAt + ($i * $oneDay);
                                                $carImagePaths[] = "car_photos/{$driverId}_car_{$side}_{$ts}.jpg";
                                                $carImagePaths[] = "car_photos/{$driverId}_car_{$side}_{$ts}.png";
                                            }
                                        }
                                    }
                                    // Fallback patterns for 4 sides
                                    foreach ($sides as $side) {
                                        $carImagePaths[] = "car_photos/{$driverId}_car_{$side}.jpg";
                                        $carImagePaths[] = "car_photos/{$driverId}_car_{$side}.png";
                                    }
                                    // Legacy single image patterns (for backward compatibility)
                                    $carImagePaths[] = "car_photos/{$driverId}_car.jpg";
                                    $carImagePaths[] = "car_photos/{$driverId}_car.png";
                                }
                                
                                // Convert paths to URLs
                                $carImageUrls = [];
                                foreach ($carImagePaths as $path) {
                                    $url = getImageUrl($path);
                                    if ($url) {
                                        $carImageUrls[] = $url;
                                    }
                                }
                                
                                $isCarImageRejected = isset($documentDecisions['carImage']) && $documentDecisions['carImage'] === 'rejected';
                                $carImageResubmitted = $isCarImageRejected && documentHasNewUpload($app, 'carImage');

                                $documentsForRow['carImage'] = [
                                    'label' => 'Car Image',
                                    'type' => 'carImage',
                                    'preview' => $carImageUrls[0] ?? '',
                                    'available' => !empty($carImageUrls),
                                    'decision' => $documentDecisions['carImage'] ?? 'accepted',
                                    'resubmitted' => $carImageResubmitted
                                ];

                                if ($isCarImageRejected && $carImageResubmitted): ?>
                                    <div class="doc-alert doc-alert-warning">
                                        <i class="fas fa-sync-alt"></i>
                                        Driver uploaded new Car Images. Review required.
                                    </div>
                                    <?php if (!empty($carImageUrls)): ?>
                                        <button class="action-btn view-btn" onclick="viewImageFromData(['<?php echo implode("', '", array_map('htmlspecialchars', $carImageUrls)); ?>'], 'Car Image - <?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', 'carImage')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                    <?php else: ?>
                                        <span style="color: #999;">Awaiting new upload</span>
                                    <?php endif; ?>
                                <?php elseif ($isCarImageRejected): ?>
                                    <div class="doc-alert doc-alert-danger">
                                        <i class="fas fa-exclamation-circle"></i>
                                        Driver needs to resubmit Car Image
                                    </div>
                                <?php elseif (!empty($carImageUrls)): ?>
                                    <button class="action-btn view-btn" onclick="viewImageFromData(['<?php echo implode("', '", array_map('htmlspecialchars', $carImageUrls)); ?>'], 'Car Image - <?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', 'carImage')">
                                        <i class="fas fa-eye"></i> View
                                    </button>
                                <?php else: ?>
                                    <span style="color: #999;">N/A</span>
                                    <small style="display: block; color: #999; font-size: 10px;">Path not in data</small>
                                <?php endif; ?>
                            </div>
                            <?php
                            $detailsHtml = ob_get_clean();
                            $documentsJson = htmlspecialchars(json_encode($documentsForRow, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
                            $appStatus = strtolower($app['status'] ?? 'pending');
                            $needsResubmission = isset($app['needs_resubmission']) && $app['needs_resubmission'] === true;
                            $hasRejectedDocuments = !empty($app['rejected_documents'] ?? []);
                            $showActionButtons = in_array($appStatus, ['pending', 'waiting_for_resubmission'], true) || (($needsResubmission || $hasRejectedDocuments) && $appStatus !== 'approved');
                            ?>
                            <td class="actions-cell">
                                <button class="action-btn view-btn" title="View Details" onclick="openDetailsModal('<?php echo htmlspecialchars($app['id']); ?>', '<?php echo $driverName; ?>', '<?php echo htmlspecialchars($idCardNumber); ?>', <?php echo $showActionButtons ? 'true' : 'false'; ?>)">
                                    <i class="fas fa-eye"></i>
                                </button>
                                <?php if ($showActionButtons): ?>
                                    <button class="action-btn approve-btn" title="Approve" onclick="approveApplication('<?php echo htmlspecialchars($app['id']); ?>', '<?php echo htmlspecialchars($app['name'] ?? ''); ?>')">
                                        <i class="fas fa-check"></i>
                                    </button>
                                    <button
                                        class="action-btn reject-btn"
                                        title="Reject"
                                        data-driver-id="<?php echo htmlspecialchars($app['id']); ?>"
                                        data-driver-name="<?php echo htmlspecialchars($app['name'] ?? ''); ?>"
                                        data-documents="<?php echo $documentsJson; ?>"
                                        onclick="openRejectModal(this)">
                                        <i class="fas fa-times"></i>
                                    </button>
                                <?php endif; ?>
                                <button class="action-btn delete-btn" title="Delete application" onclick="deleteApplication('<?php echo htmlspecialchars($app['id']); ?>', '<?php echo htmlspecialchars($app['name'] ?? ''); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <div class="app-details-payload" id="app-details-<?php echo htmlspecialchars($app['id']); ?>" style="display:none"><?php echo $detailsHtml; ?></div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModalOverlay" class="reject-overlay" aria-hidden="true">
        <form id="rejectForm" class="reject-modal" role="dialog" aria-modal="true" aria-labelledby="rejectModalTitle">
            <button type="button" class="reject-close-btn" onclick="closeRejectModal()" aria-label="Close">&times;</button>
            <header>
                <h2 id="rejectModalTitle">Reject Application</h2>
                <p id="rejectModalSubtitle"></p>
            </header>
            <div class="reject-modal-body">
                <section>
                    <h3 style="font-size:15px;color:#1e293b;margin-bottom:10px;">Verify submitted documents</h3>
                    <div id="rejectDocList" class="reject-doc-list"></div>
                </section>
                <section class="reject-reason-group">
                    <h3>Reason for rejection</h3>
                    <div class="reject-reasons">
                        <label>
                            <input type="radio" name="rejectReason" value="missing_documents">
                            Missing or unreadable document
                        </label>
                        <label>
                            <input type="radio" name="rejectReason" value="expired_license">
                            Driving license is expired or invalid
                        </label>
                        <label>
                            <input type="radio" name="rejectReason" value="vehicle_issue">
                            Vehicle documents are incomplete
                        </label>
                        <label>
                            <input type="radio" name="rejectReason" value="information_mismatch">
                            Information provided does not match documents
                        </label>
                        <label>
                            <input type="radio" name="rejectReason" value="other">
                            Other (specify in message)
                        </label>
                    </div>
                    <textarea id="rejectMessage" name="rejectMessage" placeholder="Explain the reason. This message will be shared with the driver." required></textarea>
                    <small style="display:block;margin-top:6px;color:#94a3b8;font-size:12px;">Be specific so the driver knows how to fix and resubmit.</small>
                </section>
            </div>
            <footer>
                <button type="button" class="btn btn-secondary" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Send &amp; Reject</button>
            </footer>
        </form>
    </div>

    <!-- Confirm Modal -->
    <div id="confirmModalOverlay" class="confirm-overlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirmModalTitle">
            <header>
                <h3 id="confirmModalTitle">Confirm Action</h3>
            </header>
            <p id="confirmModalMessage">Are you sure?</p>
            <div class="confirm-actions">
                <button type="button" id="confirmModalCancelBtn" class="confirm-btn cancel">Cancel</button>
                <button type="button" id="confirmModalConfirmBtn" class="confirm-btn primary">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Application Details Modal -->
    <div id="detailsModalOverlay" class="details-overlay" aria-hidden="true">
        <div class="details-modal" role="dialog" aria-modal="true" aria-labelledby="detailsModalTitle" onclick="event.stopPropagation()">
            <header>
                <div>
                    <h3 id="detailsModalTitle">Driver Application</h3>
                    <p id="detailsModalSubtitle"></p>
                </div>
                <button type="button" class="details-close-btn" onclick="closeDetailsModal()" aria-label="Close">&times;</button>
            </header>
            <div class="details-modal-body" id="detailsModalBody"></div>
            <footer id="detailsModalFooter"></footer>
        </div>
    </div>

    <!-- Delete Application Modal -->
    <div id="deleteAppModalOverlay" class="confirm-overlay" aria-hidden="true">
        <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="deleteAppModalTitle" onclick="event.stopPropagation()">
            <header>
                <div class="icon danger"><i class="fas fa-trash"></i></div>
                <h3 id="deleteAppModalTitle">Delete Application</h3>
            </header>
            <p id="deleteAppModalMessage">Are you sure?</p>
            <label class="delete-app-checkbox">
                <input type="checkbox" id="deleteAppHardDelete">
                Also delete driver profile &amp; commissions if they exist
            </label>
            <div class="confirm-actions">
                <button type="button" class="confirm-btn cancel" onclick="closeDeleteAppModal()">Cancel</button>
                <button type="button" class="confirm-btn primary" id="deleteAppConfirmBtn" onclick="confirmDeleteApplication()">Delete</button>
            </div>
        </div>
    </div>

    <div id="appToast" class="app-toast" role="status" aria-live="polite"></div>

    <!-- Image Modal -->
    <div id="imageModal" class="image-modal">
        <div class="image-modal-content">
            <div class="image-modal-header">
                <h3 id="imageModalTitle">Image</h3>
                <button class="image-modal-close" onclick="closeImageModal()">&times;</button>
            </div>
            <div class="image-modal-body" id="imageModalBody">
                <div class="image-loading">Loading image...</div>
            </div>
            <div class="image-modal-footer" id="imageModalFooter" style="display: none;">
                <button class="nav-arrow nav-arrow-left" id="prevImageBtn" onclick="navigateImage('prev')" title="Previous (Back)">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
                <span id="imageCounter" style="margin: 0 15px; color: #666; font-size: 14px;"></span>
                <button class="nav-arrow nav-arrow-right" id="nextImageBtn" onclick="navigateImage('next')" title="Next (Front)">
                    Front <i class="fas fa-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
        // Debug: Log application count on page load
        console.log('Driver Applications Page Loaded');
        console.log('Applications count from PHP: <?php echo count($applications); ?>');
        console.log('Summary:', <?php echo json_encode($summary); ?>);
        <?php if ($errorMessage): ?>
        console.error('Error loading applications:', <?php echo json_encode($errorMessage); ?>);
        <?php endif; ?>
        
        const rejectModalOverlay = document.getElementById('rejectModalOverlay');
        const rejectForm = document.getElementById('rejectForm');
        const rejectDocList = document.getElementById('rejectDocList');
        const rejectModalTitle = document.getElementById('rejectModalTitle');
        const rejectModalSubtitle = document.getElementById('rejectModalSubtitle');
        const rejectMessageInput = document.getElementById('rejectMessage');
        const rejectSubmitBtn = rejectForm ? rejectForm.querySelector('.btn.btn-primary') : null;
        const confirmModalOverlay = document.getElementById('confirmModalOverlay');
        const confirmModalTitle = document.getElementById('confirmModalTitle');
        const confirmModalMessage = document.getElementById('confirmModalMessage');
        const confirmModalConfirmBtn = document.getElementById('confirmModalConfirmBtn');
        const confirmModalCancelBtn = document.getElementById('confirmModalCancelBtn');
        const appToast = document.getElementById('appToast');
        const detailsModalOverlay = document.getElementById('detailsModalOverlay');
        const detailsModalTitle = document.getElementById('detailsModalTitle');
        const detailsModalSubtitle = document.getElementById('detailsModalSubtitle');
        const detailsModalBody = document.getElementById('detailsModalBody');
        const detailsModalFooter = document.getElementById('detailsModalFooter');
        const deleteAppModalOverlay = document.getElementById('deleteAppModalOverlay');
        const deleteAppModalMessage = document.getElementById('deleteAppModalMessage');
        const deleteAppHardDelete = document.getElementById('deleteAppHardDelete');
        const deleteAppConfirmBtn = document.getElementById('deleteAppConfirmBtn');
        let deleteAppContext = { driverId: '', driverName: '' };
        const rejectReasonTemplates = {
            missing_documents: 'Hi, we could not verify your documents because one or more images are missing or unreadable. Please upload clear photos and resubmit your application.',
            expired_license: 'Hi, we noticed that your driving license appears to be expired or invalid. Please update the license image and reapply.',
            vehicle_issue: 'Hi, we could not verify your vehicle documents. Kindly upload a clear photo of the latest car grant and resubmit your application.',
            information_mismatch: 'Hi, the information provided in your application does not match the documents submitted. Please review and correct the details before reapplying.',
            other: ''
        };
        let rejectContext = { driverId: '', driverName: '', documents: {} };
        let confirmModalAction = null;
        let toastTimer = null;

        function showToast(message, type = 'success') {
            if (!appToast) return;
            appToast.textContent = message;
            appToast.classList.remove('success', 'error');
            appToast.classList.add(type === 'error' ? 'error' : 'success');
            appToast.classList.add('visible');
            clearTimeout(toastTimer);
            toastTimer = setTimeout(() => {
                appToast.classList.remove('visible');
            }, 3500);
        }

        function openConfirmModal({ title, message, confirmText = 'Confirm', cancelText = 'Cancel', onConfirm }) {
            if (!confirmModalOverlay) return;
            confirmModalTitle.textContent = title || 'Confirm Action';
            confirmModalMessage.textContent = message || 'Are you sure?';
            confirmModalConfirmBtn.textContent = confirmText;
            confirmModalCancelBtn.textContent = cancelText;
            confirmModalOverlay.classList.add('active');
            confirmModalOverlay.setAttribute('aria-hidden', 'false');
            confirmModalAction = typeof onConfirm === 'function' ? onConfirm : null;
        }

        function closeConfirmModal() {
            if (!confirmModalOverlay) return;
            confirmModalOverlay.classList.remove('active');
            confirmModalOverlay.setAttribute('aria-hidden', 'true');
            confirmModalAction = null;
        }

        if (confirmModalConfirmBtn) {
            confirmModalConfirmBtn.addEventListener('click', () => {
                const action = confirmModalAction;
                closeConfirmModal();
                if (typeof action === 'function') {
                    action();
                }
            });
        }

        if (confirmModalCancelBtn) {
            confirmModalCancelBtn.addEventListener('click', closeConfirmModal);
        }

        if (confirmModalOverlay) {
            confirmModalOverlay.addEventListener('click', (event) => {
                if (event.target === confirmModalOverlay) {
                    closeConfirmModal();
                }
            });
        }

        function escapeHtml(str) {
            if (str === undefined || str === null) return '';
            return String(str).replace(/[&<>"']/g, s => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[s] || s));
        }

        function openRejectModal(buttonEl) {
            if (!buttonEl) return;
            const driverId = buttonEl.dataset.driverId || '';
            const driverName = buttonEl.dataset.driverName || 'this driver';
            let documents = {};
            try {
                const documentsStr = buttonEl.dataset.documents || '{}';
                documents = JSON.parse(documentsStr);
                console.log('Documents parsed from button:', documents);
            } catch (err) {
                console.warn('Failed to parse documents data', err);
                console.warn('Raw documents string:', buttonEl.dataset.documents);
                documents = {};
            }

            rejectContext = { driverId, driverName, documents };
            console.log('Reject context set:', rejectContext);

            if (rejectForm) {
                rejectForm.reset();
            }
            if (rejectMessageInput) {
                rejectMessageInput.value = '';
                delete rejectMessageInput.dataset.autoFilled;
            }

            if (rejectModalTitle) {
                rejectModalTitle.textContent = `Reject ${driverName}`;
            }
            if (rejectModalSubtitle) {
                rejectModalSubtitle.textContent = `Let ${driverName} know why the application is being rejected.`;
            }

            renderRejectDocuments(documents);
            preselectDefaultReason();

            if (rejectModalOverlay) {
                rejectModalOverlay.classList.add('active');
                rejectModalOverlay.setAttribute('aria-hidden', 'false');
            }
        }

        function renderRejectDocuments(documents) {
            if (!rejectDocList) return;
            const entries = Object.entries(documents || {});
            if (!entries.length) {
                rejectDocList.innerHTML = '<p style="color:#94a3b8;font-size:13px;">No documents were submitted with this application.</p>';
                return;
            }
            rejectDocList.innerHTML = entries.map(([key, doc]) => {
                const preview = doc && doc.preview ? `<img src="${escapeHtml(doc.preview)}" alt="${escapeHtml(doc.label || key)}">` : '<div class="reject-doc-placeholder"><i class="fas fa-image"></i></div>';
                const label = escapeHtml(doc && doc.label ? doc.label : key);
                const decision = (doc && doc.decision) ? String(doc.decision) : 'accepted';
                const acceptedChecked = decision === 'rejected' ? '' : 'checked';
                const rejectedChecked = decision === 'rejected' ? 'checked' : '';
                const resubmitted = doc && doc.resubmitted ? true : false;
                const resubmittedTag = resubmitted ? '<span class="doc-resubmitted-pill"><i class="fas fa-sync-alt"></i> New upload</span>' : '';
                return `
                    <div class="reject-doc-item">
                        <div class="reject-doc-preview">
                            ${preview}
                            <div class="reject-doc-label">${label}${resubmittedTag}</div>
                        </div>
                        <div class="reject-doc-actions">
                            <label>
                                <input type="radio" name="docStatus-${key}" value="accepted" ${acceptedChecked}>
                                Accepted
                            </label>
                            <label>
                                <input type="radio" name="docStatus-${key}" value="rejected" ${rejectedChecked}>
                                Rejected
                            </label>
                        </div>
                    </div>
                `;
            }).join('');
        }

        function closeRejectModal() {
            if (rejectModalOverlay) {
                rejectModalOverlay.classList.remove('active');
                rejectModalOverlay.setAttribute('aria-hidden', 'true');
            }
            if (rejectSubmitBtn) {
                rejectSubmitBtn.disabled = false;
                rejectSubmitBtn.textContent = 'Send & Reject';
            }
        }

        function preselectDefaultReason() {
            if (!rejectForm) return;
            const reasonInputs = rejectForm.querySelectorAll('input[name="rejectReason"]');
            if (reasonInputs.length) {
                reasonInputs[0].checked = true;
                autoFillRejectMessage(reasonInputs[0].value);
            }
        }

        function autoFillRejectMessage(reason) {
            if (!rejectMessageInput) return;
            const template = rejectReasonTemplates[reason] || '';
            if (!template) {
                if (!rejectMessageInput.dataset.autoFilled) {
                    rejectMessageInput.value = '';
                }
                return;
            }
            if (!rejectMessageInput.value.trim() || rejectMessageInput.dataset.autoFilled === 'true') {
                rejectMessageInput.value = template;
                rejectMessageInput.dataset.autoFilled = 'true';
            }
        }

        function submitRejectForm(event) {
            event.preventDefault();
            if (!rejectContext.driverId) {
                alert('Unable to find driver ID for this application.');
                return;
            }
            if (!rejectForm) return;
            const formData = new FormData(rejectForm);
            const reason = formData.get('rejectReason') || '';
            const message = (formData.get('rejectMessage') || '').trim();

            if (!message) {
                alert('Please provide a message for the driver.');
                return;
            }

            // Collect document decisions from ALL radio buttons in the form
            const documentDecisions = {};
            
            // First, ensure we have entries for ALL documents from rejectContext
            if (rejectContext.documents) {
                Object.keys(rejectContext.documents).forEach(key => {
                    // Initialize with default 'accepted', will be overwritten if found
                    documentDecisions[key] = 'accepted';
                });
            }
            
            // Get all radio button groups (all inputs with name starting with "docStatus-")
            const allDocRadios = rejectForm.querySelectorAll('input[name^="docStatus-"]');
            
            // Process all checked radio buttons
            allDocRadios.forEach(radio => {
                if (radio.checked) {
                    const name = radio.name;
                    const key = name.replace('docStatus-', '');
                    documentDecisions[key] = radio.value;
                }
            });
            
            // Log detailed information for debugging
            console.log('Document decisions collected:', documentDecisions);
            console.log('Available documents:', Object.keys(rejectContext.documents || {}));
            console.log('All radio buttons found:', allDocRadios.length);
            console.log('Rejected documents count:', Object.values(documentDecisions).filter(v => v === 'rejected').length);

            if (rejectSubmitBtn) {
                rejectSubmitBtn.disabled = true;
                rejectSubmitBtn.textContent = 'Submitting...';
            }

            console.log('Submitting rejection with:', {
                driverId: rejectContext.driverId,
                rejectionReason: reason,
                rejectionMessage: message,
                documentDecisions: documentDecisions
            });
            
            rejectApplication(rejectContext.driverId, rejectContext.driverName, {
                rejectionReason: reason,
                rejectionMessage: message,
                documentDecisions: documentDecisions
            });
        }

        if (rejectForm) {
            rejectForm.addEventListener('submit', submitRejectForm);
            rejectForm.addEventListener('change', function (event) {
                if (event.target && event.target.name === 'rejectReason') {
                    autoFillRejectMessage(event.target.value);
                }
            });
        }
        if (rejectMessageInput) {
            rejectMessageInput.addEventListener('input', () => {
                delete rejectMessageInput.dataset.autoFilled;
            });
        }

        if (rejectModalOverlay) {
            rejectModalOverlay.addEventListener('click', function (event) {
                if (event.target === rejectModalOverlay) {
                    closeRejectModal();
                }
            });
        }

        // Firebase Storage base URL
        const FIREBASE_STORAGE_URL = 'https://firebasestorage.googleapis.com/v0/b/drive-ab344.firebasestorage.app/o/';
        
        // Function to encode path for Firebase Storage URL
        function encodePath(path) {
            return path.split('/').map(segment => encodeURIComponent(segment)).join('/');
        }
        
        // Function to build Firebase Storage URL from path
        function buildStorageUrl(path) {
            return FIREBASE_STORAGE_URL + encodePath(path) + '?alt=media';
        }
        
        // Global variables for image navigation
        let currentImageUrls = [];
        let currentImageIndex = 0;
        let currentImageType = '';
        let currentImageTitle = '';
        let currentIdNumber = '';

        // Function to organize URLs by front/back
        function organizeUrlsBySide(urls, type) {
            const frontUrls = [];
            const backUrls = [];
            const otherUrls = [];
            
            // STRICT: Only match URLs with exact pattern _front_ or _back_ (underscores on both sides)
            // This matches actual file names like: {driverId}_ic_front_{timestamp}.jpg
            urls.forEach(url => {
                const urlLower = url.toLowerCase();
                if (urlLower.includes('_front_')) {
                    frontUrls.push(url);
                } else if (urlLower.includes('_back_')) {
                    backUrls.push(url);
                } else {
                    otherUrls.push(url);
                }
            });
            
            // For ID Card and License: ONLY show front and back (max 2 images)
            // Take the FIRST front URL and FIRST back URL only
            const finalUrls = [];
            if (frontUrls.length > 0) {
                finalUrls.push(frontUrls[0]); // Only first front
            }
            if (backUrls.length > 0) {
                finalUrls.push(backUrls[0]); // Only first back
            }

            // Fail-safe: if nothing matched the strict _front_/_back_ naming
            // (e.g. Cloudinary-hosted URLs that don't use that convention),
            // fall back to showing whatever URLs we do have instead of
            // silently discarding them -- unlabeled beats invisible.
            if (finalUrls.length === 0 && otherUrls.length > 0) {
                return {
                    front: [],
                    back: [],
                    other: otherUrls.slice(0, 2),
                    all: otherUrls.slice(0, 2)
                };
            }

            // Return ONLY these 2 URLs (or fewer if only one exists)
            // Ignore all fallback patterns and other URLs
            return {
                front: finalUrls.filter(url => url.toLowerCase().includes('_front_')),
                back: finalUrls.filter(url => url.toLowerCase().includes('_back_')),
                other: [],
                all: finalUrls // MAX 2 URLs: front (index 0) and/or back (index 1)
            };
        }

        // Function to view image from data - with front/back navigation support
        function viewImageFromData(urls, title, idNumber, type) {
            const modal = document.getElementById('imageModal');
            const modalTitle = document.getElementById('imageModalTitle');
            const modalBody = document.getElementById('imageModalBody');
            const modalFooter = document.getElementById('imageModalFooter');
            
            currentImageType = type || '';
            currentIdNumber = idNumber || '';
            
            // Update title with ID number if available
            let displayTitle = title || 'Image';
            if (currentIdNumber) {
                displayTitle += ' (ID: ' + currentIdNumber + ')';
            }
            modalTitle.textContent = displayTitle;
            
            modalBody.innerHTML = '<div class="image-loading">Loading image...</div>';
            modal.classList.add('active');
            
            if (!urls || urls.length === 0) {
                modalBody.innerHTML = `
                    <div class="image-error">
                        <p style="color: red; font-weight: bold;">No image URL found</p>
                        <p>The image path is not stored in the database.</p>
                        <p style="margin-top: 10px; font-size: 12px; color: #666;">Please ensure the mobile app is saving image paths to Firebase.</p>
                    </div>
                `;
                modalFooter.style.display = 'none';
                return;
            }
            
            // Organize URLs by front/back for ID Card and License
            if (type === 'idCard' || type === 'license') {
                organizedUrls = organizeUrlsBySide(urls, type);
                // For ID Card and License, ONLY show front and back (max 2 images)
                // organizedUrls.all should already be limited to max 2 (first front + first back)
                currentImageUrls = organizedUrls.all;
                currentImageIndex = 0;
                
                // Show navigation only if we have exactly 2 images
                if (currentImageUrls.length === 2) {
                    modalFooter.style.display = 'flex';
                } else {
                    modalFooter.style.display = 'none';
                }
            } else if (type === 'carImage') {
                // For car images, organize by side: front, back, right, left
                const organizedUrls = {
                    front: [],
                    back: [],
                    right: [],
                    left: [],
                    other: []
                };
                
                urls.forEach(url => {
                    const urlLower = url.toLowerCase();
                    if (urlLower.includes('_front_') || urlLower.includes('_car_front') || urlLower.includes('/front')) {
                        organizedUrls.front.push(url);
                    } else if (urlLower.includes('_back_') || urlLower.includes('_car_back') || urlLower.includes('/back')) {
                        organizedUrls.back.push(url);
                    } else if (urlLower.includes('_right_') || urlLower.includes('_car_right') || urlLower.includes('/right')) {
                        organizedUrls.right.push(url);
                    } else if (urlLower.includes('_left_') || urlLower.includes('_car_left') || urlLower.includes('/left')) {
                        organizedUrls.left.push(url);
                    } else {
                        organizedUrls.other.push(url);
                    }
                });
                
                // Build ordered array: front, back, right, left, then others
                currentImageUrls = [
                    ...(organizedUrls.front.length > 0 ? [organizedUrls.front[0]] : []),
                    ...(organizedUrls.back.length > 0 ? [organizedUrls.back[0]] : []),
                    ...(organizedUrls.right.length > 0 ? [organizedUrls.right[0]] : []),
                    ...(organizedUrls.left.length > 0 ? [organizedUrls.left[0]] : []),
                    ...organizedUrls.other.slice(0, 6) // Limit others to 6
                ];
                
                currentImageIndex = 0;
                if (currentImageUrls.length > 1) {
                    modalFooter.style.display = 'flex';
                } else {
                    modalFooter.style.display = 'none';
                }
            } else {
                // For other types (car grant), just use all URLs (limit to reasonable number)
                currentImageUrls = urls.slice(0, 10);
                currentImageIndex = 0;
                if (currentImageUrls.length > 1) {
                    modalFooter.style.display = 'flex';
                } else {
                    modalFooter.style.display = 'none';
                }
            }
            
            // Load the current image
            loadCurrentImage();
        }


        // Function to load the current image
        function loadCurrentImage() {
            const modalBody = document.getElementById('imageModalBody');
            const modalFooter = document.getElementById('imageModalFooter');
            
            if (currentImageIndex >= currentImageUrls.length || currentImageIndex < 0) {
                modalBody.innerHTML = `
                    <div class="image-error">
                        <p style="color: red; font-weight: bold;">No more images available</p>
                    </div>
                `;
                return;
            }
            
            modalBody.innerHTML = '<div class="image-loading">Loading image...</div>';
            
            const url = currentImageUrls[currentImageIndex];
            const img = new Image();
            
            img.onload = function() {
                // Determine side label based on URL and type
                const urlLower = url.toLowerCase();
                let sideLabel = '';
                
                if (currentImageType === 'idCard' || currentImageType === 'license') {
                    // ID Card and License: Front/Back
                    if (urlLower.includes('_front_') || urlLower.includes('_ic_front') || urlLower.includes('_license_front')) {
                        sideLabel = ' (Front)';
                    } else if (urlLower.includes('_back_') || urlLower.includes('_ic_back') || urlLower.includes('_license_back')) {
                        sideLabel = ' (Back)';
                    }
                } else if (currentImageType === 'carImage') {
                    // Car Image: Front/Back/Right/Left
                    if (urlLower.includes('_front_') || urlLower.includes('_car_front') || urlLower.includes('/front')) {
                        sideLabel = ' (Front)';
                    } else if (urlLower.includes('_back_') || urlLower.includes('_car_back') || urlLower.includes('/back')) {
                        sideLabel = ' (Back)';
                    } else if (urlLower.includes('_right_') || urlLower.includes('_car_right') || urlLower.includes('/right')) {
                        sideLabel = ' (Right)';
                    } else if (urlLower.includes('_left_') || urlLower.includes('_car_left') || urlLower.includes('/left')) {
                        sideLabel = ' (Left)';
                    }
                }
                
                modalBody.innerHTML = `
                    <div style="text-align: center;">
                        <img src="${url}" alt="${currentImageTitle || 'Image'}${sideLabel}" style="max-width: 100%; max-height: 70vh; height: auto; border-radius: 5px; box-shadow: 0 2px 10px rgba(0,0,0,0.2);" />
                        ${sideLabel ? '<p style="margin-top: 10px; color: #666; font-size: 14px; font-weight: 500;">' + sideLabel.replace(/[()]/g, '') + '</p>' : ''}
                    </div>
                `;
                updateNavigationButtons();
            };
            
            img.onerror = function() {
                // For ID Card and License, if this URL fails and we have others, try next
                // But don't auto-advance if we're already at a valid image
                if (currentImageType === 'idCard' || currentImageType === 'license') {
                    // Remove this failed URL from the list
                    currentImageUrls = currentImageUrls.filter(u => u !== url);
                    if (currentImageIndex >= currentImageUrls.length) {
                        currentImageIndex = Math.max(0, currentImageUrls.length - 1);
                    }
                    if (currentImageUrls.length > 0) {
                        loadCurrentImage();
                    } else {
                        modalBody.innerHTML = `
                            <div class="image-error">
                                <p style="color: red; font-weight: bold;">Failed to load image</p>
                                <p>The image file could not be found in Firebase Storage.</p>
                            </div>
                        `;
                        modalFooter.style.display = 'none';
                    }
                } else {
                    // For other types, try next URL
                    if (currentImageIndex < currentImageUrls.length - 1) {
                        currentImageIndex++;
                        setTimeout(loadCurrentImage, 200);
                    } else {
                        modalBody.innerHTML = `
                            <div class="image-error">
                                <p style="color: red; font-weight: bold;">Failed to load image</p>
                                <p>Tried ${currentImageUrls.length} URL(s) but none worked.</p>
                            </div>
                        `;
                    }
                }
            };
            
            img.src = url;
        }

        // Function to update navigation buttons
        function updateNavigationButtons() {
            const prevBtn = document.getElementById('prevImageBtn');
            const nextBtn = document.getElementById('nextImageBtn');
            const counter = document.getElementById('imageCounter');
            
            if (!prevBtn || !nextBtn || !counter) return;
            
            // Determine which side we're on
            const currentUrl = currentImageUrls[currentImageIndex] || '';
            const urlLower = currentUrl.toLowerCase();
            
            let prevLabel = 'Previous';
            let nextLabel = 'Next';
            
            if (currentImageType === 'idCard' || currentImageType === 'license') {
                // ID Card and License: Front/Back navigation
                const isBack = urlLower.includes('_back_') || urlLower.includes('_ic_back') || urlLower.includes('_license_back');
                const isFront = urlLower.includes('_front_') || urlLower.includes('_ic_front') || urlLower.includes('_license_front');
                
                if (isBack) {
                    prevLabel = 'Back';
                    nextLabel = 'Front';
                } else if (isFront) {
                    prevLabel = 'Back';
                    nextLabel = 'Front';
                }
            } else if (currentImageType === 'carImage') {
                // Car Image: Front/Back/Right/Left navigation
                const isFront = urlLower.includes('_front_') || urlLower.includes('_car_front') || urlLower.includes('/front');
                const isBack = urlLower.includes('_back_') || urlLower.includes('_car_back') || urlLower.includes('/back');
                const isRight = urlLower.includes('_right_') || urlLower.includes('_car_right') || urlLower.includes('/right');
                const isLeft = urlLower.includes('_left_') || urlLower.includes('_car_left') || urlLower.includes('/left');
                
                // Determine next/prev based on order: Front -> Back -> Right -> Left
                if (isFront) {
                    prevLabel = 'Front';
                    nextLabel = 'Back';
                } else if (isBack) {
                    prevLabel = 'Front';
                    nextLabel = 'Right';
                } else if (isRight) {
                    prevLabel = 'Back';
                    nextLabel = 'Left';
                } else if (isLeft) {
                    prevLabel = 'Right';
                    nextLabel = 'Left';
                }
            }
            
            // Update button labels
            prevBtn.innerHTML = `<i class="fas fa-arrow-left"></i> ${prevLabel}`;
            nextBtn.innerHTML = `${nextLabel} <i class="fas fa-arrow-right"></i>`;
            
            // Enable/disable buttons
            prevBtn.disabled = currentImageIndex <= 0;
            nextBtn.disabled = currentImageIndex >= currentImageUrls.length - 1;
            
            // Update counter
            counter.textContent = `${currentImageIndex + 1} / ${currentImageUrls.length}`;
        }

        // Function to navigate between images
        function navigateImage(direction) {
            if (direction === 'prev' && currentImageIndex > 0) {
                currentImageIndex--;
                loadCurrentImage();
            } else if (direction === 'next' && currentImageIndex < currentImageUrls.length - 1) {
                currentImageIndex++;
                loadCurrentImage();
            }
        }
        
        function viewImage(imageUrl, title, rawPath) {
            const modal = document.getElementById('imageModal');
            const modalTitle = document.getElementById('imageModalTitle');
            const modalBody = document.getElementById('imageModalBody');
            
            modalTitle.textContent = title || 'Image';
            modalBody.innerHTML = '<div class="image-loading">Loading image...</div>';
            modal.classList.add('active');
            
            const img = new Image();
            img.onload = function() {
                modalBody.innerHTML = '<img src="' + imageUrl + '" alt="' + (title || 'Image') + '" style="max-width: 100%; height: auto;" />';
            };
            img.onerror = function() {
                let errorMsg = '<div class="image-error">';
                errorMsg += '<p><strong>Failed to load image</strong></p>';
                errorMsg += '<p>Generated URL: <code style="font-size: 11px; word-break: break-all;">' + imageUrl + '</code></p>';
                if (rawPath) {
                    errorMsg += '<p>Original Path: <code style="font-size: 11px; word-break: break-all;">' + rawPath + '</code></p>';
                }
                errorMsg += '<p style="margin-top: 10px; color: #666;">Possible issues:</p>';
                errorMsg += '<ul style="text-align: left; color: #666; font-size: 12px;">';
                errorMsg += '<li>File does not exist in Firebase Storage</li>';
                errorMsg += '<li>Storage rules are blocking access</li>';
                errorMsg += '<li>Path format is incorrect</li>';
                errorMsg += '</ul>';
                errorMsg += '</div>';
                modalBody.innerHTML = errorMsg;
            };
            img.src = imageUrl;
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            modal.classList.remove('active');
        }

        // Close modal when clicking outside the image
        window.onclick = function(event) {
            const modal = document.getElementById('imageModal');
            if (event.target === modal) {
                closeImageModal();
            }
        }

        // Close modal with Escape key
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                closeImageModal();
                closeDetailsModal();
                closeDeleteAppModal();
            }
        });
        // Add status filter functionality
        document.getElementById('statusFilter').addEventListener('change', function() {
            const status = this.value.toLowerCase();
            const rows = document.querySelectorAll('.applications-table tbody tr');
            
            rows.forEach(row => {
                const statusCell = row.querySelector('.status-badge');
                if (!statusCell) {
                    row.style.display = 'none';
                    return;
                }
                
                const statusText = statusCell.textContent.toLowerCase();
                let shouldShow = false;
                
                if (status === '') {
                    shouldShow = true;
                } else if (status === 'waiting_for_resubmission') {
                    // Match "waiting for resubmission" text
                    shouldShow = statusText.includes('waiting') || statusText.includes('resubmission');
                } else {
                    shouldShow = statusText.includes(status);
                }
                
                row.style.display = shouldShow ? '' : 'none';
            });
        });

        function approveApplication(driverId, driverName) {
            openConfirmModal({
                title: 'Approve Application',
                message: `Are you sure you want to approve ${driverName}'s application?`,
                confirmText: 'Approve',
                cancelText: 'Cancel',
                onConfirm: () => performApproval(driverId, driverName)
            });
        }

        function performApproval(driverId, driverName) {
            fetch('update-driver-status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    driverId: driverId,
                    status: 'approved'
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
            })
            .then(data => {
                console.log('Update response:', data);
                if (data.success) {
                    showToast(`${driverName}'s application has been approved.`);
                    setTimeout(() => {
                        window.location.href = window.location.href.split('?')[0] + '?_t=' + Date.now();
                    }, 600);
                } else {
                    showToast('Error updating application: ' + (data.message || 'Unknown error'), 'error');
                }
            })
            .catch(error => {
                console.error('Error:', error);
                showToast('Error updating application: ' + error.message, 'error');
            });
        }

        function rejectApplication(driverId, driverName, extra = {}) {
            if (!driverId) {
                alert('Driver ID is missing.');
                if (rejectSubmitBtn) {
                    rejectSubmitBtn.disabled = false;
                    rejectSubmitBtn.textContent = 'Send & Reject';
                }
                return;
            }
            const payload = Object.assign({
                driverId: driverId,
                status: 'rejected'
            }, extra || {});

                fetch('update-driver-status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                body: JSON.stringify(payload)
                    })
            .then(response => {
                if (!response.ok) {
                    throw new Error('HTTP error! status: ' + response.status);
                }
                return response.json();
                })
                .then(data => {
                console.log('Update response:', data);
                console.log('Status set to:', data.status);
                console.log('Has rejected documents:', data.hasRejectedDocuments);
                    if (data.success) {
                    closeRejectModal();
                    // Reload page without alert popup
                    setTimeout(() => {
                        window.location.href = window.location.href.split('?')[0] + '?_t=' + Date.now();
                    }, 300);
                    } else {
                    if (rejectSubmitBtn) {
                        rejectSubmitBtn.disabled = false;
                        rejectSubmitBtn.textContent = 'Send & Reject';
                    }
                    alert('Error updating application: ' + (data.message || 'Unknown error'));
                    }
                })
                .catch(error => {
                if (rejectSubmitBtn) {
                    rejectSubmitBtn.disabled = false;
                    rejectSubmitBtn.textContent = 'Send & Reject';
                }
                console.error('Error:', error);
                alert('Error updating application: ' + error.message);
            });
        }

        function openDetailsModal(appId, driverName, idNumber, isPending) {
            if (!detailsModalOverlay) return;
            const payload = document.getElementById('app-details-' + appId);
            detailsModalTitle.textContent = driverName || 'Driver Application';
            detailsModalSubtitle.textContent = idNumber ? ('ID: ' + idNumber) : '';
            detailsModalBody.innerHTML = payload ? payload.innerHTML : '<p>No details available.</p>';

            const safeName = driverName.replace(/'/g, "\\'");
            let footerHtml = '';
            footerHtml += `<button type="button" class="action-btn delete-btn" onclick="closeDetailsModal(); deleteApplication('${appId}', '${safeName}');"><i class="fas fa-trash"></i> Delete</button>`;
            if (isPending) {
                footerHtml += `<button type="button" class="action-btn reject-btn" onclick="closeDetailsModal(); document.querySelector('.reject-btn[data-driver-id=\\'${appId}\\']').click();"><i class="fas fa-times"></i> Reject</button>`;
                footerHtml += `<button type="button" class="action-btn approve-btn" onclick="closeDetailsModal(); approveApplication('${appId}', '${safeName}');"><i class="fas fa-check"></i> Approve</button>`;
            }
            detailsModalFooter.innerHTML = footerHtml;

            detailsModalOverlay.classList.add('active');
            detailsModalOverlay.setAttribute('aria-hidden', 'false');
        }

        function closeDetailsModal() {
            if (!detailsModalOverlay) return;
            detailsModalOverlay.classList.remove('active');
            detailsModalOverlay.setAttribute('aria-hidden', 'true');
        }

        if (detailsModalOverlay) {
            detailsModalOverlay.addEventListener('click', (event) => {
                if (event.target === detailsModalOverlay) {
                    closeDetailsModal();
                }
            });
        }

        function deleteApplication(driverId, driverName) {
            if (!deleteAppModalOverlay) return;
            deleteAppContext = { driverId, driverName };
            deleteAppModalMessage.innerHTML = `Are you sure you want to delete the application for <strong>${driverName}</strong>?<br>This action cannot be undone.`;
            deleteAppHardDelete.checked = false;
            deleteAppModalOverlay.classList.add('active');
            deleteAppModalOverlay.setAttribute('aria-hidden', 'false');
        }

        function closeDeleteAppModal() {
            if (!deleteAppModalOverlay) return;
            deleteAppModalOverlay.classList.remove('active');
            deleteAppModalOverlay.setAttribute('aria-hidden', 'true');
            deleteAppContext = { driverId: '', driverName: '' };
        }

        function confirmDeleteApplication() {
            if (!deleteAppContext.driverId) return;
            const alsoDeleteDriver = deleteAppHardDelete.checked;
            deleteAppConfirmBtn.disabled = true;
            deleteAppConfirmBtn.textContent = 'Deleting...';
            fetch('delete-driver-application.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ driverId: deleteAppContext.driverId, hardDelete: alsoDeleteDriver ? 1 : 0 })
            })
            .then(r => r.json())
            .then(res => {
                deleteAppConfirmBtn.disabled = false;
                deleteAppConfirmBtn.textContent = 'Delete';
                if (res.success) {
                    closeDeleteAppModal();
                    showToast('Application deleted successfully');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast('Delete failed: ' + (res.message || 'Unknown error'), 'error');
                }
            })
            .catch(err => {
                deleteAppConfirmBtn.disabled = false;
                deleteAppConfirmBtn.textContent = 'Delete';
                showToast('Delete failed: ' + err, 'error');
            });
        }

        if (deleteAppModalOverlay) {
            deleteAppModalOverlay.addEventListener('click', (event) => {
                if (event.target === deleteAppModalOverlay) {
                    closeDeleteAppModal();
                }
            });
        }
    </script>
</body>
</html>

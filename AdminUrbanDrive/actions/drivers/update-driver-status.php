<?php
session_start();
require_once 'firebase-config.php';

// Check if user is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['driverId']) || !isset($input['status'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

$driverId = $input['driverId']; // This is actually the Firebase key (application ID)
$status = $input['status'];

// Validate status
if (!in_array($status, ['approved', 'rejected', 'pending', 'waiting_for_resubmission'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit;
}

try {
    // First, get the current application data to preserve all fields
    // driverId here is actually the Firebase key (application ID)
    $currentApp = getFirebaseData('driver_approvals/' . $driverId);
    
    if (empty($currentApp) || !is_array($currentApp)) {
        http_response_code(404);
        echo json_encode([
            'success' => false, 
            'message' => 'Driver application not found',
            'path' => 'driver_approvals/' . $driverId
        ]);
        exit;
    }
    
    // Get the actual driverId from the application data (if it exists)
    $actualDriverId = $currentApp['driverId'] ?? $currentApp['id'] ?? $driverId;
    
    // Initialize variables
    $rejectedDocuments = [];
    $documentDecisions = [];
    
    // Handle document-level rejections
    error_log("Checking for documentDecisions in input: " . json_encode(isset($input['documentDecisions'])));
    if (isset($input['documentDecisions'])) {
        error_log("documentDecisions value: " . json_encode($input['documentDecisions']));
        error_log("Is array: " . (is_array($input['documentDecisions']) ? 'yes' : 'no'));
        error_log("Is empty: " . (empty($input['documentDecisions']) ? 'yes' : 'no'));
    }
    
    if (isset($input['documentDecisions']) && is_array($input['documentDecisions']) && !empty($input['documentDecisions'])) {
        $documentDecisions = $input['documentDecisions'];
        
        // Check which documents were rejected
        foreach ($documentDecisions as $docType => $decision) {
            // Normalize decision value (handle case sensitivity)
            $normalizedDecision = strtolower(trim($decision));
            if ($normalizedDecision === 'rejected') {
                $rejectedDocuments[] = $docType;
            }
        }
        
        // Log for debugging
        error_log("Document decisions received: " . json_encode($documentDecisions));
        error_log("Rejected documents: " . json_encode($rejectedDocuments));
        
        // Store document decisions (new format)
        $currentApp['document_decisions'] = $documentDecisions;
        
        // Also set boolean flags for driver app compatibility (old format)
        // Map document types to boolean flag names
        $flagMapping = [
            'idCard' => 'idCardRejected',
            'license' => 'licenseRejected',
            'carGrant' => 'carGrantRejected',
            'carImage' => 'carImageRejected'
        ];
        
        // Set boolean flags based on document decisions
        // Set to true if rejected, false if accepted (for driver app compatibility)
        foreach ($flagMapping as $docType => $flagName) {
            if (isset($documentDecisions[$docType])) {
                $currentApp[$flagName] = ($documentDecisions[$docType] === 'rejected');
            } else {
                // If document decision not set, keep existing value or default to false
                if (!isset($currentApp[$flagName])) {
                    $currentApp[$flagName] = false;
                }
            }
        }
        
        // If ANY documents are rejected, set status to waiting_for_resubmission
        // This allows driver to see which documents need to be resubmitted
        if (!empty($rejectedDocuments)) {
            // Always set to waiting_for_resubmission when documents are rejected
            // Driver can resubmit the rejected documents
            $status = 'waiting_for_resubmission';
            $currentApp['needs_resubmission'] = true;
            $currentApp['rejected_documents'] = $rejectedDocuments;
            
            // Store rejection info for driver to see
            if (isset($input['rejectionReason'])) {
                $currentApp['rejection_reason'] = $input['rejectionReason'];
            }
            if (isset($input['rejectionMessage'])) {
                $currentApp['rejection_message'] = $input['rejectionMessage'];
            }
            
            // Store timestamp in both formats for app compatibility
            $rejectedTimestamp = time() * 1000; // milliseconds
            $currentApp['rejected_at'] = date('Y-m-d H:i:s');
            $currentApp['rejectedAt'] = ['time' => $rejectedTimestamp];
            
            // Log what we're storing
            error_log("Storing rejected_documents array: " . json_encode($rejectedDocuments));
            error_log("Total rejected documents count: " . count($rejectedDocuments));
        }
    }
    
    // If status is 'rejected' but we have document decisions with rejected docs, change to waiting_for_resubmission
    // This handles the case where documentDecisions might be processed but status wasn't updated
    if ($status === 'rejected' && !empty($rejectedDocuments)) {
        $status = 'waiting_for_resubmission';
        $currentApp['needs_resubmission'] = true;
        if (!isset($currentApp['rejected_documents'])) {
            $currentApp['rejected_documents'] = $rejectedDocuments;
        }
        // Ensure timestamp is set if not already set
        if (!isset($currentApp['rejectedAt'])) {
            $rejectedTimestamp = time() * 1000;
            $currentApp['rejected_at'] = date('Y-m-d H:i:s');
            $currentApp['rejectedAt'] = ['time' => $rejectedTimestamp];
        }
    }
    
    // Update the status while preserving all other fields
    // Final check: if we have rejected documents, ensure status is waiting_for_resubmission
    if (!empty($rejectedDocuments) && $status !== 'waiting_for_resubmission') {
        $status = 'waiting_for_resubmission';
        $currentApp['needs_resubmission'] = true;
        if (!isset($currentApp['rejected_documents'])) {
            $currentApp['rejected_documents'] = $rejectedDocuments;
        }
    }
    
    $currentApp['status'] = $status;
    $currentApp['registrationStatus'] = $status;
    $currentApp['updated_at'] = date('Y-m-d H:i:s');
    $currentApp['updatedAt'] = time() * 1000;
    
    // Log final status and rejected documents for debugging
    error_log("Final status being saved: " . $status);
    error_log("Final rejected_documents being saved: " . json_encode($currentApp['rejected_documents'] ?? []));
    error_log("Final document_decisions being saved: " . json_encode($currentApp['document_decisions'] ?? []));
    
    // Handle full rejection (when status is explicitly set to rejected without document decisions)
    if ($status === 'rejected' && empty($rejectedDocuments)) {
        if (isset($input['rejectionReason'])) {
            $currentApp['rejection_reason'] = $input['rejectionReason'];
        }
        if (isset($input['rejectionMessage'])) {
            $currentApp['rejection_message'] = $input['rejectionMessage'];
        }
        $currentApp['rejected_at'] = date('Y-m-d H:i:s');
    }
    
    // Ensure status is explicitly set in the array
    $currentApp['status'] = $status;
    
    // Use PUT to ensure complete update
    $success = setFirebaseData('driver_approvals/' . $driverId, $currentApp);
    
    if ($success) {
        // Wait a moment for Firebase to process the write
        usleep(500000); // 0.5 second delay
        
        // Verify the update was successful
        $updatedApp = getFirebaseData('driver_approvals/' . $driverId);
        $actualStatus = $updatedApp['status'] ?? '';
        
        // If status doesn't match, try one more time with explicit status update
        if (strtolower($actualStatus) !== strtolower($status)) {
            error_log("Status update mismatch. Expected: {$status}, Got: {$actualStatus}. Retrying...");
            // Force update status field directly using updateFirebaseData
            updateFirebaseData('driver_approvals/' . $driverId, ['status' => $status]);
            usleep(300000); // 0.3 second delay
            $updatedApp = getFirebaseData('driver_approvals/' . $driverId);
            $actualStatus = $updatedApp['status'] ?? $status;
        }
        
        // If approved, also copy the application into drivers/{driverId}
        if ($status === 'approved') {
            // Use the updated app data
            $app = $updatedApp ?: $currentApp;
            if (is_array($app)) {
                // Normalize fields for drivers collection
                $driverRecord = [
                    'name' => $app['name'] ?? ($app['driverName'] ?? ''),
                    'phone' => $app['phone'] ?? '',
                    'email' => $app['email'] ?? '',
                    'vehicleBrand' => $app['vehicleBrand'] ?? '',
                    'vehicleModel' => $app['vehicleModel'] ?? '',
                    'vehicleYear' => $app['vehicleYear'] ?? '',
                    'carPlateNumber' => $app['carPlateNumber'] ?? ($app['plateNumber'] ?? ($app['vehiclePlate'] ?? '')),
                    'plateNumber' => $app['plateNumber'] ?? ($app['carPlateNumber'] ?? ($app['vehiclePlate'] ?? '')),
                    'vehiclePlate' => $app['vehiclePlate'] ?? ($app['carPlateNumber'] ?? ($app['plateNumber'] ?? '')),
                    'passengerCapacity' => $app['passengerCapacity'] ?? '',
                    'userType' => 'driver',
                    'status' => 'active',
                    'registrationStatus' => 'approved',
                    'approved_at' => date('Y-m-d H:i:s')
                ];
                // Use actualDriverId if available, otherwise use the Firebase key
                updateFirebaseData('drivers/' . $actualDriverId, $driverRecord);

                // Ensure initial record exists in driver_commissions for this driver
                $initialCommission = [
                    'driver_info' => [
                        'driver_id' => $actualDriverId,
                        'driver_name' => $driverRecord['name'],
                        'last_updated' => time() * 1000
                    ],
                    'commission_summary' => [
                        'total_earnings' => 0.0,
                        'total_commission' => 0.0,
                        'paid_commission' => 0.0,
                        'unpaid_commission' => 0.0,
                        'last_updated' => time() * 1000,
                        'commission_rate' => 20
                    ],
                    'recent_jobs' => [],
                    'payment_history' => []
                ];
                // Only create if not exists
                $existing = getFirebaseData('driver_commissions/' . $actualDriverId);
                if (!$existing) {
                updateFirebaseData('driver_commissions/' . $actualDriverId, $initialCommission);
            }
        } else {
            updateFirebaseData('drivers/' . $actualDriverId, [
                'registrationStatus' => $status,
                'status' => $status,
                'updatedAt' => time() * 1000
            ]);
        }
        }
        // Final verification of what's being saved
        $finalRejectedDocs = $currentApp['rejected_documents'] ?? $rejectedDocuments;
        error_log("Response - rejectedDocuments count: " . count($finalRejectedDocs));
        error_log("Response - rejectedDocuments array: " . json_encode($finalRejectedDocs));
        
        echo json_encode([
            'success' => true, 
            'message' => 'Status updated successfully',
            'driverId' => $driverId,
            'status' => $status,
            'actualStatus' => $actualStatus ?? $status,
            'rejectedDocuments' => $finalRejectedDocs,
            'rejectedDocumentsCount' => count($finalRejectedDocs),
            'hasRejectedDocuments' => !empty($finalRejectedDocs),
            'documentDecisions' => $currentApp['document_decisions'] ?? []
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Failed to update status in Firebase',
            'driverId' => $driverId
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}
?>

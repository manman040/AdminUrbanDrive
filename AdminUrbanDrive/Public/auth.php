<?php
session_start();
require_once 'firebase-config.php';

// Simple authentication - in production, you should use Firebase Auth or proper authentication
$admin_credentials = [
    'admin' => 'admin123',
    'admin@PerdaRide.com' => 'admin123'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Try Firebase authentication first
    if (authenticateWithFirebase($username, $password)) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        $_SESSION['auth_method'] = 'firebase';
        header('Location: dashboard.php');
        exit;
    } else {
        // Fallback to local authentication
        if (isset($admin_credentials[$username]) && $admin_credentials[$username] === $password) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            $_SESSION['auth_method'] = 'local';
            header('Location: dashboard.php');
            exit;
        } else {
            header('Location: index.php?error=invalid_credentials');
            exit;
        }
    }
} else {
    header('Location: index.php');
    exit;
}
?>

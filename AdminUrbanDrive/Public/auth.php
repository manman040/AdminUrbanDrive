<?php
session_start();
require_once 'firebase-config.php';

// Admin login: Firebase Authentication email/password, and the account must be
// listed under /admins/{uid} in the Realtime Database. There is no fallback
// password any more -- see Docs/ADMIN_AUTH_SETUP.md to add an admin.

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$email = trim($_POST['username'] ?? ($_POST['email'] ?? ''));
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    header('Location: index.php?error=invalid_credentials');
    exit;
}

$result = authenticateAdmin($email, $password);

if ($result === 'ok') {
    header('Location: dashboard.php');
    exit;
}

header('Location: index.php?error=' . rawurlencode($result));
exit;

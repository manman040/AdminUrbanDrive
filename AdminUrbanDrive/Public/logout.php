<?php
session_start();
require_once 'firebase-config.php';

// Drop the Firebase tokens and the session cookie, not just the server-side data.
firebaseClearSession();

header('Location: index.php');
exit;

<?php
// Temporary sanity check: confirms this host allows outbound HTTPS calls
// to Firebase. Delete this file once you've confirmed it works.

header('Content-Type: text/plain');

$url = 'https://drive-ab344-default-rtdb.asia-southeast1.firebasedatabase.app/.json?shallow=true';

echo "PHP version: " . PHP_VERSION . "\n";
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF') . "\n";
echo "curl extension: " . (function_exists('curl_init') ? 'available' : 'MISSING') . "\n\n";

echo "--- Trying file_get_contents() ---\n";
$context = stream_context_create(['http' => ['timeout' => 10]]);
$result = @file_get_contents($url, false, $context);
if ($result !== false) {
    echo "SUCCESS: " . substr($result, 0, 200) . "\n";
} else {
    $err = error_get_last();
    echo "FAILED: " . ($err['message'] ?? 'unknown error') . "\n";
}

echo "\n--- Trying cURL ---\n";
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    if ($result !== false) {
        echo "SUCCESS: " . substr($result, 0, 200) . "\n";
    } else {
        echo "FAILED: " . curl_error($ch) . "\n";
    }
    curl_close($ch);
} else {
    echo "cURL not available on this host.\n";
}

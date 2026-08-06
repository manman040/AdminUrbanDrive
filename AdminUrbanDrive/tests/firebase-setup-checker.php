<?php
// Firebase Setup Checker
// This file helps you verify your Firebase configuration

require_once 'firebase-config.php';

echo "<!DOCTYPE html>";
echo "<html><head><title>Firebase Setup Checker</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }";
echo ".container { max-width: 800px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }";
echo ".success { color: #28a745; background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; }";
echo ".error { color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 5px; margin: 10px 0; }";
echo ".warning { color: #856404; background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0; }";
echo ".info { color: #0c5460; background: #d1ecf1; padding: 10px; border-radius: 5px; margin: 10px 0; }";
echo "h1 { color: #333; border-bottom: 2px solid #ff6b35; padding-bottom: 10px; }";
echo "h2 { color: #555; margin-top: 30px; }";
echo "code { background: #f8f9fa; padding: 2px 5px; border-radius: 3px; font-family: monospace; }";
echo ".config-item { background: #f8f9fa; padding: 15px; margin: 10px 0; border-radius: 5px; border-left: 4px solid #ff6b35; }";
echo "</style></head><body>";

echo "<div class='container'>";
echo "<h1>🔥 Firebase Setup Checker</h1>";

// Check 1: Firebase Configuration
echo "<h2>1. Firebase Configuration Status</h2>";

if (isFirebaseConfigured()) {
    echo "<div class='success'>✅ Firebase is properly configured!</div>";
} else {
    echo "<div class='error'>❌ Firebase is not configured. You need to update the configuration values.</div>";
    
    echo "<div class='config-item'>";
    echo "<h3>Required Configuration Updates:</h3>";
    echo "<p>You need to update these values in <code>firebase-config.php</code>:</p>";
    echo "<ul>";
    echo "<li><strong>apiKey:</strong> Get from your <code>google-services.json</code> file</li>";
    echo "<li><strong>authDomain:</strong> Get from Firebase Console > Project Settings</li>";
    echo "<li><strong>projectId:</strong> Get from your <code>google-services.json</code> file</li>";
    echo "<li><strong>storageBucket:</strong> Get from your <code>google-services.json</code> file</li>";
    echo "<li><strong>messagingSenderId:</strong> Get from your <code>google-services.json</code> file</li>";
    echo "<li><strong>appId:</strong> Get from Firebase Console > Project Settings</li>";
    echo "<li><strong>firebase_database_url:</strong> Get from Firebase Console > Realtime Database</li>";
    echo "</ul>";
    echo "</div>";
}

// Check 2: Current Configuration Values
echo "<h2>2. Current Configuration Values</h2>";
echo "<div class='config-item'>";
echo "<p><strong>API Key:</strong> " . (strpos($firebase_config['apiKey'], 'AIzaSyBqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXq') !== false ? '❌ Placeholder' : '✅ Configured') . "</p>";
echo "<p><strong>Auth Domain:</strong> " . (strpos($firebase_config['authDomain'], 'your-project-id') !== false ? '❌ Placeholder' : '✅ Configured') . "</p>";
echo "<p><strong>Project ID:</strong> " . (strpos($firebase_config['projectId'], 'your-project-id') !== false ? '❌ Placeholder' : '✅ Configured') . "</p>";
echo "<p><strong>Storage Bucket:</strong> " . (strpos($firebase_config['storageBucket'], 'your-project-id') !== false ? '❌ Placeholder' : '✅ Configured') . "</p>";
echo "<p><strong>Messaging Sender ID:</strong> " . (strpos($firebase_config['messagingSenderId'], '123456789') !== false ? '❌ Placeholder' : '✅ Configured') . "</p>";
echo "<p><strong>App ID:</strong> " . (strpos($firebase_config['appId'], '1:123456789:web:abcdef123456') !== false ? '❌ Placeholder' : '✅ Configured') . "</p>";
echo "<p><strong>Database URL:</strong> " . (strpos($firebase_database_url, 'your-project-id') !== false ? '❌ Placeholder' : '✅ Configured') . "</p>";
echo "</div>";

// Check 3: How to Get Configuration Values
echo "<h2>3. How to Get Your Firebase Configuration</h2>";
echo "<div class='info'>";
echo "<h3>From google-services.json (Android):</h3>";
echo "<ol>";
echo "<li>Open your <code>google-services.json</code> file</li>";
echo "<li>Look for these values:";
echo "<ul>";
echo "<li><code>project_info.project_number</code> → messagingSenderId</li>";
echo "<li><code>project_info.project_id</code> → projectId</li>";
echo "<li><code>project_info.storage_bucket</code> → storageBucket</li>";
echo "<li><code>client[0].api_key[0].current_key</code> → apiKey</li>";
echo "</ul>";
echo "</li>";
echo "</ol>";

echo "<h3>From Firebase Console:</h3>";
echo "<ol>";
echo "<li>Go to <a href='https://console.firebase.google.com/' target='_blank'>Firebase Console</a></li>";
echo "<li>Select your project</li>";
echo "<li>Click the gear icon (⚙️) next to 'Project Overview'</li>";
echo "<li>Go to 'Project settings'</li>";
echo "<li>Scroll down to 'Your apps' section</li>";
echo "<li>Copy the configuration values</li>";
echo "</ol>";

echo "<h3>Database URL:</h3>";
echo "<ol>";
echo "<li>Go to Firebase Console > Realtime Database</li>";
echo "<li>Copy the database URL (looks like: <code>https://your-project-id-default-rtdb.firebaseio.com/</code>)</li>";
echo "</ol>";
echo "</div>";

// Check 4: Example Configuration
echo "<h2>4. Example Configuration</h2>";
echo "<div class='config-item'>";
echo "<p>Here's how your <code>firebase-config.php</code> should look after configuration:</p>";
echo "<pre><code>";
echo "// Firebase project configuration\n";
echo "\$firebase_config = [\n";
echo "    'apiKey' => 'AIzaSyC2XqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXq', // Your actual API key\n";
echo "    'authDomain' => 'your-actual-project.firebaseapp.com', // Your actual auth domain\n";
echo "    'projectId' => 'your-actual-project', // Your actual project ID\n";
echo "    'storageBucket' => 'your-actual-project.appspot.com', // Your actual storage bucket\n";
echo "    'messagingSenderId' => '123456789012', // Your actual messaging sender ID\n";
echo "    'appId' => '1:123456789012:web:abcdef1234567890' // Your actual app ID\n";
echo "];\n";
echo "\n";
echo "// Firebase Realtime Database URL\n";
echo "\$firebase_database_url = 'https://your-actual-project-default-rtdb.firebaseio.com/';\n";
echo "</code></pre>";
echo "</div>";

// Check 5: Next Steps
echo "<h2>5. Next Steps</h2>";
echo "<div class='warning'>";
echo "<ol>";
echo "<li><strong>Update Configuration:</strong> Replace the placeholder values in <code>firebase-config.php</code></li>";
echo "<li><strong>Test Connection:</strong> Visit <a href='test-firebase.php'>test-firebase.php</a> to verify the connection</li>";
echo "<li><strong>Enable Services:</strong> In Firebase Console, enable:";
echo "<ul>";
echo "<li>Authentication (Email/Password)</li>";
echo "<li>Realtime Database</li>";
echo "<li>Storage (optional)</li>";
echo "</ul>";
echo "</li>";
echo "<li><strong>Set Database Rules:</strong> Configure security rules in Firebase Console</li>";
echo "</ol>";
echo "</div>";

echo "<div class='info'>";
echo "<h3>Need Help?</h3>";
echo "<ul>";
echo "<li>Check the <a href='FIREBASE_INTEGRATION_GUIDE.md'>Firebase Integration Guide</a></li>";
echo "<li>Run <a href='test-firebase.php'>test-firebase.php</a> after configuration</li>";
echo "<li>Check PHP error logs for detailed error messages</li>";
echo "</ul>";
echo "</div>";

echo "<p><a href='dashboard.php'>← Back to Dashboard</a> | <a href='test-firebase.php'>Test Firebase Connection</a></p>";
echo "</div>";
echo "</body></html>";
?>

# Firebase Integration Guide for Urban Drive Admin Panel

## Overview
This guide explains how to connect your PHP web application with Firebase, enabling real-time data synchronization between your Android app and web admin panel.

## Step 1: Get Your Firebase Configuration

### From google-services.json (Android)
Your `google-services.json` file contains the Firebase project configuration. Extract these values:

```json
{
  "project_info": {
    "project_number": "123456789",
    "project_id": "your-project-id",
    "storage_bucket": "your-project-id.appspot.com"
  },
  "client": [
    {
      "client_info": {
        "mobilesdk_app_id": "1:123456789:android:abcdef123456",
        "android_client_info": {
          "package_name": "com.yourpackage.urbandrive"
        }
      },
      "api_key": [
        {
          "current_key": "AIzaSyBqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXq"
        }
      ]
    }
  ]
}
```

### From Firebase Console
1. Go to [Firebase Console](https://console.firebase.google.com/)
2. Select your project
3. Go to Project Settings (gear icon)
4. Copy the configuration values

## Step 2: Update firebase-config.php

Replace the placeholder values in `firebase-config.php`:

```php
$firebase_config = [
    'apiKey' => 'AIzaSyBqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXq', // From google-services.json
    'authDomain' => 'your-project-id.firebaseapp.com', // From Firebase Console
    'projectId' => 'your-project-id', // From google-services.json
    'storageBucket' => 'your-project-id.appspot.com', // From google-services.json
    'messagingSenderId' => '123456789', // From google-services.json
    'appId' => '1:123456789:web:abcdef123456' // From Firebase Console
];

$firebase_database_url = 'https://your-project-id-default-rtdb.firebaseio.com/';
```

## Step 3: Enable Firebase Services

### 1. Firebase Authentication
- Go to Firebase Console > Authentication
- Enable Email/Password authentication
- Add admin users for web access

### 2. Firebase Realtime Database
- Go to Firebase Console > Realtime Database
- Create database in test mode (for development)
- Set up security rules:

```json
{
  "rules": {
    "rides": {
      ".read": "auth != null",
      ".write": "auth != null"
    },
    "driver_applications": {
      ".read": "auth != null",
      ".write": "auth != null"
    },
    "users": {
      ".read": "auth != null",
      ".write": "auth != null"
    }
  }
}
```

### 3. Firebase Storage (Optional)
- Go to Firebase Console > Storage
- Enable for document uploads

## Step 4: Database Structure

Your Firebase Realtime Database should have this structure:

```json
{
  "rides": {
    "ride_123456": {
      "id": "ride_123456",
      "date_time": "2025-01-10 10:00:00",
      "customer": "Devi",
      "driver": "Mamu",
      "destination": "KL to Genting",
      "fare": "RM 11.00",
      "status": "completed",
      "payment": "cash",
      "rating": 4.5,
      "created_at": "2025-01-10 10:00:00"
    }
  },
  "driver_applications": {
    "app_123456": {
      "id": "app_123456",
      "driver_name": "John Smith",
      "car_type": "Toyota Camry",
      "plate_number": "ABC-1234",
      "phone_number": "+1234567890",
      "capacity": 4,
      "status": "pending",
      "documents": {
        "id_card": "url_to_document",
        "driving_license": "url_to_document",
        "car_grant": "url_to_document",
        "car_image": "url_to_document"
      },
      "created_at": "2025-01-10 10:00:00"
    }
  },
  "users": {
    "user_123456": {
      "id": "user_123456",
      "name": "Admin User",
      "email": "admin@urbandrive.com",
      "role": "admin",
      "created_at": "2025-01-10 10:00:00"
    }
  }
}
```

## Step 5: Update Your PHP Files

### Update auth.php to use Firebase Authentication:

```php
<?php
session_start();
require_once 'firebase-config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // Try Firebase authentication first
    if (authenticateWithFirebase($username, $password)) {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_username'] = $username;
        header('Location: dashboard.php');
        exit;
    } else {
        // Fallback to local authentication
        $admin_credentials = [
            'admin' => 'admin123',
            'admin@urbandrive.com' => 'admin123'
        ];
        
        if (isset($admin_credentials[$username]) && $admin_credentials[$username] === $password) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
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
```

### Update dashboard.php to use Firebase data:

```php
<?php
session_start();
require_once 'firebase-config.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

// Get real data from Firebase
$metrics = getMetrics();
$rides = getRidesData();

// Calculate daily rides for charts
$daily_rides = [];
$days = ['MON', 'TUE', 'WED', 'THU', 'FRI', 'SAT', 'SUN'];
foreach ($days as $day) {
    $daily_rides[$day] = 0;
}

// Process rides data for charts
foreach ($rides as $ride) {
    if (isset($ride['date_time'])) {
        $day = strtoupper(date('D', strtotime($ride['date_time'])));
        if (isset($daily_rides[$day])) {
            $daily_rides[$day]++;
        }
    }
}
?>
```

## Step 6: Testing the Integration

### 1. Test Firebase Connection
Create a test file `test-firebase.php`:

```php
<?php
require_once 'firebase-config.php';

echo "<h2>Testing Firebase Connection</h2>";

// Test reading data
$rides = getRidesData();
echo "<p>Rides count: " . count($rides) . "</p>";

// Test writing data
$testData = [
    'test_field' => 'test_value',
    'timestamp' => date('Y-m-d H:i:s')
];

if (setFirebaseData('test/' . uniqid(), $testData)) {
    echo "<p style='color: green;'>✅ Firebase write test successful</p>";
} else {
    echo "<p style='color: red;'>❌ Firebase write test failed</p>";
}
?>
```

### 2. Test Authentication
Try logging in with Firebase credentials.

## Step 7: Android App Integration

Your Android app should write data to the same Firebase database:

```java
// Example: Creating a new ride from Android app
DatabaseReference ridesRef = FirebaseDatabase.getInstance().getReference("rides");
String rideId = ridesRef.push().getKey();

Ride ride = new Ride();
ride.setId(rideId);
ride.setCustomer("Devi");
ride.setDriver("Mamu");
ride.setDestination("KL to Genting");
ride.setFare("RM 11.00");
ride.setStatus("pending");
ride.setDateTime(new Date());

ridesRef.child(rideId).setValue(ride);
```

## Step 8: Real-time Updates

To enable real-time updates in your web admin panel, add JavaScript:

```javascript
// Add this to your dashboard.php
<script>
// Listen for real-time updates
function listenForRidesUpdates() {
    const ridesRef = firebase.database().ref('rides');
    ridesRef.on('value', (snapshot) => {
        const rides = snapshot.val();
        updateRidesTable(rides);
        updateMetrics();
    });
}

function updateRidesTable(rides) {
    // Update your rides table with new data
    const tableBody = document.getElementById('ridesTableBody');
    tableBody.innerHTML = '';
    
    Object.keys(rides).forEach(rideId => {
        const ride = rides[rideId];
        // Add row to table
    });
}
</script>
```

## Common Issues and Solutions

### 1. CORS Issues
If you encounter CORS issues, add headers to your PHP files:

```php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');
```

### 2. Authentication Errors
- Ensure your API key is correct
- Check if Firebase Authentication is enabled
- Verify email/password credentials

### 3. Database Permission Errors
- Check Firebase Realtime Database rules
- Ensure your database URL is correct
- Verify project ID matches

## Security Best Practices

1. **Use Environment Variables**: Store Firebase config in environment variables
2. **Implement Proper Authentication**: Use Firebase Auth instead of session-based auth
3. **Validate Data**: Always validate data before writing to Firebase
4. **Use HTTPS**: Always use HTTPS in production
5. **Set Up Security Rules**: Configure proper Firebase security rules

## Next Steps

1. Replace mock data in all PHP files with Firebase calls
2. Implement real-time updates using Firebase listeners
3. Add error handling for Firebase operations
4. Set up proper logging and monitoring
5. Implement user management with Firebase Auth

This integration will allow your Android app and web admin panel to share the same Firebase database, enabling real-time data synchronization across your entire Urban Drive application.


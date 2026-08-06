# 🚀 Quick Start: Connect Your App & Website in 5 Minutes

## Step 1: Get Your Firebase Values (2 minutes)

### From your `google-services.json`:
1. Open your `google-services.json` file
2. Find these values:
   - `project_id` (in `project_info`)
   - `storage_bucket` (in `project_info`) 
   - `current_key` (in `api_key`)
   - `mobilesdk_app_id` (in `client_info`)

### From Firebase Console:
1. Go to [Firebase Console](https://console.firebase.google.com/)
2. Select your project
3. Click the gear icon → Project Settings
4. Copy the **Web API Key**
5. Go to Realtime Database → Copy the **Database URL**

## Step 2: Update Your PHP Config (1 minute)

Open `firebase-config.php` and replace these lines:

```php
// Replace this:
'apiKey' => 'AIzaSyBqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXqXq',

// With this (from google-services.json):
'apiKey' => 'AIzaSyYourActualApiKeyHere',

// Replace this:
'projectId' => 'your-project-id',

// With this (from google-services.json):
'projectId' => 'your-actual-project-id',

// Replace this:
$firebase_database_url = 'https://your-project-id-default-rtdb.firebaseio.com/';

// With this (from Firebase Console):
$firebase_database_url = 'https://your-actual-project-id-default-rtdb.firebaseio.com/';
```

## Step 3: Test Your Connection (1 minute)

1. Visit: `http://localhost/AdminUrbanDrive/firebase-setup-checker.php`
2. You should see: ✅ Firebase is properly configured!
3. Visit: `http://localhost/AdminUrbanDrive/test-firebase.php`
4. You should see: ✅ Success messages

## Step 4: Create Test Data (1 minute)

### Option A: From your Android app
Add this code to create a test ride:

```java
DatabaseReference ridesRef = FirebaseDatabase.getInstance().getReference("rides");
String rideId = ridesRef.push().getKey();

Map<String, Object> testRide = new HashMap<>();
testRide.put("user_id", "test_user");
testRide.put("driver_id", "test_driver");
testRide.put("pickup", "Kuala Lumpur");
testRide.put("destination", "Petaling Jaya");
testRide.put("fare", "RM 25.00");
testRide.put("status", "completed");
testRide.put("created_at", "2024-01-15 10:30:00");

ridesRef.child(rideId).setValue(testRide);
```

### Option B: From your PHP website
Visit: `http://localhost/AdminUrbanDrive/test-firebase.php` and click "Create Test Data"

## Step 5: Verify It Works

1. Check your PHP admin panel: `http://localhost/AdminUrbanDrive/dashboard.php`
2. You should see your test data!
3. Check your Android app - it should also show the same data

## 🎉 You're Done!

Your Android app and PHP website are now connected through Firebase!

**What happens next:**
- Data created in your app → appears in your admin panel
- Data updated in your admin panel → appears in your app
- Everything syncs in real-time! 🚀

## 🔧 If Something Doesn't Work

1. **Check Firebase Console** → Realtime Database → Rules
2. Set rules to: `{ "rules": { ".read": true, ".write": true } }`
3. **Check your database URL** - make sure it ends with `.firebaseio.com/`
4. **Check your API key** - make sure it's the Web API Key, not the Android one

Need help? Check the full guide: `FIREBASE_CONNECTION_GUIDE.md`

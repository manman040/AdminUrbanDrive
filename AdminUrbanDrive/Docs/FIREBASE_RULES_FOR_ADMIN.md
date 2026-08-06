# Firebase Rules for Admin Panel

## Problem
Your Firebase Database Rules require authentication (`auth != null`), but the PHP admin panel is accessing Firebase using REST API without authentication tokens, resulting in "Permission denied" errors.

## Solution Options

### Option 1: Add Admin Read Access (Quick Fix - Less Secure)

Add these rules to allow read access for admin paths. Update your rules in Firebase Console:

```json
{
  "rules": {
    "users": {
      "$uid": {
        ".read": "auth != null && auth.uid === $uid",
        ".write": "auth != null && auth.uid === $uid"
      },
      // Add this for admin panel read access
      ".read": true
    },
    "drivers": {
      "$driverId": {
        ".read": "auth != null && auth.uid === $driverId",
        "currentLocation": {
          ".write": "auth != null && auth.uid === $driverId"
        },
        ".write": "auth != null && auth.uid === $driverId"
      },
      // Add this for admin panel read access
      ".read": true
    },
    "bookings": {
      // Add this for admin panel read access
      ".read": true,
      "$bookingId": {
        ".read": "auth != null",
        "driverLocation": {
          ".write": "auth != null"
        },
        "status": {
          ".write": "auth != null && (root.child('bookings').child($bookingId).child('userId').val() === auth.uid || !root.child('bookings').child($bookingId).child('driverId').exists() || root.child('bookings').child($bookingId).child('driverId').val() === null || root.child('bookings').child($bookingId).child('driverId').val() === '' || root.child('bookings').child($bookingId).child('driverId').val() === auth.uid)"
        },
        "driverId": {
          ".write": "auth != null && (newData.val() === auth.uid && (!root.child('bookings').child($bookingId).child('driverId').exists() || root.child('bookings').child($bookingId).child('driverId').val() === null || root.child('bookings').child($bookingId).child('driverId').val() === '')) || root.child('bookings').child($bookingId).child('driverId').val() === auth.uid || root.child('bookings').child($bookingId).child('userId').val() === auth.uid"
        },
        "rated": {
          ".write": "auth != null && root.child('bookings').child($bookingId).child('userId').val() === auth.uid"
        },
        "ratingTimestamp": {
          ".write": "auth != null && root.child('bookings').child($bookingId).child('userId').val() === auth.uid"
        },
        ".write": "auth != null && ((newData.exists() && newData.child('userId').val() === auth.uid) || (newData.exists() && newData.child('driverId').val() === auth.uid) || (data.exists() && (data.child('userId').val() === auth.uid || data.child('driverId').val() === auth.uid || !data.child('driverId').exists() || data.child('driverId').val() === null || data.child('driverId').val() === '')))"
      }
    },
    "ratings": {
      // Add this for admin panel read access
      ".read": true,
      "$driverId": {
        ".read": "auth != null",
        "$ratingId": {
          ".read": "auth != null",
          ".write": "auth != null"
        }
      }
    },
    "driver_approvals": {
      // Add this for admin panel read access
      ".read": true,
      "$driverId": {
        ".read": "auth != null && auth.uid === $driverId",
        ".write": "auth != null && auth.uid === $driverId"
      }
    },
    "driver_commissions": {
      // Add this for admin panel read access
      ".read": true,
      "$driverId": {
        ".read": "auth != null && auth.uid === $driverId",
        ".write": "auth != null && auth.uid === $driverId || true"
      }
    },
    "commission_payments": {
      ".read": "auth != null",
      ".write": true
    },
    "bill_mappings": {
      ".read": "auth != null",
      ".write": true
    },
    "commissions": {
      ".read": "auth != null",
      ".write": true
    },
    "commission_payment": {
      ".read": "auth != null",
      ".write": true,
      "$driverId": {
        ".read": "auth != null && auth.uid === $driverId",
        ".write": true
      }
    },
    "driver_settings": {
      ".read": "auth != null",
      ".write": true,
      "$driverId": {
        ".read": "auth != null && auth.uid === $driverId || true",
        ".write": true
      }
    },
    ".read": false,
    ".write": false
  }
}
```

### Option 2: Use Firebase Admin SDK (More Secure - Recommended for Production)

For production, you should use Firebase Admin SDK with service account credentials. This requires:
1. Creating a service account in Firebase
2. Downloading the service account key
3. Installing Firebase Admin SDK for PHP
4. Updating the PHP code to use Admin SDK

### Option 3: IP Whitelist (Alternative)

You can restrict admin access by IP address in the rules, but this is less flexible.

## Steps to Update Rules

1. Go to [Firebase Console → Rules](https://console.firebase.google.com/project/drive-ab344/database/drive-ab344-default-rtdb/rules)
2. Copy the updated rules above
3. Paste and click "Publish"
4. Wait 1-2 minutes for rules to propagate
5. Refresh your admin panel

## Security Note

⚠️ **Warning:** Adding `.read: true` makes data publicly readable. For production:
- Use Firebase Admin SDK with service account
- Or restrict by IP address
- Or use a custom token authentication system


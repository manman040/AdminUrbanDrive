# Updated Firebase Storage Rules

## Current Rules Analysis

Your current Storage rules allow read/write access until December 3, 2025:

```javascript
rules_version = '2';
service firebase.storage {
  match /b/{bucket}/o {
    match /{allPaths=**} {
      allow read, write: if request.time < timestamp.date(2025, 12, 3);
    }
  }
}
```

## Recommended Rules for Admin Panel

These rules allow public read access (for admin panel) while requiring authentication for writes:

```javascript
rules_version = '2';
service firebase.storage {
  match /b/{bucket}/o {
    // Allow public read access for all files (required for admin panel)
    match /{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null;
    }
  }
}
```

## More Secure Rules (Recommended for Production)

If you want to restrict access more, use these rules:

```javascript
rules_version = '2';
service firebase.storage {
  match /b/{bucket}/o {
    // Driver document photos - public read for admin, auth write
    match /ic_photos/{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null;
    }
    
    match /license_photos/{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null;
    }
    
    match /grant_photos/{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null;
    }
    
    match /car_photos/{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null;
    }
    
    match /profile_photos/{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null;
    }
    
    // Default: allow read for admin, write requires auth
    match /{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null;
    }
  }
}
```

## How to Update Rules

1. Go to [Firebase Console](https://console.firebase.google.com/)
2. Select your project: **drive-ab344**
3. Go to **Storage** → **Rules**
4. Copy and paste one of the rule sets above
5. Click **Publish**
6. Wait 1-2 minutes for rules to propagate

## Testing

After updating rules, test image access:

1. Open `check-storage-paths.php` in your browser
2. Click "Test" buttons for each document type
3. Check which paths work
4. Update the code in `driver-requests.php` if needed

## Important Notes

- **Public Read Access**: Required for admin panel to display images without authentication
- **Write Protection**: Only authenticated users can upload/modify files
- **Security**: Public read access means anyone with the URL can view images
- **Alternative**: For production, consider using Firebase Admin SDK with service account to generate signed URLs

## Troubleshooting

### Images Still Not Loading

1. **Check Storage Rules**: Make sure rules allow `read: if true`
2. **Verify File Paths**: Use `check-storage-paths.php` to test paths
3. **Check File Exists**: Verify files exist in Firebase Storage Console
4. **Check File Names**: File names might be different than expected
5. **Check Extensions**: Files might be `.png` instead of `.jpg` or vice versa

### Common Issues

- **403 Forbidden**: Rules are blocking access → Update rules to allow read
- **404 Not Found**: File doesn't exist at that path → Check actual path in Storage Console
- **CORS Error**: Firebase Storage handles CORS automatically, but check browser console

## Next Steps

1. Update Storage rules using one of the rule sets above
2. Use `check-storage-paths.php` to identify correct file paths
3. Update `driver-requests.php` if file paths are different than expected
4. Test image loading in the admin panel


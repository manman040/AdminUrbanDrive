# Firebase Storage Rules for Admin Panel

## Overview
To allow the admin panel to access images from Firebase Storage, you need to configure Storage Security Rules.

## Storage Rules Configuration

### Step 1: Go to Firebase Console
1. Open [Firebase Console](https://console.firebase.google.com/)
2. Select your project: **drive-ab344**
3. Go to **Storage** → **Rules**

### Step 2: Update Storage Rules

Copy and paste these rules:

```json
rules_version = '2';
service firebase.storage {
  match /b/{bucket}/o {
    // Allow public read access for all files (for admin panel)
    match /{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null;
    }
  }
}
```

### Alternative: More Secure Rules (Recommended for Production)

If you want more control, use these rules:

```json
rules_version = '2';
service firebase.storage {
  match /b/{bucket}/o {
    // Driver documents folder
    match /driver_documents/{driverId}/{fileName} {
      allow read: if true;  // Public read for admin panel
      allow write: if request.auth != null && request.auth.uid == driverId;
    }
    
    // Car images folder
    match /car_images/{driverId}/{fileName} {
      allow read: if true;  // Public read for admin panel
      allow write: if request.auth != null && request.auth.uid == driverId;
    }
    
    // ID cards folder
    match /id_cards/{driverId}/{fileName} {
      allow read: if true;  // Public read for admin panel
      allow write: if request.auth != null && request.auth.uid == driverId;
    }
    
    // Licenses folder
    match /licenses/{driverId}/{fileName} {
      allow read: if true;  // Public read for admin panel
      allow write: if request.auth != null && request.auth.uid == driverId;
    }
    
    // Car grants folder
    match /car_grants/{driverId}/{fileName} {
      allow read: if true;  // Public read for admin panel
      allow write: if request.auth != null && request.auth.uid == driverId;
    }
    
    // Default: allow read for admin, write requires auth
    match /{allPaths=**} {
      allow read: if true;
      allow write: if request.auth != null;
    }
  }
}
```

### Step 3: Publish Rules
1. Click **Publish** button
2. Wait 1-2 minutes for rules to propagate

## How It Works

### Image URL Formats Supported

The admin panel now supports multiple image URL formats:

1. **Full HTTPS URL**: `https://firebasestorage.googleapis.com/v0/b/...`
   - Used as-is

2. **gs:// URL**: `gs://drive-ab344.firebasestorage.app/path/to/image.jpg`
   - Automatically converted to download URL

3. **Storage Path**: `driver_documents/driver123/id_card.jpg`
   - Automatically converted to download URL

### URL Conversion

The system automatically converts storage paths to download URLs:
```
gs://drive-ab344.firebasestorage.app/driver_documents/abc123/id_card.jpg
↓
https://firebasestorage.googleapis.com/v0/b/drive-ab344.firebasestorage.app/o/driver_documents%2Fabc123%2Fid_card.jpg?alt=media
```

## Testing

### Test Image Access
1. Go to Driver Requests page
2. Click "View" button on any document/image column
3. Image should open in a modal

### Test Storage Rules
You can test if images are accessible by:
1. Opening browser console (F12)
2. Trying to access an image URL directly
3. Should return 200 OK if rules are correct

## Security Notes

⚠️ **Warning**: The rules above allow **public read access** to all files in Storage. This means:
- Anyone with the URL can access the images
- Good for admin panel access
- Not ideal for sensitive documents

### For Production:
1. Use Firebase Admin SDK with service account
2. Generate signed URLs with expiration
3. Implement IP whitelisting
4. Use authentication tokens

## Troubleshooting

### Images Not Loading
1. **Check Storage Rules**: Make sure rules allow read access
2. **Check URL Format**: Verify the path in Firebase Console
3. **Check Browser Console**: Look for CORS or 403 errors
4. **Verify File Exists**: Check Firebase Storage Console

### Common Errors

**403 Forbidden**:
- Storage rules are blocking access
- Update rules to allow read access

**404 Not Found**:
- File doesn't exist at that path
- Check the path in Firebase Storage Console

**CORS Error**:
- Firebase Storage should handle CORS automatically
- If issue persists, check Firebase project settings

## Example Data Structure

Your driver application data should store image paths like:

```json
{
  "driver_approvals": {
    "driver123": {
      "name": "John Doe",
      "idCard": "gs://drive-ab344.firebasestorage.app/id_cards/driver123/id_card.jpg",
      "drivingLicense": "gs://drive-ab344.firebasestorage.app/licenses/driver123/license.jpg",
      "carGrant": "gs://drive-ab344.firebasestorage.app/car_grants/driver123/grant.jpg",
      "carImage": "gs://drive-ab344.firebasestorage.app/car_images/driver123/car.jpg"
    }
  }
}
```

Or with relative paths:

```json
{
  "idCard": "id_cards/driver123/id_card.jpg",
  "drivingLicense": "licenses/driver123/license.jpg",
  "carGrant": "car_grants/driver123/grant.jpg",
  "carImage": "car_images/driver123/car.jpg"
}
```

Both formats are automatically handled by the `getImageUrl()` function.


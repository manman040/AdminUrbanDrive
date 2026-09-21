# Admin authentication setup

The admin panel, the mobile app and the ToyyibPay backend all share one Firebase
Realtime Database. Since the September 2026 security fix:

- **Nothing in the database is public.** Every read/write needs a signed-in Firebase
  user, and each collection only allows what that user's role needs
  (`database.rules.json` in the app repo is the single source of truth).
- **Admins are Firebase users listed under `/admins/{uid}: true`.** The PHP panel
  signs in with Firebase Auth, checks that list, and sends the user's ID token
  (`?auth=`) on every database request. The old built-in `admin` password is gone.
- **The ToyyibPay backend (`index.js`) uses a service account** (Firebase Admin SDK),
  because it runs without a user.

## 1. Create the admin account

1. Firebase Console -> **Authentication** -> Users -> **Add user**. Use the email and
   password the admin will log in with.
2. Copy the new user's **User UID**.
3. Firebase Console -> **Realtime Database** -> Data. At the root, add:

   ```
   admins
     <User UID>: true
   ```

   (Click "+" on the root, key `admins`, then "+" again with key = the UID and value
   `true` as a boolean.)

Repeat step 1-3 for each admin. Remove the UID from `/admins` to revoke access.

## 2. Deploy the database rules

The rules live in the app repo (`D:\PerdaRide-App\flutter_application_1\database.rules.json`).

```bash
cd D:\PerdaRide-App\flutter_application_1
firebase login            # once
firebase deploy --only database
```

Or paste the file's contents into Firebase Console -> Realtime Database -> **Rules**
-> Publish.

**Do not use** the old `firebase-rules-*.json` files in this repo; they open the database
to everyone.

## 3. ToyyibPay backend on Render

1. Firebase Console -> Project settings -> **Service accounts** -> *Generate new private
   key*. This downloads a JSON file. Keep it secret; never commit it.
2. Render -> your `urbandrive-toyyibpay` service -> **Environment** -> add
   `FIREBASE_SERVICE_ACCOUNT` and paste the **entire JSON file contents** as the value.
3. Redeploy (the `firebase-admin` dependency was added to `package.json`).
4. Optional: `TOYYIBPAY_VERIFY_CALLBACK=false` skips the getBillTransactions check on
   callbacks. Leave it unset (verification on) in production.

See `.env.example` for the full list.

## 4. Admin panel (Cloud Run / Docker)

No secrets are needed by the PHP panel: it only uses the public web API key that is
already in `config/firebase-config.php`. Rebuild and deploy the image as usual.

## How the login flow works now

1. `Public/auth.php` calls `authenticateAdmin()` -> Firebase `signInWithPassword`.
2. It then reads `/admins/{uid}` with the new token. Anything other than `true` -> login
   refused with "not on the admin list".
3. The ID token, refresh token and expiry are stored in the PHP session. Every page and
   action starts with `requireAdminSession()`, which refreshes the token when it is
   about to expire and logs the admin out if it cannot.
4. All `getFirebaseData` / `setFirebaseData` / `updateFirebaseData` / `deleteFirebaseData`
   calls append `?auth=<token>` and now return `false` / `[]` on a real HTTP error
   (previously any response body counted as success). `firebaseLastError()` holds the
   last error message for diagnostics.

## What clients can and cannot do (summary of the rules)

| Path | Rider / driver (signed in) | Admin |
|---|---|---|
| `users/{uid}` | own record only; cannot change `status` | all |
| `drivers/{id}` | anyone signed in can read one driver; owner writes, but cannot set `registrationStatus`/`status` to anything except `pending` | all |
| `driver_approvals/{uid}` | owner read; owner write only while `status` stays `pending` | all |
| `bookings` | read all; create own (`pending`); rider may cancel/update own; a driver may claim a `pending` booking and update their own ride | all |
| `ratings/{driverId}/{bookingId}` | the booking's rider writes | read all |
| `commission_payment/{driverId}` | owner reads; owner creates records only with `status: pending` | all |
| `driver_commissions/{driverId}` | owner reads; owner writes `payment_deadline` only | all |
| `*_notifications/{uid}` | owner reads/updates; the other party of a booking may create | read all |
| `booking_chats/{bookingId}` | both parties of that booking | - |
| `fareSettings` | read | write |

Known gap to tighten later: any signed-in user can still read the whole `bookings`
list and any single `drivers/{id}` record (which includes the IC number and document
URLs). Fixing that needs query-based reads in the app plus moving the sensitive
driver fields out of `drivers/`.

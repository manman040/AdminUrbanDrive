# 📱 Android Firebase Code Examples

## Setup Firebase in Android

### 1. Add to your `build.gradle` (app level):
```gradle
dependencies {
    implementation 'com.google.firebase:firebase-database:20.3.0'
    implementation 'com.google.firebase:firebase-auth:22.3.0'
}
```

### 2. Initialize Firebase in your MainActivity:
```java
import com.google.firebase.database.FirebaseDatabase;
import com.google.firebase.database.DatabaseReference;

public class MainActivity extends AppCompatActivity {
    private DatabaseReference mDatabase;
    
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);
        
        // Initialize Firebase
        mDatabase = FirebaseDatabase.getInstance().getReference();
    }
}
```

---

## 🚗 Example 1: Create a New Ride

```java
public void createNewRide() {
    DatabaseReference ridesRef = mDatabase.child("rides");
    String rideId = ridesRef.push().getKey();
    
    Map<String, Object> rideData = new HashMap<>();
    rideData.put("user_id", "user_123");
    rideData.put("driver_id", "driver_456");
    rideData.put("pickup", "Kuala Lumpur City Centre");
    rideData.put("destination", "Petaling Jaya");
    rideData.put("fare", "RM 35.00");
    rideData.put("status", "ongoing");
    rideData.put("created_at", new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(new Date()));
    
    ridesRef.child(rideId).setValue(rideData)
        .addOnSuccessListener(aVoid -> {
            // Ride created successfully
            Toast.makeText(this, "Ride created!", Toast.LENGTH_SHORT).show();
        })
        .addOnFailureListener(e -> {
            // Handle error
            Toast.makeText(this, "Failed to create ride: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        });
}
```

---

## 👤 Example 2: Create a New User

```java
public void createNewUser(String name, String email, String phone) {
    DatabaseReference usersRef = mDatabase.child("users");
    String userId = usersRef.push().getKey();
    
    Map<String, Object> userData = new HashMap<>();
    userData.put("name", name);
    userData.put("email", email);
    userData.put("phone", phone);
    userData.put("status", "active");
    userData.put("created_at", new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(new Date()));
    
    usersRef.child(userId).setValue(userData)
        .addOnSuccessListener(aVoid -> {
            Toast.makeText(this, "User registered!", Toast.LENGTH_SHORT).show();
        })
        .addOnFailureListener(e -> {
            Toast.makeText(this, "Failed to register user: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        });
}
```

---

## 🚘 Example 3: Driver Application

```java
public void submitDriverApplication(String name, String email, String license, String vehicle) {
    DatabaseReference appsRef = mDatabase.child("driver_applications");
    String appId = appsRef.push().getKey();
    
    Map<String, Object> appData = new HashMap<>();
    appData.put("name", name);
    appData.put("email", email);
    appData.put("license", license);
    appData.put("vehicle", vehicle);
    appData.put("status", "pending");
    appData.put("created_at", new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(new Date()));
    
    appsRef.child(appId).setValue(appData)
        .addOnSuccessListener(aVoid -> {
            Toast.makeText(this, "Application submitted!", Toast.LENGTH_SHORT).show();
        })
        .addOnFailureListener(e -> {
            Toast.makeText(this, "Failed to submit application: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        });
}
```

---

## 💰 Example 4: Create Commission Record

```java
public void createCommissionRecord(String driverId, String rideId, double fare, int commissionRate) {
    DatabaseReference commRef = mDatabase.child("commissions");
    String commId = commRef.push().getKey();
    
    double commissionAmount = (fare * commissionRate) / 100;
    
    Map<String, Object> commData = new HashMap<>();
    commData.put("driver_id", driverId);
    commData.put("ride_id", rideId);
    commData.put("fare", "RM " + String.format("%.2f", fare));
    commData.put("commission_rate", commissionRate);
    commData.put("commission_amount", "RM " + String.format("%.2f", commissionAmount));
    commData.put("status", "unpaid");
    commData.put("created_at", new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(new Date()));
    
    commRef.child(commId).setValue(commData)
        .addOnSuccessListener(aVoid -> {
            Toast.makeText(this, "Commission record created!", Toast.LENGTH_SHORT).show();
        })
        .addOnFailureListener(e -> {
            Toast.makeText(this, "Failed to create commission: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        });
}
```

---

## 📊 Example 5: Listen for Real-time Updates

```java
public void listenForRideUpdates() {
    DatabaseReference ridesRef = mDatabase.child("rides");
    
    ridesRef.addValueEventListener(new ValueEventListener() {
        @Override
        public void onDataChange(DataSnapshot snapshot) {
            // This runs every time data changes
            for (DataSnapshot rideSnapshot : snapshot.getChildren()) {
                String rideId = rideSnapshot.getKey();
                String status = rideSnapshot.child("status").getValue(String.class);
                String pickup = rideSnapshot.child("pickup").getValue(String.class);
                String destination = rideSnapshot.child("destination").getValue(String.class);
                
                // Update your UI here
                updateRideInUI(rideId, status, pickup, destination);
            }
        }
        
        @Override
        public void onCancelled(DatabaseError error) {
            Toast.makeText(MainActivity.this, "Failed to load rides: " + error.getMessage(), Toast.LENGTH_SHORT).show();
        }
    });
}

private void updateRideInUI(String rideId, String status, String pickup, String destination) {
    // Update your RecyclerView, ListView, or other UI components
    // This will automatically update when data changes in Firebase
}
```

---

## 🔄 Example 6: Update Ride Status

```java
public void updateRideStatus(String rideId, String newStatus) {
    DatabaseReference rideRef = mDatabase.child("rides").child(rideId);
    
    Map<String, Object> updates = new HashMap<>();
    updates.put("status", newStatus);
    updates.put("updated_at", new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(new Date()));
    
    rideRef.updateChildren(updates)
        .addOnSuccessListener(aVoid -> {
            Toast.makeText(this, "Ride status updated to " + newStatus, Toast.LENGTH_SHORT).show();
        })
        .addOnFailureListener(e -> {
            Toast.makeText(this, "Failed to update status: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        });
}
```

---

## 📱 Example 7: Complete Ride Booking Flow

```java
public void bookRide(String pickup, String destination, String userId) {
    // Step 1: Create the ride
    DatabaseReference ridesRef = mDatabase.child("rides");
    String rideId = ridesRef.push().getKey();
    
    Map<String, Object> rideData = new HashMap<>();
    rideData.put("user_id", userId);
    rideData.put("pickup", pickup);
    rideData.put("destination", destination);
    rideData.put("status", "searching");
    rideData.put("created_at", new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(new Date()));
    
    ridesRef.child(rideId).setValue(rideData)
        .addOnSuccessListener(aVoid -> {
            // Step 2: Find available driver
            findAvailableDriver(rideId);
        })
        .addOnFailureListener(e -> {
            Toast.makeText(this, "Failed to book ride: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        });
}

private void findAvailableDriver(String rideId) {
    DatabaseReference driversRef = mDatabase.child("drivers");
    
    driversRef.orderByChild("status").equalTo("available").limitToFirst(1)
        .addListenerForSingleValueEvent(new ValueEventListener() {
            @Override
            public void onDataChange(DataSnapshot snapshot) {
                if (snapshot.exists()) {
                    DataSnapshot driverSnapshot = snapshot.getChildren().iterator().next();
                    String driverId = driverSnapshot.getKey();
                    String driverName = driverSnapshot.child("name").getValue(String.class);
                    
                    // Step 3: Assign driver to ride
                    assignDriverToRide(rideId, driverId, driverName);
                } else {
                    Toast.makeText(MainActivity.this, "No drivers available", Toast.LENGTH_SHORT).show();
                }
            }
            
            @Override
            public void onCancelled(DatabaseError error) {
                Toast.makeText(MainActivity.this, "Failed to find driver: " + error.getMessage(), Toast.LENGTH_SHORT).show();
            }
        });
}

private void assignDriverToRide(String rideId, String driverId, String driverName) {
    DatabaseReference rideRef = mDatabase.child("rides").child(rideId);
    
    Map<String, Object> updates = new HashMap<>();
    updates.put("driver_id", driverId);
    updates.put("driver_name", driverName);
    updates.put("status", "assigned");
    updates.put("assigned_at", new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(new Date()));
    
    rideRef.updateChildren(updates)
        .addOnSuccessListener(aVoid -> {
            Toast.makeText(MainActivity.this, "Driver " + driverName + " assigned to your ride!", Toast.LENGTH_SHORT).show();
        })
        .addOnFailureListener(e -> {
            Toast.makeText(MainActivity.this, "Failed to assign driver: " + e.getMessage(), Toast.LENGTH_SHORT).show();
        });
}
```

---

## 🎯 How This Connects to Your PHP Admin Panel

When you run any of these examples:

1. **Data is written to Firebase** from your Android app
2. **Your PHP admin panel reads the same data** using the functions in `firebase-config.php`
3. **Both platforms see the same data** in real-time
4. **Updates in one appear in the other** automatically

### Example Flow:
1. User books a ride in your Android app → `createNewRide()`
2. Admin sees the new ride in PHP admin panel → `getRidesData()`
3. Admin updates ride status in PHP → `updateRideStatus()`
4. Android app shows updated status → `listenForRideUpdates()`

**That's it! Your app and website are now connected through Firebase! 🚀**

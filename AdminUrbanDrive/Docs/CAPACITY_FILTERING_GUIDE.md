# Capacity-Based Booking Filter Guide

## Overview
The booking system now filters available bookings based on driver car capacity. Drivers with 4-seater cars will only see bookings that require 4 or fewer passengers. Bookings requiring more than 4 passengers will only appear to drivers with larger capacity vehicles.

## How It Works

### Logic
- **Driver Capacity**: Retrieved from driver profile (`passengerCapacity`, `capacity`, or `carCapacity` field)
- **Booking Passenger Count**: Retrieved from booking (`passengerCount`, `numberOfPassengers`, `passengers`, or `passengerCapacity` field)
- **Filter Rule**: Only bookings where `passengerCount <= driverCapacity` are shown

### Example
- Driver A has a 4-seater car → Only sees bookings with 1-4 passengers
- Driver B has a 7-seater car → Sees bookings with 1-7 passengers
- Booking requires 6 passengers → Only visible to Driver B, not Driver A

## API Endpoints

### 1. Get Available Bookings (Filtered by Capacity)

**Endpoint**: `available-bookings-api.php`

**Method**: GET or POST

**Parameters**:
- `driver_id` (required): The driver's ID

**Example Request**:
```
GET http://yourserver.com/AdminUrbanDrive/available-bookings-api.php?driver_id=YOUR_DRIVER_ID
```

**Example Response**:
```json
{
  "success": true,
  "driverId": "dc0aoTWFphdVK0ayxCbgIm6aLTq1",
  "driverCapacity": 4,
  "count": 3,
  "bookings": [
    {
      "bookingId": "booking123",
      "pickupLocation": "Kuala Lumpur",
      "dropoffLocation": "Genting",
      "pickupLat": 3.1390,
      "pickupLng": 101.6869,
      "dropoffLat": 3.4244,
      "dropoffLng": 101.7906,
      "distanceKm": 58.5,
      "estimatedFare": 150.00,
      "passengerCount": 2,
      "status": "pending",
      "createdAt": {
        "time": 1703123456789
      },
      "driverId": null
    }
  ],
  "message": "Found 3 available booking(s) matching your car capacity",
  "timestamp": 1703123456789
}
```

### 2. Assign Booking to Driver (With Capacity Validation)

**Endpoint**: `assign-booking-driver.php`

**Method**: POST

**Parameters**:
- `bookingId` (required): The booking ID
- `driverId` (required): The driver's ID
- `status` (optional): Booking status (default: "confirmed")

**Example Request**:
```json
{
  "bookingId": "booking123",
  "driverId": "dc0aoTWFphdVK0ayxCbgIm6aLTq1",
  "status": "confirmed"
}
```

**Success Response**:
```json
{
  "success": true
}
```

**Error Response** (Capacity Insufficient):
```json
{
  "success": false,
  "message": "Driver capacity insufficient for this booking. The booking requires more passengers than the driver's car can accommodate."
}
```

## Android Implementation

### Step 1: Get Available Bookings
```java
public void fetchAvailableBookings(String driverId) {
    String url = "http://yourserver.com/AdminUrbanDrive/available-bookings-api.php?driver_id=" + driverId;
    
    RequestQueue queue = Volley.newRequestQueue(this);
    JsonObjectRequest request = new JsonObjectRequest(
        Request.Method.GET, url, null,
        response -> {
            try {
                boolean success = response.getBoolean("success");
                int driverCapacity = response.getInt("driverCapacity");
                int count = response.getInt("count");
                JSONArray bookings = response.getJSONArray("bookings");
                
                // Process bookings
                for (int i = 0; i < bookings.length(); i++) {
                    JSONObject booking = bookings.getJSONObject(i);
                    String bookingId = booking.getString("bookingId");
                    String pickupLocation = booking.getString("pickupLocation");
                    double fare = booking.getDouble("estimatedFare");
                    int passengerCount = booking.getInt("passengerCount");
                    
                    // Display booking in driver's job list
                }
            } catch (JSONException e) {
                e.printStackTrace();
            }
        },
        error -> {
            // Handle error
        }
    );
    
    queue.add(request);
}
```

### Step 2: Accept Booking with Validation
```java
public void acceptBooking(String bookingId, String driverId) {
    String url = "http://yourserver.com/AdminUrbanDrive/assign-booking-driver.php";
    
    JSONObject params = new JSONObject();
    try {
        params.put("bookingId", bookingId);
        params.put("driverId", driverId);
        params.put("status", "confirmed");
    } catch (JSONException e) {
        e.printStackTrace();
    }
    
    RequestQueue queue = Volley.newRequestQueue(this);
    JsonObjectRequest request = new JsonObjectRequest(
        Request.Method.POST, url, params,
        response -> {
            try {
                boolean success = response.getBoolean("success");
                if (success) {
                    // Booking assigned successfully
                    Toast.makeText(this, "Booking accepted!", Toast.LENGTH_SHORT).show();
                } else {
                    String message = response.getString("message");
                    Toast.makeText(this, message, Toast.LENGTH_LONG).show();
                }
            } catch (JSONException e) {
                e.printStackTrace();
            }
        },
        error -> {
            // Handle error - might be capacity validation failure
            if (error.networkResponse != null && error.networkResponse.statusCode == 400) {
                Toast.makeText(this, "Cannot accept: Car capacity insufficient", Toast.LENGTH_LONG).show();
            }
        }
    );
    
    queue.add(request);
}
```

## Data Structure Requirements

### Driver Profile
Your driver data in Firebase should include capacity information:
```json
{
  "drivers": {
    "driverId123": {
      "name": "John Doe",
      "passengerCapacity": 4,  // or "capacity" or "carCapacity"
      "carPlateNumber": "ABC1234",
      ...
    }
  }
}
```

### Booking Data
Your booking data in Firebase should include passenger count:
```json
{
  "bookings": {
    "booking123": {
      "passengerCount": 3,  // or "numberOfPassengers" or "passengers"
      "pickupLocation": "Kuala Lumpur",
      "status": "pending",
      ...
    }
  }
}
```

## Field Name Variations

The system checks multiple field names for compatibility:

**Driver Capacity Fields** (checked in order):
1. `passengerCapacity`
2. `capacity`
3. `carCapacity`

**Booking Passenger Count Fields** (checked in order):
1. `passengerCount`
2. `numberOfPassengers`
3. `passengers`
4. `passengerCapacity`

If none found, defaults to 1 passenger.

## Testing

### Test Available Bookings API
```
http://localhost/AdminUrbanDrive/available-bookings-api.php?driver_id=YOUR_DRIVER_ID
```

### Test Debug List
```
http://localhost/AdminUrbanDrive/debug-list-bookings.php?driver_id=YOUR_DRIVER_ID
```

This will show:
- Available bookings (filtered by capacity)
- Assigned bookings (already assigned to driver)

## Notes

- The filter only applies to **pending bookings with no driver assigned**
- Once a booking is assigned, it appears in the driver's assigned bookings regardless of capacity (for historical purposes)
- Capacity validation happens both:
  1. When fetching available bookings (filtering)
  2. When assigning a booking (validation check)

## Troubleshooting

**Issue**: Driver sees no available bookings
- Check driver capacity is set correctly in Firebase
- Check booking passenger count is set correctly
- Verify bookings have status "pending" and no driverId assigned

**Issue**: Driver can't accept booking
- Check booking passenger count exceeds driver capacity
- Verify booking exists and is in "pending" status
- Check Firebase rules allow updates


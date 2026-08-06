# Commission Payment Tracking System

## Problem
When a driver makes a payment, the system needs to track **which specific jobs/rides** are covered by that payment, especially when payments are partial (not covering all unpaid commissions).

## Solution Implemented

### 1. FIFO (First-In-First-Out) Payment Allocation
The system now automatically allocates payments to the **oldest unpaid jobs first**. This ensures:
- Clear tracking of which jobs are paid
- Fair allocation when partial payments are made
- Accurate status display for each job

### 2. Payment Structure
When a payment is recorded in `commission_payment`, it can optionally include `job_ids` or `booking_ids` to explicitly mark which jobs are covered. If not provided, the system uses FIFO to allocate automatically.

**Recommended Payment Structure:**
```json
{
  "commission_payment": {
    "{driverId}": {
      "{paymentId}": {
        "amount": 1000.00,
        "status": "paid",
        "paid_at": 1234567890000,
        "job_ids": ["booking1", "booking2", "booking3"],  // Optional: explicit job IDs
        "created_at": 1234567890000
      }
    }
  }
}
```

### 3. Job Status Calculation
Each job's status is now calculated based on:
1. **Explicit job IDs** in payment records (if provided)
2. **FIFO allocation** (oldest unpaid jobs get paid first)
3. **Status values:**
   - `paid` - Fully paid
   - `partial` - Partially paid (rare, only if payment is less than one job's commission)
   - `unpaid` - Not yet paid
   - `pending` - Job not yet completed

## App Feature Recommendation

### Suggested App Flow:

```
Driver App → Commission Screen
├── List Commission
│   ├── Total Commission: RM X,XXX.XX
│   ├── Paid: RM X,XXX.XX
│   ├── Unpaid: RM X,XXX.XX
│   │
│   └── Commission Details (Expandable List)
│       ├── Job 1 - RM 50.00 [PAID] ✓
│       ├── Job 2 - RM 75.00 [PAID] ✓
│       ├── Job 3 - RM 100.00 [UNPAID]
│       ├── Job 4 - RM 80.00 [UNPAID]
│       └── ...
│
└── [Pay All Commission] Button
    └── Opens Payment Method Selection
        └── Process Payment
            └── Updates commission_payment in Firebase
```

### Implementation Steps for App:

1. **Display Commission List:**
   - Fetch from `driver_commissions/{driverId}/recent_jobs`
   - Show each job with status (paid/unpaid)
   - Display totals at top

2. **"Pay All Commission" Button:**
   - Calculate total unpaid amount
   - Show payment confirmation dialog
   - Process payment (via payment gateway)
   - Create payment record in Firebase

3. **Payment Record Creation:**
   ```javascript
   // When payment is successful
   const paymentData = {
     amount: totalUnpaidAmount,
     status: 'paid',
     paid_at: Date.now(),
     job_ids: unpaidJobIds, // Array of booking IDs being paid
     payment_method: 'bank_transfer', // or 'cash', 'online', etc.
     transaction_id: 'TXN123456'
   };
   
   // Save to Firebase
   firebase.database().ref(`commission_payment/${driverId}/${paymentId}`)
     .set(paymentData);
   ```

4. **Optional: Partial Payment:**
   - Allow driver to select specific jobs to pay
   - Calculate total for selected jobs
   - Process payment for selected jobs only
   - Include only selected `job_ids` in payment record

### Firebase Structure Example:

```json
{
  "commission_payment": {
    "driverId123": {
      "payment_20250115_001": {
        "amount": 500.00,
        "status": "paid",
        "paid_at": 1736899200000,
        "job_ids": [
          "-O_LLZeShQuxnv2noFjB",
          "-O_LMBf7D0d_fl8Tt3_8",
          "-O_LTf6asXz_7TGN1IKJ"
        ],
        "payment_method": "bank_transfer",
        "transaction_id": "TXN20250115001",
        "created_at": 1736899200000
      }
    }
  }
}
```

## Benefits

1. **Transparency:** Drivers can see exactly which jobs are paid/unpaid
2. **Accuracy:** System automatically tracks payment allocation
3. **Flexibility:** Supports both "Pay All" and selective payment
4. **Audit Trail:** Clear record of which jobs each payment covers

## Admin Panel Updates

The admin panel (`driver-commission.php`) now:
- Shows accurate job-level payment status
- Uses FIFO to allocate payments when `job_ids` are not specified
- Displays status badges (paid/unpaid/partial) for each job
- Automatically updates when payments are recorded

## Next Steps

1. ✅ **Backend:** FIFO allocation implemented
2. ✅ **Admin Panel:** Job status display updated
3. ⏳ **App Feature:** Implement "List Commission" screen
4. ⏳ **App Feature:** Implement "Pay All Commission" button
5. ⏳ **App Feature:** Optional: Add job selection for partial payments


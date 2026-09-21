import express from 'express';
import cors from 'cors';
import fetch from 'node-fetch';
import dotenv from 'dotenv';
import admin from 'firebase-admin';
dotenv.config();

const app = express();
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));

const TOYYIB_BASE = process.env.TOYYIBPAY_BASE || 'https://dev.toyyibpay.com';
const FIREBASE_DB_URL = process.env.FIREBASE_DB_URL ||
  'https://drive-ab344-default-rtdb.asia-southeast1.firebasedatabase.app/';

// The database rules no longer allow unauthenticated writes, so this backend
// signs in with a Firebase service account (the Admin SDK bypasses the rules).
// Set FIREBASE_SERVICE_ACCOUNT to the full contents of the service-account
// JSON file (Render: Environment -> add variable, paste the JSON as-is).
function initFirebase() {
  const raw = process.env.FIREBASE_SERVICE_ACCOUNT;
  if (raw) {
    let serviceAccount;
    try {
      serviceAccount = JSON.parse(raw);
    } catch (e) {
      throw new Error('FIREBASE_SERVICE_ACCOUNT is not valid JSON: ' + e.message);
    }
    admin.initializeApp({
      credential: admin.credential.cert(serviceAccount),
      databaseURL: FIREBASE_DB_URL,
    });
    return;
  }
  // Falls back to GOOGLE_APPLICATION_CREDENTIALS / the GCP metadata server.
  console.warn('FIREBASE_SERVICE_ACCOUNT not set; trying application default credentials');
  admin.initializeApp({
    credential: admin.credential.applicationDefault(),
    databaseURL: FIREBASE_DB_URL,
  });
}
initFirebase();
const db = admin.database();

app.get('/', (_req, res) => res.json({ ok: true, service: 'ToyyibPay backend' }));

app.post('/api/toyyibpay/create-bill', async (req, res) => {
  try {
    const {
      amount,
      driverId,
      reference,
      returnUrl,
      callbackUrl,
      billTo,
      billEmail,
      billPhone,
      billName,
      billDescription
    } = req.body;
    if (!amount || !driverId) return res.status(400).json({ error: 'amount and driverId are required' });

    const numericAmount = Number(amount);
    if (!Number.isFinite(numericAmount) || numericAmount <= 0) {
      return res.status(400).json({ error: 'amount must be a positive number' });
    }

    const billAmount = Math.round(numericAmount * 100);
    const form = new URLSearchParams({
      userSecretKey: process.env.TOYYIBPAY_SECRET,
      categoryCode: process.env.TOYYIBPAY_CATEGORY,
      billName: billName || 'Urban Drive Commission',
      billDescription: billDescription || `Commission for ${driverId}`,
      billPriceSetting: '1',
      billPayorInfo: '1',
      billAmount: String(billAmount),
      billExternalReferenceNo: reference || '',
      billReturnUrl: returnUrl || process.env.APP_RETURN_URL || '',
      billCallbackUrl: callbackUrl || process.env.APP_CALLBACK_URL || '',
      billTo: billTo || driverId,
      billEmail: billEmail || `${driverId}@perdaride.app`,
      billPhone: billPhone || '0123456789'
    });

    const r = await fetch(`${TOYYIB_BASE}/index.php/api/createBill`, {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form
    });
    const data = await r.json();
    if (!Array.isArray(data) || !data[0]?.BillCode) {
      return res.status(400).json({ error: 'Toyyib createBill failed', data });
    }
    const billCode = data[0].BillCode;
    return res.json({ billCode, paymentUrl: `${TOYYIB_BASE}/${billCode}` });
  } catch (e) {
    res.status(500).json({ error: 'server_error', message: e.message });
  }
});

app.get('/api/toyyibpay/success', (req, res) => {
  res.send(`
    <!doctype html>
    <html>
      <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Payment Received</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 32px; text-align: center; color: #1f2937; }
          .box { max-width: 460px; margin: 0 auto; }
          h1 { color: #16a34a; }
        </style>
      </head>
      <body>
        <div class="box">
          <h1>Payment received</h1>
          <p>You can close this page and return to Urban Drive.</p>
        </div>
      </body>
    </html>
  `);
});

// ToyyibPay callbacks are plain unsigned POSTs, so anyone who knows a bill
// code could otherwise mark it paid. Ask ToyyibPay whether the bill really
// has a successful transaction before trusting the callback. Set
// TOYYIBPAY_VERIFY_CALLBACK=false to skip (sandbox troubleshooting only).
async function verifyBillPaid(billCode) {
  if (String(process.env.TOYYIBPAY_VERIFY_CALLBACK).toLowerCase() === 'false') return true;
  const form = new URLSearchParams({ billCode, billpaymentStatus: '1' });
  const r = await fetch(`${TOYYIB_BASE}/index.php/api/getBillTransactions`, {
    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: form
  });
  const data = await r.json();
  return Array.isArray(data) && data.some(t => String(t.billpaymentStatus) === '1');
}

// status_id === '1' means PAID. Finds the commission_payment record whose
// billCode matches this callback and marks it paid, so the driver's
// outstanding commission actually clears once ToyyibPay confirms payment.
app.post('/api/toyyibpay/callback', async (req, res) => {
  console.log('ToyyibPay callback received:', req.body);
  try {
    const statusId = req.body.status_id ?? req.body.statusId;
    const billCode = req.body.billcode ?? req.body.billCode;

    if (String(statusId) === '1' && billCode) {
      const paid = await verifyBillPaid(billCode);
      if (!paid) {
        console.warn(`Callback for bill ${billCode} claims paid but ToyyibPay shows no successful transaction; ignoring`);
        return res.send('OK');
      }

      const snapshot = await db.ref('commission_payment').once('value');
      const allPayments = snapshot.val();

      if (allPayments && typeof allPayments === 'object') {
        for (const driverId of Object.keys(allPayments)) {
          const driverPayments = allPayments[driverId];
          if (!driverPayments || typeof driverPayments !== 'object') continue;

          for (const paymentId of Object.keys(driverPayments)) {
            const payment = driverPayments[paymentId];
            if (payment && payment.billCode === billCode && payment.status !== 'paid') {
              await db.ref(`commission_payment/${driverId}/${paymentId}`).update({
                status: 'paid',
                paid_at: Date.now(),
                paid_via: 'toyyibpay_callback',
              });
              console.log(`Marked commission_payment/${driverId}/${paymentId} as paid`);
            }
          }
        }
      }
    }
  } catch (e) {
    // A 500 makes ToyyibPay retry the webhook later instead of dropping it.
    console.error('ToyyibPay callback processing failed:', e);
    return res.status(500).send('ERROR');
  }
  res.send('OK');
});

app.listen(process.env.PORT || 3000, () => {
  console.log('Server listening on', process.env.PORT || 3000);
});

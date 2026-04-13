---
name: nowpayments-checkout-ui
description: Embed and customize the cryptocurrency checkout UI using the Laravel Cashier NOWPayments package, including Blade checkout buttons, embedded payment widget, JS modal checkout, currency selection, QR code display, and payment status polling.
---

# NOWPayments Checkout UI

## When to use this skill

Use this skill when:
- Adding a "Pay with Crypto" button to Blade views
- Using the embedded NOWPayments payment widget in an iframe overlay
- Opening the checkout overlay from JavaScript/SPA
- Displaying payment details with QR codes
- Polling payment status for real-time updates
- Customizing the checkout appearance or behavior

## Checkout Options

The package provides **two checkout approaches**:

### 1. Embedded Payment Widget (Recommended)

Uses NOWPayments' official payment widget in an iframe overlay. Zero UI maintenance — NOWPayments handles currency selection, QR codes, exchange rates, and payment tracking.

```php
// Quick redirect
return redirect()->route('cashier-nowpayments.checkout.embedded', [
    'amount' => 49.99,
    'currency' => 'usd',
    'description' => 'Premium Plan',
    'success_url' => route('payment.success'),
    'cancel_url' => route('payment.cancel'),
]);

// Via Billable trait
$url = $user->embeddedCheckoutUrl(49.99, 'usd', [
    'description' => 'Order #12345',
    'success_url' => route('payment.success'),
    'cancel_url' => route('cart'),
]);
```

**Widget URL:**
```
GET /cashier-nowpayments/checkout/embedded?amount=49.99&currency=usd&success_url=...&cancel_url=...
```

**Widget Flow:**
1. Creates invoice on NOWPayments API
2. Displays widget in beautiful overlay
3. Widget shows: `https://nowpayments.io/embeds/payment-widget?iid={invoice_id}`
4. User selects crypto, sees QR code, completes payment
5. Auto-redirects to `success_url` after payment
6. Falls back to regular checkout if widget fails

### 2. Custom Checkout Overlay

Pre-built custom UI with full control over appearance and behavior.

```
GET /cashier-nowpayments/checkout?amount=49.99&currency=usd&success_url=...&cancel_url=...
```

Required query parameters:
- `amount` — Payment amount (numeric, ≥ 0.01)
- `currency` — Fiat currency code (usd, eur, etc.)

Optional query parameters:
- `description` — Payment description
- `order_id` — Internal order reference
- `success_url` — Redirect on success (default: `config('app.url')`)
- `cancel_url` — Redirect on cancel (default: `config('app.url')`)
- `pay_currency` — Preferred crypto (btc, eth, etc.)
- `type` — Checkout type: `payment`, `invoice`, or `subscription`
- `metadata` — Additional metadata (array)

**Custom Checkout Flow:**
1. User sees overlay with amount and currency selector
2. User selects cryptocurrency → estimate is fetched
3. User clicks "Continue to Payment"
4. Payment is created via API → address + QR code displayed
5. 15-minute countdown timer starts
6. Frontend polls status every 5 seconds
7. On success: redirects to `success_url`
8. On failure/cancel: redirects to `cancel_url` or shows retry option

## CheckoutService (New)

The `CheckoutService` provides a unified, service-oriented API for all checkout scenarios.

### Basic Usage

```php
use SerenityTechnologies\CashierNowPayments\Facades\Checkout;

// Simple payment (3 lines)
$payment = Checkout::createPayment(49.99, 'usd', 'btc');
$customer = $user->createOrGetCustomer();
Checkout::completeCheckout($payment, $customer, $user);

// With validation
$validation = Checkout::validateAmount(49.99, 'usd', 'btc');
if (!$validation->isValid()) {
    throw new Exception($validation->getFirstError());
}

$estimate = Checkout::getEstimate(49.99, 'usd', 'btc');
echo $estimate->getFormattedEstimatedAmount(); // "0.00123456 BTC"

// Session-based checkout
$session = Checkout::createSession(49.99, 'usd', [
    'description' => 'Order #123',
    'success_url' => route('checkout.success'),
    'cancel_url' => route('checkout.cancel'),
]);

session(['checkout_session_id' => $session->getId()]);

// Later, retrieve
$session = Checkout::getSession(session('checkout_session_id'));
```

### CheckoutService Methods

| Method | Description |
|--------|-------------|
| `createSession($amount, $currency, $options)` | Create cached checkout session |
| `getSession($sessionId)` | Retrieve session by ID |
| `isApiAvailable()` | Check NOWPayments API status |
| `getAvailableCurrencies($fixedRate)` | Get supported currencies |
| `getMinimumPaymentAmount($from, $to)` | Get minimum amount for pair |
| `getEstimate($amount, $from, $to, $forceRefresh)` | Get crypto estimate |
| `validateAmount($amount, $from, $to)` | Validate meets minimum |
| `createPayment($amount, $currency, $payCurrency, $options)` | Create direct payment |
| `createInvoice($amount, $currency, $options)` | Create hosted invoice |
| `completeCheckout($result, $customer, $billable)` | Persist to database |
| `generateQrCodeUri($address, $amount)` | Generate QR code URI |

### Via Billable Trait

```php
// Access CheckoutService
$service = $user->checkout();
$session = $user->checkout()->createSession(49.99, 'usd');
```

## Checkout Button Helper

Generate a checkout link button in Blade:

```blade
{!! $user->checkoutButton(49.99, 'usd', [
    'text' => 'Pay with Crypto',
    'class' => 'my-custom-btn',
    'description' => 'Order #12345',
    'order_id' => 'ORD-123',
    'success_url' => route('payment.success'),
    'cancel_url' => route('cart'),
]) !!}
```

Generates:
```html
<a href="/cashier-nowpayments/checkout?amount=49.99&currency=usd"
   class="my-custom-btn"
   data-cashier-nowpayments
   data-config='{"amount":49.99,"currency":"usd","success_url":"..."}'>
    Pay with Crypto
</a>
```

### checkoutUrl() Helper

```blade
<a href="{{ $user->checkoutUrl(49.99, 'usd', [
    'description' => 'Premium Plan',
    'success_url' => route('success'),
]) }}">
    Pay Now
</a>
```

### embeddedCheckoutUrl() Helper (New)

```blade
<a href="{{ $user->embeddedCheckoutUrl(49.99, 'usd', [
    'description' => 'Premium Plan',
    'success_url' => route('payment.success'),
    'cancel_url' => route('cart'),
]) }}">
    Pay with Crypto (Widget)
</a>
```

## JS Modal Checkout

Publish the JavaScript asset:

```bash
php artisan vendor:publish --tag=cashier-nowpayments-assets
```

### Import as Module

```javascript
import { CashierCheckout } from './vendor/cashier-nowpayments/cashier-checkout';

CashierCheckout.open({
    amount: 49.99,
    currency: 'usd',
    description: 'Premium Plan',
    success_url: 'https://yoursite.com/success',
    cancel_url: 'https://yoursite.com/cancel',
}).then(result => {
    console.log('Payment:', result.purchase_id);
}).catch(err => {
    console.log('Cancelled or failed:', err.message);
});
```

### Embedded Checkout Modal (New)

Open embedded widget in modal:

```javascript
function openEmbeddedCheckout(amount, currency, options = {}) {
    const params = new URLSearchParams({
        amount: amount,
        currency: currency,
        description: options.description || '',
        success_url: options.success_url || window.location.origin + '/success',
        cancel_url: options.cancel_url || window.location.origin + '/cancel',
    });

    const modal = document.createElement('div');
    modal.style.cssText = `
        position: fixed; top: 0; left: 0; width: 100%; height: 100%;
        background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(8px);
        z-index: 999999; display: flex; align-items: center; justify-content: center;
    `;

    const container = document.createElement('div');
    container.style.cssText = `
        width: 450px; height: 750px; background: white;
        border-radius: 16px; overflow: hidden; position: relative;
    `;

    const iframe = document.createElement('iframe');
    iframe.src = `/cashier-nowpayments/checkout/embedded?${params.toString()}`;
    iframe.style.cssText = 'width: 100%; height: 100%; border: none;';

    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '×';
    closeBtn.style.cssText = `
        position: absolute; top: 10px; right: 10px;
        background: rgba(0, 0, 0, 0.5); color: white; border: none;
        border-radius: 50%; width: 32px; height: 32px; font-size: 24px; cursor: pointer;
    `;
    closeBtn.onclick = () => modal.remove();

    container.appendChild(iframe);
    container.appendChild(closeBtn);
    modal.appendChild(container);
    document.body.appendChild(modal);

    modal.onclick = (e) => { if (e.target === modal) modal.remove(); };
}

// Usage
openEmbeddedCheckout(49.99, 'usd', {
    description: 'Premium Plan',
    success_url: 'https://yoursite.com/success',
    cancel_url: 'https://yoursite.com/cancel',
});
```

### Available JS Methods

```javascript
// Direct API payment
await CashierCheckout.createPayment({
    amount: 49.99,
    currency: 'usd',
    pay_currency: 'btc',
    description: 'Order #123',
    success_url: 'https://...',
    cancel_url: 'https://...',
});

// Create hosted invoice
const invoice = await CashierCheckout.createInvoice({
    amount: 49.99,
    currency: 'usd',
    description: 'Invoice #INV-123',
    success_url: 'https://...',
    cancel_url: 'https://...',
});

// Pay an existing invoice with selected crypto
const payment = await CashierCheckout.payInvoice(invoice.invoice_id, {
    pay_currency: 'btc',
});
// Returns: { pay_address, pay_amount, pay_currency, qr_code, ... }

// Get crypto estimate
await CashierCheckout.getEstimate(49.99, 'usd', 'btc');

// Get available currencies
await CashierCheckout.getCurrencies();

// Check payment status
await CashierCheckout.checkStatus(purchaseId);
```

### postMessage Events

The checkout overlay sends `postMessage` events to the parent window:

```javascript
// Success (custom checkout)
{ type: 'cashier-checkout-complete', payload: { purchase_id, ... } }

// Widget loaded (embedded checkout)
{ type: 'cashier-checkout-loaded' }

// Cancel
{ type: 'cashier-checkout-cancel' }
```

Listen for them:

```javascript
window.addEventListener('message', (event) => {
    if (event.data.type === 'cashier-checkout-complete') {
        console.log('Payment complete:', event.data.payload);
    }
    if (event.data.type === 'cashier-checkout-loaded') {
        console.log('Widget loaded successfully');
    }
    if (event.data.type === 'cashier-checkout-cancel') {
        console.log('Checkout cancelled');
    }
});
```

## Currency Selection

The overlay fetches available currencies from the API and displays them in a grid. Popular currencies (btc, eth, usdttrc20, usdterc20, ltc, trx) are shown first.

```
GET /cashier-nowpayments/checkout/currencies
```

Response is cached for 1 hour.

## Payment Estimate

When a user selects a currency, an estimate is fetched:

```
POST /cashier-nowpayments/checkout/estimate
{
    "amount": 49.99,
    "from_currency": "usd",
    "to_currency": "btc"
}
```

Returns:
```json
{
    "success": true,
    "estimated_amount": "0.00123",
    "minimum_amount": "0.0001",
    "fee": null
}
```

## Payment Status Polling

### Remote Status

```
GET /cashier-nowpayments/payment/status/{purchaseId}
```

- Queries NOWPayments API directly
- Cached for 10 seconds (configurable: `payment_status.cache_seconds`)
- Auth-gated (configurable)

Response:
```json
{
    "success": true,
    "status": "completed",
    "payment_status": "finished",
    "actually_paid": "0.00123",
    "pay_amount": "0.00123",
    "pay_address": "bc1q...",
    "pay_currency": "btc"
}
```

### Local Status

```
GET /cashier-nowpayments/payment/local/{paymentId}
```

- Checks local database
- Syncs with NOWPayments API only if pending AND cooldown elapsed (15s default)
- Auth-gated (configurable)

## QR Code Display

The checkout overlay renders a QR code using `qrcode.js` loaded from CDN. The QR code encodes a `crypto:` URI:

```
crypto:{address}?amount={amount}
```

The embedded widget handles QR code display automatically.

## Customizing the Checkout View

### Custom Checkout Overlay

Publish and customize the Blade view:

```bash
php artisan vendor:publish --tag=cashier-nowpayments-views
```

Edit `resources/views/vendor/cashier-nowpayments/checkout.blade.php`.

### Embedded Widget Overlay

Edit `resources/views/vendor/cashier-nowpayments/checkout-embedded.blade.php`.

Customize:
- Widget dimensions (default: 410×696)
- Header colors and gradient
- Loading/error states
- Footer attribution
- Mobile responsive breakpoints

## Checkout Configuration

```env
# Payment Timeout
CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT=900       # Countdown timer seconds (15 min)

# Status Polling
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=10   # Remote status cache TTL
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=15          # Local sync cooldown seconds

# Authorization
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH=true  # Auth-gate status endpoints
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD=web  # Auth guard to use

# Payment Defaults
CASHIER_NOWPAYMENTS_FIXED_RATE=false          # Lock exchange rate for 20 min
CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER=false    # Customer pays network fee
```

## Rate Limits

| Endpoint | Limit |
|----------|-------|
| GET `/checkout/embedded` | — |
| POST `/checkout/payment` | 30 per minute |
| POST `/checkout/invoice` | 20 per minute |
| POST `/checkout/subscription` | 10 per minute |
| POST `/checkout/estimate` | 60 per minute |
| GET `/payment/status/{id}` | 30 per minute |
| GET `/payment/local/{id}` | 30 per minute |

## Embedded Widget Features

The NOWPayments embedded widget provides:

✅ **Currency Selection** - Users choose from available cryptocurrencies
✅ **Real-time Exchange Rates** - Live conversion rates
✅ **QR Code Display** - Scannable QR code for mobile wallets
✅ **Payment Address** - Copyable deposit address
✅ **Payment Status** - Real-time status updates
✅ **Countdown Timer** - Time remaining for payment
✅ **Multi-language Support** - Automatic language detection
✅ **Mobile Responsive** - Works on all screen sizes
✅ **Success/Cancel Redirects** - Automatic redirect after payment
✅ **Zero Maintenance** - NOWPayments maintains the widget UI

## Error Handling

### Embedded Widget States

1. **Loading** (0-10 seconds)
   - Shows spinner with "Loading payment widget..."
   
2. **Loaded**
   - Widget displays payment interface
   
3. **Error** (>10 seconds or load failure)
   - Shows error message with "Try Again" button
   - Automatic fallback to regular checkout if invoice creation fails

### Custom Checkout States

1. **Form** — Currency selection and estimate
2. **Payment Details** — Address, QR code, timer
3. **Success** — Payment completed, auto-redirect
4. **Failed** — Payment failed, retry option

## When to Use Which Approach

### Use Embedded Widget When:
- ✅ Quick integration needed
- ✅ Don't want to maintain custom UI
- ✅ Want latest features automatically
- ✅ Mobile-first experience important
- ✅ Reliability is priority

### Use Custom Checkout When:
- ✅ Need full control over UI/UX
- ✅ Custom branding required
- ✅ Need offline support
- ✅ Want custom payment flows
- ✅ Need detailed UI analytics

## Comparison

| Feature | Embedded Widget | Custom Checkout |
|---------|----------------|-----------------|
| **UI Source** | NOWPayments official | Custom built |
| **Maintenance** | Zero (NOWPayments maintains) | Full (you maintain) |
| **Crypto Selection** | Widget handles | Custom UI |
| **QR Codes** | Widget renders | Client-side qrcode.js |
| **Payment Flow** | Widget handles | Manual polling |
| **Redirects** | Automatic | Manual after polling |
| **Customization** | Limited | Full control |
| **Fallback** | To regular checkout | N/A |
| **Load Time** | Depends on CDN | Instant |
| **Best For** | Quick, reliable | Custom branding |

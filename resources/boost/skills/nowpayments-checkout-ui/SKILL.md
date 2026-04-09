---
name: nowpayments-checkout-ui
description: Embed and customize the cryptocurrency checkout UI using the Laravel Cashier NOWPayments package, including Blade checkout buttons, JS modal checkout, currency selection, QR code display, and payment status polling.
---

# NOWPayments Checkout UI

## When to use this skill

Use this skill when:
- Adding a "Pay with Crypto" button to Blade views
- Opening the checkout overlay from JavaScript/SPA
- Displaying payment details with QR codes
- Polling payment status for real-time updates
- Customizing the checkout appearance or behavior

## Checkout Overlay

The package includes a pre-built checkout overlay rendered as a Blade view (`checkout.blade.php`).

### Accessing the Checkout

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

### Checkout Flow

1. User sees overlay with amount and currency selector
2. User selects cryptocurrency → estimate is fetched
3. User clicks "Continue to Payment"
4. Payment is created via API → address + QR code displayed
5. 15-minute countdown timer starts
6. Frontend polls status every 5 seconds
7. On success: redirects to `success_url`
8. On failure/cancel: redirects to `cancel_url` or shows retry option

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
// Success
{ type: 'cashier-checkout-complete', payload: { purchase_id, ... } }

// Cancel
{ type: 'cashier-checkout-cancel' }
```

Listen for them:

```javascript
window.addEventListener('message', (event) => {
    if (event.data.type === 'cashier-checkout-complete') {
        console.log('Payment complete:', event.data.payload);
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

## Customizing the Checkout View

Publish and customize the Blade view:

```bash
php artisan vendor:publish --tag=cashier-nowpayments-views
```

Edit `resources/views/vendor/cashier-nowpayments/checkout.blade.php`.

## Checkout Configuration

```env
CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT=900       # Countdown timer seconds (15 min)
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=10   # Remote status cache TTL
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=15          # Local sync cooldown seconds
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH=true  # Auth-gate status endpoints
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD=web  # Auth guard to use
```

## Rate Limits

| Endpoint | Limit |
|----------|-------|
| POST `/checkout/payment` | 30 per minute |
| POST `/checkout/invoice` | 20 per minute |
| POST `/checkout/subscription` | 10 per minute |
| POST `/checkout/estimate` | 60 per minute |
| GET `/payment/status/{id}` | 30 per minute |
| GET `/payment/local/{id}` | 30 per minute |

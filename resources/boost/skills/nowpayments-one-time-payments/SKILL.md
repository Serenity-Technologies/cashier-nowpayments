---
name: nowpayments-one-time-payments
description: Create and manage one-time cryptocurrency payments using the Laravel Cashier NOWPayments package, including direct payments, embedded payment widget, checkout overlay, guest checkout, CheckoutService, and payment status polling.
---

# NOWPayments One-Time Payments

## When to use this skill

Use this skill when:
- Creating direct cryptocurrency payments for authenticated or guest users
- Using the embedded NOWPayments payment widget for zero-maintenance UI
- Embedding the checkout overlay UI in Blade views
- Using the CheckoutService for service-oriented payment flows
- Using the JS modal checkout for SPAs
- Polling payment status for frontend updates
- Configuring idempotency to prevent duplicate payments

## Billable Model Setup

The target model must use the `Billable` trait:

```php
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

## Creating Payments

### Option 1: CheckoutService (Recommended)

The `CheckoutService` provides a unified, testable API for all payment scenarios.

```php
use SerenityTechnologies\CashierNowPayments\Facades\Checkout;

// Simple payment (3 lines)
$payment = Checkout::createPayment(49.99, 'usd', 'btc');
$customer = $user->createOrGetCustomer();
Checkout::completeCheckout($payment, $customer, $user);

// With full validation
$validation = Checkout::validateAmount(49.99, 'usd', 'btc');
if (!$validation->isValid()) {
    throw new Exception($validation->getFirstError());
}

$estimate = Checkout::getEstimate(49.99, 'usd', 'btc');
echo $estimate->getFormattedEstimatedAmount(); // "0.00123456 BTC"

$payment = Checkout::createPayment(49.99, 'usd', 'btc', [
    'description' => 'Premium ebook',
    'order_id' => 'ORDER-123',
    'fixed_rate' => true,
    'fee_paid_by_user' => false,
]);

Checkout::completeCheckout($payment, $customer, $user);
```

### Option 2: Embedded Payment Widget (Zero UI Maintenance)

Use NOWPayments' official payment widget in an iframe overlay.

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

return redirect($url);
```

**Widget displays:** `https://nowpayments.io/embeds/payment-widget?iid={invoice_id}`

**Widget handles:**
- Currency selection (BTC, ETH, USDT, etc.)
- Real-time exchange rates
- QR code generation
- Payment address display
- Countdown timer
- Payment status tracking
- Success/cancel redirects

### Option 3: Direct Payment (PaymentBuilder)

Use the fluent `PaymentBuilder` via `$user->newPayment()`:

```php
$payment = $user->newPayment(49.99, 'usd')
    ->withPayCurrency('btc')
    ->withDescription('Premium ebook')
    ->withOrderId('ORDER-123')
    ->withFixedRate()           // Lock rate for 20 min
    ->withFeePaidByUser()       // Payer covers network fee
    ->withCredits()             // Apply available credits first
    ->charge();                 // API + persist in DB transaction

// Access payment details
$payment->pay_address;   // BTC deposit address
$payment->pay_amount;    // Amount in BTC
$payment->pay_currency;  // 'btc'
```

### PaymentBuilder Methods

| Method | Purpose |
|--------|---------|
| `withPayCurrency($crypto)` | Cryptocurrency to pay with (btc, eth, usdttrc20, etc.) |
| `withDescription($text)` | Payment description |
| `withOrderId($id)` | Internal order reference |
| `withFixedRate()` | Lock exchange rate for 20 minutes |
| `withFeePaidByUser()` | Network fee paid by customer instead of merchant |
| `withCredits()` | Consume available credits before charging |
| `withMetadata($array)` | Additional key-value metadata |
| `withRedirectUrl($url)` | Redirect URL after payment |
| `charge()` | Create on API + persist locally (returns `Payment` model) |
| `create()` | Create on API only (returns DTO, no persist) |

## Guest Checkout

Guest payments are handled automatically by the checkout overlay and embedded widget. The `CheckoutController` creates a guest `Customer` record linked by session ID and caches the billable mapping for webhook reconciliation.

### Via CheckoutService

```php
// Create session (works for guests)
$session = Checkout::createSession(49.99, 'usd', [
    'description' => 'Order #123',
    'success_url' => route('checkout.success'),
    'cancel_url' => route('checkout.cancel'),
]);

// Store in session
session(['checkout_session_id' => $session->getId()]);

// Later, retrieve
$session = Checkout::getSession(session('checkout_session_id'));
```

### Via Embedded Widget

```php
// Automatically creates guest customer
return redirect()->route('cashier-nowpayments.checkout.embedded', [
    'amount' => 49.99,
    'currency' => 'usd',
]);
```

## Checkout Overlay (Custom UI)

### Blade View

The package provides a pre-built checkout overlay at `resources/views/vendor/cashier-nowpayments/checkout.blade.php`. Access it via:

```
GET /cashier-nowpayments/checkout?amount=49.99&currency=usd&success_url=...&cancel_url=...
```

### Checkout Button Helper

```blade
{!! $user->checkoutButton(49.99, 'usd', [
    'text' => 'Pay with Crypto',
    'description' => 'Order #12345',
    'success_url' => route('payment.success'),
    'cancel_url' => route('cart'),
]) !!}
```

Generates:
```html
<a href="/cashier-nowpayments/checkout?amount=49.99&currency=usd"
   class="cashier-nowpayments-checkout-btn"
   data-cashier-nowpayments
   data-config='{"amount":49.99,"currency":"usd",...}'>
    Pay with Crypto
</a>
```

### JS Modal Checkout

For SPA integrations, publish the JS asset:

```bash
php artisan vendor:publish --tag=cashier-nowpayments-assets
```

Then use:

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
    console.log('Cancelled');
});
```

### Embedded Checkout Modal

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
});
```

Additional JS methods:
```javascript
// Direct API payment
await CashierCheckout.createPayment({ amount, currency, pay_currency, ... });

// Get estimate
await CashierCheckout.getEstimate(49.99, 'usd', 'btc');

// Get currencies
await CashierCheckout.getCurrencies();

// Check status
await CashierCheckout.checkStatus(purchaseId);
```

## Payment Status Polling

Two endpoints are available:

### Remote Status (NOWPayments API)

```
GET /cashier-nowpayments/payment/status/{purchaseId}
```

- Cached for 10 seconds (configurable: `payment_status.cache_seconds`)
- Auth-gated with ownership verification
- Returns: `{ status, payment_status, actually_paid, pay_amount, pay_address, pay_currency }`

### Local Status (Database)

```
GET /cashier-nowpayments/payment/local/{paymentId}
```

- Checks local `Payment` model
- Syncs with NOWPayments API only if pending AND cooldown elapsed (15s default)
- Auth-gated with ownership verification

## Idempotency

Payment creation uses a SHA-256 idempotency key derived from:
- User ID (or `'guest'`)
- Amount, currency, pay_currency
- Order ID
- Session ID

Responses are cached for 5 minutes. Duplicate requests within this window return the cached result.

### CheckoutService Idempotency

The CheckoutService automatically handles idempotency when using sessions:

```php
$session = Checkout::createSession(49.99, 'usd', [
    'order_id' => 'ORDER-123',  // Ensures idempotency
]);
```

## Payment Model

### Key Fields

| Field | Type | Description |
|-------|------|-------------|
| `nowpayments_payment_id` | string | NOWPayments payment ID |
| `nowpayments_purchase_id` | string | NOWPayments purchase ID |
| `status` | string | Payment status (finished, failed, expired, etc.) |
| `amount` | decimal(20,8) | Fiat amount |
| `amount_paid` | decimal(20,8) | Amount actually paid |
| `pay_currency` | string | Cryptocurrency |
| `pay_amount` | decimal(20,8) | Crypto amount |
| `pay_address` | string | Deposit address |
| `paid_at` | datetime | When payment completed |
| `refunded_at` | datetime | When payment refunded |
| `fee` | JSON | Fee breakdown |
| `metadata` | JSON | Additional data |

### Scopes

```php
Payment::successful()->get();
Payment::pending()->get();
Payment::failed()->get();
Payment::forSubscription($subscriptionId)->get();
```

### Methods

```php
$payment->isSuccessful();  // status === 'finished'
$payment->isPending();     // waiting, confirming, partially_paid
$payment->isFailed();      // failed or expired
$payment->isRefunded();    // refunded status or refunded_at set
$payment->syncStatus();    // Sync with NOWPayments API
$payment->refund('reason'); // Mark as refunded locally (API refund via dashboard)
```

## Routes

| Method | URI | Middleware | Purpose |
|--------|-----|------------|---------|
| `GET` | `/checkout` | `web` | Custom overlay view |
| `GET` | `/checkout/embedded` | `web` | Embedded payment widget |
| `POST` | `/checkout/payment` | `web`, `throttle:30,1` | Create payment |
| `GET` | `/checkout/currencies` | `web` | List currencies |
| `POST` | `/checkout/estimate` | `web`, `throttle:60,1` | Get estimate |
| `GET` | `/payment/status/{id}` | `web`, `throttle:30,1`, `auth` | Remote status |
| `GET` | `/payment/local/{id}` | `web`, `throttle:30,1`, `auth` | Local status |

## Events

- `PaymentCreated` — Dispatched on API call (before persist)
- `PaymentReceived` — Dispatched on webhook when status is `finished`
- `PaymentFailed` — Dispatched on webhook when status is `failed` or `expired`
- `PaymentStatusSynced` — Dispatched when `syncStatus()` is called
- `PaymentRefunded` — Dispatched when `refund()` is called

## Configuration

```env
# Payment Method
CASHIER_NOWPAYMENTS_PAYMENT_METHOD=payment       # Default: 'payment' or 'invoice'

# Payment Options
CASHIER_NOWPAYMENTS_FIXED_RATE=false             # Lock exchange rate
CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER=false       # Payer covers fee

# Timeout & Caching
CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT=900          # Countdown seconds (15 min)
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=10      # Remote status cache TTL
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=15             # Local sync cooldown

# Authorization
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH=true     # Auth-gate status endpoints
```

## Embedded Widget vs Custom Checkout

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

### When to Use Embedded Widget

✅ Quick integration needed
✅ Don't want to maintain custom UI
✅ Want latest features automatically
✅ Mobile-first experience important
✅ Reliability is priority

### When to Use Custom Checkout

✅ Need full control over UI/UX
✅ Custom branding required
✅ Need offline support
✅ Want custom payment flows
✅ Need detailed UI analytics

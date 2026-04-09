---
name: nowpayments-one-time-payments
description: Create and manage one-time cryptocurrency payments using the Laravel Cashier NOWPayments package, including direct payments, checkout overlay, guest checkout, and payment status polling.
---

# NOWPayments One-Time Payments

## When to use this skill

Use this skill when:
- Creating direct cryptocurrency payments for authenticated or guest users
- Embedding the checkout overlay UI in Blade views
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

### Direct Payment (Authenticated User)

Use the fluent `PaymentBuilder` via `$user->charge()`:

```php
$payment = $user->charge(49.99, 'usd')
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

### Guest Checkout

Guest payments are handled automatically by the checkout overlay. The `CheckoutController` creates a guest `Customer` record linked by session ID and caches the billable mapping for webhook reconciliation.

## Checkout Overlay

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

## Payment Model

### Key Fields

| Field | Type | Description |
|-------|------|-------------|
| `nowpayments_payment_id` | string | NOWPayments payment ID |
| `nowpayments_purchase_id` | string | NOWPayments purchase ID |
| `status` | string | Payment status (finished, failed, expired, etc.) |
| `amount` | decimal | Fiat amount |
| `amount_paid` | decimal | Amount actually paid |
| `pay_currency` | string | Cryptocurrency |
| `pay_amount` | decimal | Crypto amount |
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
| `GET` | `/checkout` | `web` | Overlay view |
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
CASHIER_NOWPAYMENTS_PAYMENT_METHOD=payment       # Default: 'payment' or 'invoice'
CASHIER_NOWPAYMENTS_FIXED_RATE=false             # Lock exchange rate
CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER=false       # Payer covers fee
CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT=900          # Countdown seconds (15 min)
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=10      # Remote status cache TTL
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=15             # Local sync cooldown
```

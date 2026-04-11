# One-Time Payments

This guide covers every way to accept a single crypto payment through the Laravel Cashier NOWPayments package — from server-side fluent builder calls to a fully client-driven checkout overlay.

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [Direct Payment via Billable Trait](#2-direct-payment-via-billable-trait)
   - [CheckoutService Alternative](#checkoutservice-alternative-recommended)
   - [Embedded Payment Widget](#25-embedded-payment-widget-zero-ui-maintenance)
   - [Processing Fee Auto-Addition](#26-processing-fee-auto-addition)
3. [Guest Checkout via Checkout Overlay](#3-guest-checkout-via-checkout-overlay)
4. [Checkout UI (Blade View)](#4-checkout-ui-blade-view)
5. [JavaScript Module (`CashierCheckout`)](#5-javascript-module-cashiercheckout)
6. [Payment Status Polling](#6-payment-status-polling)
7. [Payment Model Reference](#7-payment-model-reference)
8. [Checkout Button Helper](#8-checkout-button-helper)
9. [Webhook Reconciliation](#9-webhook-reconciliation)
10. [Configuration Reference](#10-configuration-reference)

---

## 1. Architecture Overview

```
┌─────────────────────────────────────────────────────────────────────┐
│                        YOUR LARAVEL APP                             │
│                                                                     │
│  ┌───────────┐    Billable trait    ┌───────────────┐               │
│  │   User     │ ──────────────────► │ PaymentBuilder │               │
│  │  (Model)   │  $user->charge()    │   (fluent)     │               │
│  └───────────┘                     └───────┬───────┘               │
│                                            │                        │
│                    ┌───────────────────────┼────────────────┐       │
│                    │                       │                │       │
│              ┌─────▼──────┐         ┌─────▼──────┐  ┌─────▼─────┐  │
│              │  ->create() │         │  ->charge() │  │ Checkout │  │
│              │  (DTO only) │         │ (persist +  │  │Controller│  │
│              │             │         │  DB tx)     │  └─────┬────┘  │
│              └─────┬──────┘         └─────┬───────┘        │       │
│                    │                      │                │       │
│              ┌─────▼──────┐         ┌─────▼───────┐        │       │
│              │PaymentCreated│        │ Payment     │        │       │
│              │   Event     │        │  (Model)    │        │       │
│              └─────────────┘        └─────────────┘        │       │
│                                                             │       │
└─────────────────────────────────────────────────────────────┼───────┘
                                                              │
                                                    ┌─────────▼────────┐
                                                    │  NOWPayments API  │
                                                    │  (crypto gateway) │
                                                    └──────────────────┘
```

**Two primary paths:**

| Path | Entry Point | Result | Best For |
|------|-------------|--------|----------|
| **Direct (Billable)** | `$user->charge()` | Persisted `Payment` model + API call | Server-initiated charges, admin actions |
| **Checkout Overlay** | GET `/cashier-nowpayments/checkout` | Full UI with currency selection, QR, polling | Customer-facing self-service payments |

---

## 2. Direct Payment via Billable Trait

### CheckoutService Alternative (Recommended)

For a service-oriented approach, use the `CheckoutService` instead of direct builder calls:

```php
use SerenityTechnologies\CashierNowPayments\Facades\Checkout;

// Via Facade
$payment = Checkout::createPayment(49.99, 'usd', 'btc');

// Via Billable trait
$service = $user->checkout();
$payment = $service->createPayment(49.99, 'usd', 'btc');

// With validation (processing fee auto-added if below minimum)
$validation = Checkout::validateAmount(49.99, 'usd', 'btc');
if (!$validation->isValid()) {
    // Fee automatically added to meet minimum
}
```

The CheckoutService provides automatic processing fee calculation, session management, and embedded widget support. See [CheckoutService Documentation](../CHECKOUT_SERVICE.md) for full details.

---

### Prerequisites

Add the `Billable` trait to your `User` model:

```php
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

### Basic Usage

The `charge()` method on the billable model starts a fluent `PaymentBuilder`:

```php
use SerenityTechnologies\CashierNowPayments\Models\Payment;

// Start building a payment
$builder = $user->charge(49.99, 'usd');
```

### Builder Methods

Chain any combination of these methods before finalizing:

| Method | Purpose | Example |
|--------|---------|---------|
| `withPayCurrency(string)` | Force a specific crypto currency | `->withPayCurrency('btc')` |
| `withDescription(string)` | Payment description / order notes | `->withDescription('Premium plan - Annual')` |
| `withOrderId(string)` | Your internal order reference | `->withOrderId('ORD-12345')` |
| `withFixedRate(bool)` | Lock exchange rate for 20 minutes | `->withFixedRate()` |
| `withFeePaidByUser(bool)` | Shift network fee to payer | `->withFeePaidByUser()` |
| `withCredits(bool)` | Auto-apply available credits (FIFO) | `->withCredits()` |
| `withMetadata(array)` | Arbitrary key-value data stored as JSON | `->withMetadata(['plan_id' => 3])` |
| `withRedirectUrl(string)` | Post-payment redirect URL | `->withRedirectUrl('https://app.com/dashboard')` |

### Full Example

```php
$payment = $user->charge(99.00, 'usd')
    ->withPayCurrency('eth')
    ->withDescription('Q2 Consulting Package')
    ->withOrderId('CONSULT-' . $project->id)
    ->withFixedRate()
    ->withFeePaidByUser()
    ->withMetadata([
        'project_id' => $project->id,
        'invoice_ref' => 'INV-2025-042',
    ])
    ->withRedirectUrl(route('dashboard'))
    ->charge(); // <-- persists and returns Payment model

echo $payment->pay_address;   // crypto address to send to
echo $payment->pay_amount;    // exact crypto amount
echo $payment->nowpayments_payment_id;
```

### `->create()` vs `->charge()`

| Aspect | `->create()` | `->charge()` |
|--------|-------------|-------------|
| **NOWPayments API** | Calls the API | Calls the API |
| **Database persist** | No | Yes (wrapped in `DB::transaction`) |
| **Return type** | `PaymentResponse` (DTO) | `Payment` (Eloquent model) |
| **Event dispatch** | Fires `PaymentCreated` | Fires `PaymentCreated` (via `create()`) |
| **Use case** | Read-only / preview, custom persistence logic | Standard flow — one call does everything |

```php
// Using ->create() — returns DTO, nothing saved to DB
$dto = $user->charge(10.00, 'usd')
    ->withDescription('Test')
    ->create();

echo get_class($dto); // SerenityTechnologies\NowPayments\DTOs\Response\PaymentResponse
echo $dto->pay_address;

// Using ->charge() — persists to DB inside a transaction
$payment = $user->charge(10.00, 'usd')
    ->withDescription('Test')
    ->charge();

echo get_class($payment); // SerenityTechnologies\CashierNowPayments\Models\Payment
echo $payment->id; // ULID primary key
```

### Credit Application

When `->withCredits()` is enabled, the builder checks the customer's available credits and consumes them in FIFO order before creating the NOWPayments charge:

```php
$payment = $user->charge(100.00, 'usd')
    ->withCredits()
    ->charge();

// If the user had $30 in credits:
// - $30 is deducted from credits
// - A $70 charge is sent to NOWPayments
// - metadata['credits_applied'] = 30.00
// - metadata['original_amount'] = 100.00
```

### Event: `PaymentCreated`

Every successful payment creation (via both `create()` and `charge()`) dispatches `SerenityTechnologies\CashierNowPayments\Events\PaymentCreated`:

```php
use Illuminate\Support\Facades\Event;
use SerenityTechnologies\CashierNowPayments\Events\PaymentCreated;

Event::listen(function (PaymentCreated $event) {
    $event->billable;          // The User (or other billable model)
    $event->customer;          // The Customer model
    $event->paymentResponse;   // The NOWPayments PaymentResponse DTO
});
```

---

## 2.5 Embedded Payment Widget (Zero UI Maintenance)

For a polished, zero-maintenance payment experience, use the embedded payment widget instead of the custom overlay:

```php
// Via route
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

**Widget features:** Currency selection with 218 currencies and logos, real-time exchange rates, QR code display, payment tracking, countdown timer, auto-redirect on success, and automatic fallback to regular checkout if the widget fails.

See [Embedded Checkout Documentation](../EMBEDDED_CHECKOUT.md) for full details.

---

## 2.6 Processing Fee Auto-Addition

When a payment amount is below the NOWPayments minimum, the system automatically adds the difference as a processing fee:

```php
// Example: $19 order, minimum is $19.33
// System calculates: $19.33 - $19.00 = $0.33 processing fee
// Total charged: $19.33 (includes $0.33 fee)

// Response includes:
{
    "success": true,
    "price_amount": 19.33,
    "processing_fee": "0.33",
    "original_amount": "19",
    "minimum_amount": "19.33"
}
```

The payment description is automatically updated to include the fee notice, and metadata stores `processing_fee_applied: true` for audit purposes.

---

## 3. Guest Checkout via Checkout Overlay

The checkout overlay lets **anyone** (authenticated or not) pay with crypto through a self-contained UI flow.

### Route Map

| Method | Route | Controller Action | Purpose |
|--------|-------|-------------------|---------|
| GET | `/cashier-nowpayments/checkout` | `CheckoutController@show` | Render the Blade checkout view |
| POST | `/cashier-nowpayments/checkout/payment` | `CheckoutController@createPayment` | Create payment (AJAX) |
| POST | `/cashier-nowpayments/checkout/estimate` | `CheckoutController@getEstimate` | Get crypto estimate |
| GET | `/cashier-nowpayments/checkout/currencies` | `CheckoutController@getSupportedCurrencies` | List supported currencies |

### Step 1: Redirect to Checkout

```php
// In a controller or route definition
return redirect()->route('cashier-nowpayments.checkout', [
    'amount' => 100,
    'currency' => 'usd',
    'description' => 'Premium Upgrade',
    'success_url' => route('checkout.success'),
    'cancel_url' => route('checkout.cancel'),
]);
```

Or build a URL manually:

```
/cashier-nowpayments/checkout?amount=100&currency=usd
    &description=Premium%20Upgrade
    &success_url=https%3A%2F%2Fapp.com%2Fsuccess
    &cancel_url=https%3A%2F%2Fapp.com%2Fcancel
```

**Accepted query parameters:**

| Parameter | Type | Required | Notes |
|-----------|------|----------|-------|
| `amount` | numeric | Yes | Must be >= 0.01 |
| `currency` | string | Yes | Fiat currency code (e.g., `usd`, `eur`) |
| `type` | string | No | `payment`, `invoice`, or `subscription` (default: `payment`) |
| `description` | string | No | Max 500 characters |
| `order_id` | string | No | Your internal order reference |
| `metadata` | array | No | Additional data |
| `success_url` | URL | No | Redirect after successful payment (default: `app.url`) |
| `cancel_url` | URL | No | Redirect on cancel (default: `app.url`) |
| `pay_currency` | string | No | Pre-select a crypto currency |

### Step 2: Payment Creation Flow (POST `/checkout/payment`)

When the user selects a currency and clicks **Continue to Payment**, the Blade view sends a POST request. The controller executes this sequence:

```
POST body:
{
    "amount": 100,
    "currency": "usd",
    "pay_currency": "btc",
    "description": "Premium Upgrade",
    "order_id": "ORD-12345"
}
```

**Server-side processing:**

1. **Validation** — Amount, currency, and pay_currency are required.
2. **Order ID generation** — A ULID-based unique suffix is always added:
   - With client order ID: `CLIENT-ORD-12345-{ULID}`
   - Without client order ID: `CHECKOUT-{ULID}`
3. **Idempotency key** — SHA-256 hash of `{user, amount, currency, pay_currency, order_id, session}` cached for 5 minutes. Duplicate requests within the window return the cached response.
4. **Estimate fetch** — Calls NOWPayments API to get the crypto amount.
5. **Minimum amount check** — Uses `bccomp` for precise comparison against NOWPayments minimum.
6. **Billable resolution** — Authenticated user or guest customer (created from session).
7. **Billable mapping cached** — `checkout.billable.{orderId}` stores `{billable_type, billable_id}` for 24 hours. Used by webhooks to reconcile payments to the correct user.
8. **Payment creation** — Uses `PaymentBuilder->charge()` to call API and persist.
9. **QR code URI** — Generated as `crypto:{address}?amount={amount}`.
10. **Response caching** — The full payment response is cached under `checkout.payment.{idempotencyKey}` for 5 minutes.

**Retry logic:**

Transient API failures (connection errors, timeouts, cURL failures) are retried up to **3 times** with exponential backoff (500ms, 1000ms, 2000ms).

### Step 3: Guest Customer Handling

For unauthenticated users, the controller creates or retrieves a `Customer` model keyed by session ID:

```php
// Guest customer identifier
$customerId = 'cashier_nowpayments_guest_' . $sessionId;

// Metadata stored on the customer
[
    'session_key' => $sessionId,
    'source' => 'guest_checkout',
]
```

The billable mapping is cached under `checkout.billable.{orderId}` so that when the webhook fires later, the system can look up the correct billable model even for guest sessions.

### Step 4: Estimate Endpoint (POST `/checkout/estimate`)

```
POST body:
{
    "amount": 100,
    "from_currency": "usd",
    "to_currency": "btc"
}

Response:
{
    "success": true,
    "estimated_amount": 0.00147832,
    "minimum_amount": 0.00010000,
    "fee": 0.00001200
}
```

### Step 5: Currency List (GET `/checkout/currencies`)

```
GET /cashier-nowpayments/checkout/currencies

Response:
{
    "success": true,
    "currencies": ["btc", "eth", "usdttrc20", "usdterc20", "ltc", "trx", ...]
}
```

Results are cached for **1 hour** under the key `nowpayments.currencies.available`.

---

## 4. Checkout UI (Blade View)

The checkout view (`resources/views/checkout.blade.php` in the package) renders a full-screen modal overlay. Here is what it provides:

### Screen Flow

```
┌─────────────────────┐     ┌─────────────────────┐     ┌─────────────────────┐
│  SELECTION SCREEN   │────►│  PAYMENT SCREEN     │────►│  RESULT SCREEN      │
│                     │     │                     │     │                     │
│  [Amount: $100.00]  │     │  Send 0.00148 BTC   │     │  [Success] or       │
│                     │     │                     │     │  [Failed]           │
│  [BTC] [ETH] [USDT] │     │  [QR Code]          │     │                     │
│  [LTC] [TRX] [...]  │     │  [Address + Copy]   │     │  Redirects after 3s │
│                     │     │                     │     │  or Retry button    │
│  Est: 0.00148 BTC   │     │  Timer: 14:59       │     │                     │
│                     │     │  [Polling every 5s] │     │                     │
│  [Continue] [Cancel]│     │                     │     │                     │
└─────────────────────┘     └─────────────────────┘     └─────────────────────┘
```

### Key Features

**Enhanced Currency Selector** (NEW)
- Real-time search input to filter by name, ticker, code, or blockchain
- Rich display with official NOWPayments coin logos (218 currencies downloaded)
- Shows currency name, ticker, blockchain/network, and "Popular" badge
- Smart sorting: popular currencies first, then alphabetical
- Scrollable list with "no results" message when search has no matches

**QR Code Display**
- After selecting a currency, a POST to `/checkout/estimate` returns the crypto amount.
- Displayed as "You will pay: 0.00148 BTC" in a highlighted box.

**Payment Creation**
- The **Continue to Payment** button is disabled until a currency is selected.
- On click, it POSTs to `/checkout/payment` with all collected parameters.
- On failure, an error toast appears for 5 seconds and the button re-enables.
- On failure, a **Retry** button is shown on the failed state screen.

**QR Code**
- Rendered client-side using `qrcode.js` from CDN (`qrcodejs@1.0.0`).
- Encodes a `crypto:` URI: `crypto:{address}?amount={amount}`.
- No external API calls for QR generation.

**Payment Status Polling**
- After payment creation, the view polls `GET /payment/status/{purchaseId}` every **5 seconds**.
- On `completed`: shows success screen, redirects to `success_url` after 3 seconds.
- On `failed` or timeout: shows failed screen with **Try Again** and **Cancel** buttons.

**Countdown Timer**
- Default: **15:00** (900 seconds).
- Configurable via `config('cashier-nowpayments.checkout.payment_timeout_seconds')`.
- The server communicates the timeout value in the payment response, so the frontend timer always matches server expectations.
- On expiry, the payment is marked as failed.

**postMessage Support**

When the checkout is loaded inside an iframe (via the JS modal), it sends `postMessage` events to the parent window:

```javascript
// Parent window listens for events
window.addEventListener('message', function(event) {
    if (event.data.type === 'cashier-checkout-complete') {
        console.log('Payment completed:', event.data.payload);
        // Close modal, update UI, etc.
    }
    if (event.data.type === 'cashier-checkout-cancel') {
        console.log('Checkout was cancelled');
    }
});
```

---

## 5. JavaScript Module (`CashierCheckout`)

Located at `resources/js/cashier-checkout.js`, this module provides a clean API for opening the checkout overlay as a modal iframe or making direct API calls.

### Import

```javascript
// ES Module (via bundler)
import { CashierCheckout } from './cashier-checkout';

// Or access globally when loaded via <script> tag
// window.CashierCheckout
```

### `CashierCheckout.open(options)`

Opens the checkout in a full-screen modal iframe. Returns a `Promise` that resolves with the payment payload or rejects if cancelled.

```javascript
try {
    const result = await CashierCheckout.open({
        amount: 49.99,
        currency: 'usd',
        description: 'Premium Plan',
        order_id: 'ORDER-001',
        success_url: 'https://app.com/success',
        cancel_url: 'https://app.com/cancel',
        pay_currency: 'eth',       // optional — pre-select
        type: 'payment',           // optional — 'payment', 'invoice', 'subscription'
    });

    console.log('Payment completed:', result);
    // result = { payment_id, purchase_id, pay_address, pay_amount, ... }
} catch (error) {
    console.log('User cancelled or closed the checkout');
}
```

**How it works internally:**

1. Builds a URL with query parameters and navigates an iframe to `/cashier-nowpayments/checkout?...`.
2. Creates a modal overlay with the iframe and a close button.
3. Listens for `postMessage` events from the iframe:
   - `cashier-checkout-complete` → resolves the promise, closes modal.
   - `cashier-checkout-cancel` → rejects the promise, closes modal.

### `CashierCheckout.createPayment(options)`

Direct API call to create a payment without opening the UI.

```javascript
const result = await CashierCheckout.createPayment({
    amount: 100,
    currency: 'usd',
    pay_currency: 'btc',
    description: 'API-only payment',
});

if (result.success) {
    console.log('Pay to:', result.pay_address, result.pay_amount, result.pay_currency);
}
```

### `CashierCheckout.getEstimate(amount, fromCurrency, toCurrency)`

Get the estimated crypto amount for a fiat payment.

```javascript
const estimate = await CashierCheckout.getEstimate(100, 'usd', 'btc');
// { success: true, estimated_amount: 0.00147832, minimum_amount: 0.0001, fee: ... }
```

### `CashierCheckout.getCurrencies()`

Fetch the list of supported crypto currencies.

```javascript
const response = await CashierCheckout.getCurrencies();
// { success: true, currencies: ['btc', 'eth', 'usdttrc20', ...] }
```

### `CashierCheckout.checkStatus(purchaseId)`

Poll the remote payment status.

```javascript
const status = await CashierCheckout.checkStatus('nowpayments-purchase-id');
// { success: true, status: 'completed', actually_paid: 0.00148, ... }
```

---

## 6. Payment Status Polling

Two endpoints are available for checking payment status. Both are rate-limited (30 req/min) and support configurable authentication.

### Remote Status — `GET /payment/status/{purchaseId}`

Queries the NOWPayments API directly, with a short-lived cache (default: **10 seconds**) to prevent excessive API calls during polling.

```
GET /cashier-nowpayments/payment/status/{purchaseId}

Response:
{
    "success": true,
    "status": "completed",        // normalized: completed, failed, refunded, partial, pending
    "payment_status": "finished", // raw NOWPayments status
    "actually_paid": 0.00148,
    "pay_amount": 0.00148,
    "pay_address": "bc1q...",
    "pay_currency": "btc"
}
```

**Auth & Ownership Verification:**

When `payment_status.auth.enabled` is `true` (default), the endpoint:
1. Requires an authenticated user.
2. Verifies the user owns the payment by checking if a `Payment` record exists with matching `billable_type` + `billable_id` and the given `purchaseId`.

```
GET /payment/status/{purchaseId}
→ 401 if unauthenticated
→ 403 if user does not own the payment
```

### Local Status — `GET /payment/local/{paymentId}`

Checks the locally persisted `Payment` model. If the payment is still pending, it syncs with NOWPayments — but only if the last sync was longer than the cooldown period (default: **15 seconds**).

```
GET /cashier-nowpayments/payment/local/{paymentId}

Response:
{
    "success": true,
    "status": "completed",
    "payment_status": "finished",
    "amount": 100.00,
    "amount_paid": 100.00,
    "pay_currency": "btc",
    "pay_amount": 0.00148
}
```

**Sync Cooldown Logic:**

```
if payment.isPending():
    lastSync = payment.metadata['last_status_sync']
    if lastSync is null OR now - lastSync > cooldown_seconds (15):
        payment.syncStatus()
        payment.metadata['last_status_sync'] = now
```

This prevents multiple concurrent pollers from hammering the NOWPayments API for the same payment.

---

## 7. Payment Model Reference

**Class:** `SerenityTechnologies\CashierNowPayments\Models\Payment`

**Table:** `{prefix}payments` (default: `cashier_nowpayments_payments`)

**Primary Key:** ULID

### Database Columns

| Column | Type | Notes |
|--------|------|-------|
| `id` | ULID | Primary key |
| `customer_id` | ULID | FK to customers table |
| `billable_type` | string | Morph type (e.g., `App\Models\User`) |
| `billable_id` | ULID | Morph ID |
| `subscription_id` | ULID | Nullable — linked subscription |
| `nowpayments_payment_id` | string | NOWPayments internal ID |
| `nowpayments_purchase_id` | string (unique) | The purchase identifier |
| `parent_payment_id` | string | Nullable — for recurring/child payments |
| `type` | string | Default: `onetime` |
| `status` | string | Raw NOWPayments status (`waiting`, `finished`, `failed`, etc.) |
| `currency` | string | Fiat currency (e.g., `usd`) |
| `amount` | decimal(20,8) | Original fiat amount |
| `amount_paid` | decimal(20,8) | Actually paid amount |
| `pay_currency` | string | Crypto currency (e.g., `btc`) |
| `pay_amount` | decimal(20,8) | Crypto amount to send |
| `pay_address` | string | Destination wallet address |
| `order_id` | string | Your order reference |
| `order_description` | text | Payment description |
| `payin_hash` | string | Blockchain transaction hash (incoming) |
| `payout_hash` | string | Blockchain transaction hash (outgoing) |
| `fee` | JSON | Fee breakdown from NOWPayments |
| `metadata` | JSON | Custom data + system metadata |
| `paid_at` | timestamp | When payment was marked as finished |
| `refunded_at` | timestamp | When payment was refunded |
| `created_at`, `updated_at` | timestamp | Laravel timestamps |

### Scopes

```php
// Successful payments (status = 'finished')
Payment::successful()->get();

// Pending payments (waiting, confirming, confirmed, sending, partially_paid)
Payment::pending()->get();

// Failed payments (failed, expired)
Payment::failed()->get();

// Payments for a specific subscription
Payment::forSubscription($subscriptionId)->get();
```

### Instance Methods

```php
$payment->isSuccessful();  // bool — status === 'finished'
$payment->isPending();     // bool — status in [waiting, confirming, confirmed, sending, partially_paid]
$payment->isFailed();      // bool — status in [failed, expired]
$payment->isRefunded();    // bool — status === 'refunded' OR refunded_at is set

// Sync status from NOWPayments API
$payment->syncStatus();    // returns self, dispatches PaymentStatusSynced event

// Refund (marks locally — actual refund must be done via NOWPayments dashboard)
$payment->refund('Customer requested refund');  // returns self, dispatches PaymentRefunded event
```

### Relationships

```php
$payment->billable;     // MorphTo — the owning User (or other billable model)
$payment->customer;     // BelongsTo — the Customer record
$payment->subscription; // BelongsTo — linked subscription (if any)
```

---

## 8. Checkout Button Helper

Generate a pre-configured checkout button that redirects to the overlay:

### Via Billable Trait

```php
// In a Blade view
{!! $user->checkoutButton(49.99, 'usd', [
    'text' => 'Pay Now',
    'class' => 'btn btn-primary',
    'success_url' => route('checkout.success'),
    'cancel_url' => route('checkout.cancel'),
]) !!}
```

**Rendered HTML:**

```html
<a href="/cashier-nowpayments/checkout?amount=49.99&currency=usd"
   class="btn btn-primary"
   data-cashier-nowpayments
   data-config='{"amount":49.99,"currency":"usd","success_url":"...","cancel_url":"..."}'>
    Pay Now
</a>
```

### Available Options

| Option | Default | Description |
|--------|---------|-------------|
| `text` | `"Pay with Crypto"` | Button label |
| `class` | `"cashier-nowpayments-checkout-btn"` | CSS class |
| `success_url` | `config('app.url')` | Redirect on success |
| `cancel_url` | `config('app.url')` | Redirect on cancel |
| `description` | — | Payment description |
| `order_id` | — | Internal order reference |
| `pay_currency` | — | Pre-select crypto |
| `type` | — | `payment`, `invoice`, or `subscription` |

### Checkout URL Helper

If you only need the URL (for custom buttons or redirects):

```php
$url = $user->checkoutUrl(99.00, 'usd', [
    'description' => 'Annual Plan',
    'success_url' => route('checkout.success'),
]);
```

---

## 9. Webhook Reconciliation

When a payment is completed on NOWPayments, a webhook (IPN callback) is sent to your application. The webhook controller uses the cached billable mapping to associate the payment with the correct user:

```
NOWPayments → POST /nowpayments/webhook
    → Looks up checkout.billable.{orderId} in cache
    → Finds {billable_type, billable_id}
    → Creates or updates the Payment record
    → Dispatches PaymentReceived or PaymentFailed event
```

The billable mapping cache (`checkout.billable.{orderId}`) has a **24-hour TTL**, giving ample time for the webhook to arrive even if the user closes their browser before the payment completes.

---

## 10. Configuration Reference

All settings are in `config/cashier-nowpayments.php`:

```php
// Payment timeout for the checkout overlay timer
'checkout' => [
    'payment_timeout_seconds' => env('CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT', 900), // 15 minutes

    // Minimum seconds between API sync calls for a single pending payment
    'sync_cooldown_seconds' => env('CASHIER_NOWPAYMENTS_SYNC_COOLDOWN', 15),
],

// Payment status endpoint authorization
'payment_status' => [
    'auth' => [
        'enabled' => env('CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH', true),
        'guard'   => env('CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD', 'web'),
    ],
    // Remote status polling cache duration
    'cache_seconds' => env('CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS', 10),
],

// Fixed rate (lock exchange for 20 min) and fee settings
'fixed_rate'       => env('CASHIER_NOWPAYMENTS_FIXED_RATE', false),
'fee_paid_by_user' => env('CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER', false),

// Default crypto currency when none is specified
'currency' => env('CASHIER_NOWPAYMENTS_CURRENCY', 'usd'),
```

### Environment Variables

```env
CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT=900
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=15
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH=true
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD=web
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=10
CASHIER_NOWPAYMENTS_FIXED_RATE=false
CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER=false
CASHIER_NOWPAYMENTS_CURRENCY=usd
```

---

## Common Patterns

### Pattern 1: Server-Initiated Charge with Redirect

```php
// Admin or system charges a user, then redirects to payment page
$payment = $user->charge(25.00, 'usd')
    ->withDescription('Account top-up')
    ->withOrderId('TOPUP-' . time())
    ->charge();

// Redirect user to complete payment
return redirect()->route('cashier-nowpayments.checkout', [
    'amount' => $payment->amount,
    'currency' => $payment->currency,
    'pay_currency' => $payment->pay_currency,
]);
```

### Pattern 2: Custom Payment Flow with JavaScript Modal

```blade
{{-- In your Blade view --}}
<button id="payBtn" class="btn btn-primary">Pay $49.99</button>

<script type="module">
import { CashierCheckout } from '/js/cashier-checkout.js';

document.getElementById('payBtn').addEventListener('click', async () => {
    try {
        const result = await CashierCheckout.open({
            amount: 49.99,
            currency: 'usd',
            description: 'Premium Plan',
            success_url: '{{ route("dashboard") }}',
            cancel_url: '{{ route("pricing") }}',
        });
        console.log('Payment complete:', result);
    } catch (e) {
        console.log('Cancelled');
    }
});
</script>
```

### Pattern 3: Query Payment History

```php
// All payments for a user
$payments = $user->payments()->latest()->get();

// Only successful payments
$successful = $user->payments()->successful()->get();

// Remote payment history from NOWPayments API
$history = $user->remotePayments([
    'limit' => 20,
    'payment_status' => 'finished',
]);

// Crypto estimate
$estimate = $user->estimateCrypto(100.00, 'usd', 'btc');
```

### Pattern 4: Sync and Refund

```php
$payment = Payment::where('nowpayments_purchase_id', $purchaseId)->firstOrFail();

// Sync latest status from NOWPayments
$payment->syncStatus();

if ($payment->isSuccessful()) {
    // Mark as refunded locally (actual refund via NOWPayments dashboard)
    $payment->refund('Duplicate charge');
}
```

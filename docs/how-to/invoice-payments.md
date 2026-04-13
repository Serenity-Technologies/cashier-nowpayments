# Invoice Payments

Invoice payments redirect users to NOWPayments' hosted checkout page. This flow is ideal for one-off payments where you want a polished, self-contained checkout experience with success and cancel redirects.

---

## Table of Contents

- [Creating Invoices via Billable Trait](#creating-invoices-via-billable-trait)
- [Creating Invoices via Checkout Controller](#creating-invoices-via-checkout-controller)
- [Invoice Model](#invoice-model)
- [Paying an Invoice](#paying-an-invoice)
- [Invoice Webhook Handling](#invoice-webhook-handling)
- [Invoices vs Direct Payments](#invoices-vs-direct-payments)
- [Guest Invoice Flow](#guest-invoice-flow)

---

## Creating Invoices via Billable Trait

The `invoice()` method on any billable model returns an `InvoiceBuilder` for fluent invoice creation.

### Basic Usage

```php
use App\Models\User;

$user = User::find(1);

$invoice = $user->invoice(49.99, 'USD')->generate();

// Redirect the user to the hosted checkout page
return $invoice->redirect();
```

### Builder Methods

| Method | Description |
|---|---|
| `withDescription(string $description)` | Sets the order description shown on the hosted page |
| `withOrderId(string $orderId)` | Sets your internal order reference (auto-generated if omitted) |
| `withSuccessUrl(string $url)` | URL the user is redirected to after successful payment |
| `withCancelUrl(string $url)` | URL the user is redirected to if they cancel |
| `withMetadata(array $metadata)` | Arbitrary key-value data stored with the invoice |
| `withFixedRate(bool $fixed = true)` | Locks the exchange rate at invoice creation time |

### `create()` vs `generate()`

There are two terminal methods on the builder, and they serve different purposes:

**`create()`** — API-only. Creates the invoice on NOWPayments and dispatches the `InvoiceCreated` event. Returns an `InvoiceResponse` DTO. No local database record is persisted.

```php
use SerenityTechnologies\NowPayments\DTOs\Response\InvoiceResponse;

$response = $user->invoice(49.99, 'USD')
    ->withDescription('Premium Widget')
    ->withOrderId('ORD-12345')
    ->withSuccessUrl('https://example.com/payment/success')
    ->withCancelUrl('https://example.com/payment/cancel')
    ->withFixedRate()
    ->create(); // returns InvoiceResponse

$invoiceUrl = $response->invoice_url;
```

**`generate()`** — API + persist. Calls `create()` internally, then stores an `Invoice` model in your database. Returns the persisted `Invoice` model.

```php
use SerenityTechnologies\CashierNowPayments\Models\Invoice;

$invoice = $user->invoice(49.99, 'USD')
    ->withDescription('Premium Widget')
    ->withOrderId('ORD-12345')
    ->withSuccessUrl('https://example.com/payment/success')
    ->withCancelUrl('https://example.com/payment/cancel')
    ->withMetadata(['campaign' => 'summer-sale'])
    ->withFixedRate()
    ->generate(); // returns Invoice model

$invoiceUrl = $invoice->invoice_url;
$invoiceId = $invoice->id; // local ULID
```

### When to Use Which

- Use **`create()`** when you only need the `invoice_url` for an immediate redirect and do not need to track the invoice locally. The `InvoiceCreated` event is still dispatched if you need to react asynchronously.
- Use **`generate()`** when you want a local record for reconciliation, reporting, or webhook handling. This is the recommended approach for production applications.

### Listing Invoices

```php
// All invoices for a billable model
$invoices = $user->invoices()->get();

// Only unpaid invoices
$pendingInvoices = $user->invoices()->where('status', 'active')->get();
```

---

## Creating Invoices via Checkout Controller

The package exposes a ready-made HTTP endpoint for creating invoices from the frontend:

```
POST /cashier-nowpayments/checkout/invoice
```

### Request

| Field | Required | Type | Description |
|---|---|---|---|
| `amount` | Yes | number | Invoice amount (e.g. `49.99`) |
| `currency` | Yes | string | Fiat currency code (e.g. `USD`) |
| `success_url` | **Yes** | URL | Redirect after successful payment |
| `cancel_url` | **Yes** | URL | Redirect after payment cancellation |
| `description` | No | string | Order description (max 500 chars) |
| `order_id` | No | string | Your internal order reference |

### Example Request

```javascript
const response = await fetch('/cashier-nowpayments/checkout/invoice', {
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
    },
    body: JSON.stringify({
        amount: 49.99,
        currency: 'USD',
        description: 'Premium Widget',
        success_url: 'https://example.com/payment/success',
        cancel_url: 'https://example.com/payment/cancel',
    }),
});

const data = await response.json();

if (data.success) {
    // Redirect user to the hosted checkout page
    window.location.href = data.invoice_url;
}
```

### Response

**JSON response** (when the request expects JSON):

```json
{
    "success": true,
    "invoice_url": "https://nowpayments.io/payment/?iid=abc123",
    "invoice_id": "01HQ..."
}
```

**Redirect response** (standard web requests): the controller issues an HTTP redirect directly to `invoice_url`.

### Guest Support

This endpoint works for **both authenticated and guest users**:

- **Authenticated users**: the invoice is linked to the user's billable model and customer record.
- **Guest users**: a guest `Customer` is created (or reused) based on the session ID, and the invoice is linked to it. The invoice is still persisted locally and will be reconciled via webhooks when payment completes.

### Retry Logic

The controller wraps the NOWPayments API call in a retry handler:

- **Maximum attempts**: 3
- **Backoff strategy**: exponential — 500ms, then 1000ms
- **Retried errors**: transient errors only (connection failures, timeouts, cURL errors, stream errors)
- Non-transient errors (validation, authentication) are thrown immediately

### Route Configuration

The route is registered as:

```php
Route::post('/checkout/invoice', [CheckoutController::class, 'createInvoice'])
    ->middleware(['throttle:20,1'])
    ->name('checkout.invoice');
```

Rate-limited to 20 requests per minute. The base path and middleware are configurable via `config('cashier-nowpayments.routes')`.

---

## Invoice Model

The `Invoice` model (`SerenityTechnologies\CashierNowPayments\Models\Invoice`) represents a persisted invoice.

### Table Schema

| Column | Type | Description |
|---|---|---|
| `id` | ULID (primary key) | Local invoice identifier |
| `customer_id` | ULID (nullable, FK) | Owning customer |
| `billable_id` / `billable_type` | ULID polymorphic | The billable model (e.g. `User`) |
| `nowpayments_invoice_id` | string (unique) | NOWPayments invoice ID |
| `status` | string (indexed) | `active`, `paid`, `finished`, `failed`, `expired` |
| `currency` | string | Price currency (e.g. `USD`) |
| `amount` | decimal | Invoice amount |
| `amount_paid` | decimal | Actually paid amount |
| `order_id` | string (indexed) | Your internal order reference |
| `order_description` | text (nullable) | Description |
| `invoice_url` | string (nullable) | Hosted checkout URL |
| `success_url` | string (nullable) | Redirect URL on success |
| `cancel_url` | string (nullable) | Redirect URL on cancel |
| `metadata` | JSON (nullable) | Arbitrary data |
| `paid_at` | timestamp (nullable) | When payment was completed |
| `expires_at` | timestamp (nullable) | When the invoice expires |
| `created_at` / `updated_at` | timestamps | Standard Laravel timestamps |

### Status Helper Methods

```php
$invoice->isPaid();   // true when status is 'paid' OR 'finished'
$invoice->isActive(); // true when status is 'active'
$invoice->isExpired(); // true when status is 'expired' OR expires_at is in the past
```

> **Note**: NOWPayments transitions invoices through `paid` (payment detected) and then `finished` (payment confirmed on-chain). `isPaid()` checks both to cover the full lifecycle.

### Redirect

```php
return $invoice->redirect();
// Equivalent to: return redirect($invoice->invoice_url);
```

### Relationships

```php
$invoice->customer();  // BelongsTo — the owning Customer
$invoice->billable();  // MorphTo — the billable model (e.g. User)
$invoice->payments();  // HasMany — payments linked via order_id
```

The `payments()` relationship joins on `order_id`, so any payment made against the same order reference will appear here. This is useful for tracking partial or multiple payments against an invoice.

---

## Embedded Widget for Invoice Payments

For a polished, zero-maintenance payment experience, use the embedded payment widget instead of redirecting to NOWPayments' hosted page.

### Via Route

```php
return redirect()->route('cashier-nowpayments.checkout.embedded', [
    'amount' => $invoice->amount,
    'currency' => $invoice->currency,
    'description' => 'Invoice #' . $invoice->id,
    'order_id' => 'INV-' . $invoice->id,
    'success_url' => route('invoices.show', $invoice),
    'cancel_url' => route('invoices.show', $invoice),
    'metadata' => [
        'invoice_id' => $invoice->id,
    ],
]);
```

### Via Billable Trait

```php
$url = $user->embeddedCheckoutUrl($invoice->amount, $invoice->currency, [
    'description' => 'Invoice #' . $invoice->id,
    'order_id' => 'INV-' . $invoice->id,
    'success_url' => route('invoices.show', $invoice),
    'cancel_url' => route('invoices.show', $invoice),
    'metadata' => ['invoice_id' => $invoice->id],
]);

return redirect($url);
```

### Widget Flow

1. Creates invoice on NOWPayments API
2. Displays widget in beautiful modal overlay
3. User selects crypto, sees QR code, completes payment
4. Auto-redirects to `success_url` after payment
5. Webhook updates invoice status automatically
6. `InvoicePaid` event dispatched

### Widget Handles

- ✅ Currency selection with 218 currencies and logos
- ✅ Real-time exchange rates
- ✅ QR code generation
- ✅ Payment address display
- ✅ Countdown timer
- ✅ Payment status tracking
- ✅ Success/cancel redirects
- ✅ Fallback to regular checkout if widget fails

---

## Complete Invoice Flow Example

```php
// 1. Create invoice
$invoice = $user->invoice(49.99, 'usd')
    ->withDescription('Premium Plan')
    ->withOrderId('INV-123')
    ->withSuccessUrl(route('payment.success'))
    ->withCancelUrl(route('cart'))
    ->generate();

// 2. Store in session for tracking
session(['invoice_id' => $invoice->id]);

// 3. Redirect to embedded widget (recommended)
return redirect()->route('cashier-nowpayments.checkout.embedded', [
    'amount' => $invoice->amount,
    'currency' => $invoice->currency,
    'description' => $invoice->order_description,
    'order_id' => $invoice->order_id,
    'success_url' => $invoice->success_url,
    'cancel_url' => $invoice->cancel_url,
]);

// Alternative: Redirect to hosted page
// return $invoice->redirect();

// 4. Webhook updates invoice status automatically
// 5. InvoicePaid event dispatched
// 6. Listener grants access to premium features
```

---

## Paying an Invoice

The `payInvoice()` method creates a **payment** on an existing invoice. This is used when a user wants to pay an invoice with a specific cryptocurrency.

```php
use SerenityTechnologies\CashierNowPayments\Models\Invoice;

$invoice = $user->invoices()->where('status', 'active')->first();

$payment = $user->payInvoice(
    invoice: $invoice,
    payCurrency: 'btc',
    payoutAddress: 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh' // optional
);
```

### Parameters

| Parameter | Type | Required | Description |
|---|---|---|---|
| `$invoice` | `Invoice` | Yes | The invoice to pay |
| `$payCurrency` | `string` | Yes | Cryptocurrency to pay with (e.g. `btc`, `eth`, `ltc`) |
| `$payoutAddress` | `?string` | No | Address for refunds |

### What Happens

1. Calls `NOWPayments::createInvoicePayment()` with the invoice ID and chosen currency.
2. Creates a local `Payment` record linked to the invoice's customer.
3. The payment is recorded with `type` set to `'invoice'` (distinguishing it from one-time payments).
4. Returns the created `Payment` model with all payment details (address, amount, QR code data, etc.).

### Returned Payment Fields

The returned `Payment` model includes:

- `pay_address` — the crypto address to send funds to
- `pay_amount` — the exact crypto amount required
- `pay_currency` — the cryptocurrency (e.g. `btc`)
- `order_id` — the order reference
- `status` — initial payment status (typically `waiting`)

You can use these to display a payment screen or generate a QR code.

---

## Paying an Invoice with Crypto

The "Create payment by invoice" flow allows you to generate a crypto payment address for an existing invoice. This is useful when you want the customer to select their preferred cryptocurrency after the invoice has been created.

### Flow Overview

1. **Create invoice** — Via `InvoiceBuilder`, `CheckoutService::createInvoice()`, or the API
2. **Customer selects crypto** — Choose which cryptocurrency to pay with
3. **Call payInvoice** — Generates deposit address + QR code via NOWPayments
4. **Display payment details** — Show address, amount, and QR code to customer
5. **Monitor payment** — Via webhooks or status polling

### Via Invoice Model

```php
$invoice = Invoice::findOrFail($invoiceId);

// Generate crypto payment address
$paymentResponse = $invoice->pay('btc');

echo $paymentResponse->pay_address;  // BTC deposit address
echo $paymentResponse->pay_amount;   // Amount in BTC
echo $paymentResponse->payment_id;   // NOWPayments payment ID
```

### Via CheckoutService

```php
use SerenityTechnologies\CashierNowPayments\Facades\Checkout;

$invoice = Invoice::findOrFail($invoiceId);

$payment = Checkout::payInvoice($invoice, 'btc');

echo $payment->getPayAddress();    // BTC deposit address
echo $payment->getPayAmount();     // Amount in BTC
echo $payment->getQrCodeUri();     // crypto:bc1q...?amount=0.00123
```

### Via Billable Trait Helper

```php
// Generate URL to pay an existing invoice
$url = $user->payInvoiceUrl($invoice, [
    'success_url' => route('payment.success'),
    'cancel_url' => route('payment.cancel'),
]);

// Or via direct route
return redirect()->route('cashier-nowpayments.checkout', [
    'amount' => $invoice->amount,
    'currency' => $invoice->currency,
    'invoice_id' => $invoice->id,
    'type' => 'invoice_payment',
    'description' => $invoice->order_description,
]);
```

### Via API Endpoint

```
POST /cashier-nowpayments/checkout/invoice/{invoiceId}/pay

{
    "pay_currency": "btc",
    "payout_address": "bc1q..."  // optional, for refunds
}
```

Response:
```json
{
    "success": true,
    "payment_id": "12345",
    "purchase_id": "67890",
    "pay_address": "bc1qxy2...",
    "pay_amount": "0.00123",
    "pay_currency": "btc",
    "price_amount": "49.99",
    "price_currency": "usd",
    "qr_code": "crypto:bc1qxy2...?amount=0.00123",
    "local_payment_id": 123,
    "timeout_seconds": 900
}
```

### Via JavaScript Module

```javascript
import { CashierCheckout } from './cashier-checkout';

// First create an invoice
const invoice = await CashierCheckout.createInvoice({
    amount: 49.99,
    currency: 'usd',
    description: 'Order #123',
    success_url: 'https://yoursite.com/success',
    cancel_url: 'https://yoursite.com/cancel',
});

// Then pay it with selected crypto
const payment = await CashierCheckout.payInvoice(invoice.invoice_id, {
    pay_currency: 'btc',
});

console.log(payment.pay_address);  // BTC address
console.log(payment.pay_amount);   // Amount in BTC
console.log(payment.qr_code);      // QR code URI
```

### Invoice Payment URL Helper

Add the `payInvoiceUrl()` method to your billable model via the `Billable` trait:

```php
// In a controller
public function payInvoice(Invoice $invoice)
{
    return redirect($invoice->payInvoiceUrl());
}

// Or in Blade
<a href="{{ $user->payInvoiceUrl($invoice) }}">
    Pay Invoice #{{ $invoice->order_id }}
</a>
```

### Validation

The invoice payment endpoint validates:
- Invoice exists and is active (`status = 'active'`)
- Amount meets minimum payment requirement for selected crypto
- Ownership verification (if user is authenticated)

Returns appropriate error responses:
- `404` — Invoice not found
- `403` — Access denied (ownership mismatch)
- `422` — Invoice not active or amount below minimum

---

## Invoice Webhook Handling

When a payment event occurs on NOWPayments, an IPN (Instant Payment Notification) is sent to the configured webhook URL. The `WebhookController` processes it as follows.

### Route

The webhook URL is auto-generated via the `GeneratesWebhookUrl` trait and typically resolves to:

```
POST /cashier-nowpayments/webhook
```

This URL is passed to NOWPayments as `ipnCallbackUrl` when creating invoices.

### Invoice Webhook Processing (`handleInvoice()`)

1. **Lookup** — Finds the local `Invoice` by `nowpayments_invoice_id` (the `invoice_id` field in the webhook payload).

2. **Update** — Updates the invoice's `status` and `amount_paid` from the payload.

3. **Events** — Dispatches events based on the payment status:

   | NOWPayments `payment_status` | Event Dispatched |
   |---|---|
   | `finished` | `InvoicePaid` (sets `paid_at`) |
   | `failed` | `InvoicePaymentFailed` |
   | `expired` | `InvoicePaymentFailed` |

4. **Invoice Not Found** — If no local invoice exists (e.g., created via the NOWPayments dashboard or a different system), a warning is logged via `report()` and processing stops. No exception is thrown to avoid retry loops.

### Listening to Events

```php
// In your EventServiceProvider

protected $listen = [
    \SerenityTechnologies\CashierNowPayments\Events\InvoicePaid::class => [
        SendInvoiceConfirmationEmail::class,
        FulfillOrder::class,
    ],
    \SerenityTechnologies\CashierNowPayments\Events\InvoicePaymentFailed::class => [
        NotifyUserOfFailedPayment::class,
    ],
];
```

Event payloads provide the `Invoice` model and the raw NOWPayments payload:

```php
use SerenityTechnologies\CashierNowPayments\Events\InvoicePaid;

class FulfillOrder
{
    public function handle(InvoicePaid $event): void
    {
        $invoice = $event->invoice;
        $payload = $event->nowpaymentsPayload;

        // $invoice->billable — the User or other billable model
        // $payload — raw array from NOWPayments
    }
}
```

### Security

The webhook controller performs dual-layer verification:

- **HMAC signature verification** — validates the `x-nowpayments-sig` header against the configured `ipn_secret`.
- **Timestamp validation** — rejects webhooks with a `created_at` outside the configured tolerance (default 300 seconds).

---

## Invoices vs Direct Payments

Choosing between the invoice flow and the direct payment flow depends on your use case.

### Use Invoices When

- You want a **hosted checkout page** managed by NOWPayments.
- You need **success/cancel redirect URLs** for post-payment navigation.
- The payment is a **one-off** charge (e.g., purchasing a product, paying an invoice).
- You want a **better UX** with a full-page checkout that includes QR codes, payment instructions, and status tracking.
- You want the user to be able to **choose their cryptocurrency** on the hosted page.

### Use Direct Payments When

- You want an **embedded checkout overlay** (the `checkout` view with polling).
- You need **fine-grained control** over the payment UI and flow.
- You are building **recurring or programmatic payment flows** where redirects would be disruptive.
- You need to **pre-select the payment currency** and show payment details inline.
- You want to poll for payment status without leaving your application.

### Side-by-Side Comparison

| Feature | Invoice | Direct Payment |
|---|---|---|
| Checkout UI | Hosted by NOWPayments | Embedded overlay |
| Redirects | success_url / cancel_url | No redirects (polling-based) |
| Currency selection | User chooses on hosted page | Specified by application |
| Guest support | Yes (session-based customer) | Yes (session-based customer) |
| Local persistence | Via `generate()` or checkout controller | Via PaymentBuilder |
| Best for | One-off payments | Embedded / recurring flows |

---

## Guest Invoice Flow

Guest invoices allow unauthenticated users to pay via the hosted checkout while still maintaining local records for webhook reconciliation.

### Sequence Diagram

```
┌──────────┐     ┌──────────────────┐     ┌─────────────────┐     ┌──────────────┐
│  Guest   │     │  CheckoutCtrl    │     │  NOWPayments    │     │   Database   │
│  User    │     │                  │     │                 │     │              │
└────┬─────┘     └────────┬─────────┘     └────────┬────────┘     └──────┬───────┘
     │                    │                        │                     │
     │  POST /checkout/   │                        │                     │
     │  invoice           │                        │                     │
     │  {amount, currency,│                        │                     │
     │   success_url,     │                        │                     │
     │   cancel_url}      │                        │                     │
     │───────────────────>│                        │                     │
     │                    │                        │                     │
     │                    │ Get/Create Guest       │                     │
     │                    │ Customer (by session)  │                     │
     │                    │───────────────────────>│                     │
     │                    │                        │                     │
     │                    │ createInvoice()        │                     │
     │                    │ (with ipnCallbackUrl)  │                     │
     │                    │───────────────────────>│                     │
     │                    │                        │                     │
     │                    │     invoice_url        │                     │
     │                    │<───────────────────────│                     │
     │                    │                        │                     │
     │                    │ Persist Invoice        │                     │
     │                    │ (customer_id, status,  │                     │
     │                    │  invoice_url, etc.)    │                     │
     │                    │─────────────────────────────────────────────>│
     │                    │                        │                     │
     │  JSON: {           │                        │                     │
     │   success: true,   │                        │                     │
     │   invoice_url: ... │                        │                     │
     │  } OR redirect     │                        │                     │
     │<───────────────────│                        │                     │
     │                    │                        │                     │
     │  Browse to         │                        │                     │
     │  invoice_url       │                        │                     │
     │────────────────────────────────────────────>│                     │
     │                    │                        │                     │
     │  [Selects crypto,  │                        │                     │
     │   sends payment]   │                        │                     │
     │                    │                        │                     │
     │                    │                        │  IPN webhook        │
     │                    │                        │  {invoice_id,       │
     │                    │                        │   payment_status,   │
     │                    │                        │   actually_paid}    │
     │                    │                        │────────────────────>│
     │                    │                        │                     │
     │                    │                        │   Find invoice by   │
     │                    │                        │   nowpayments_      │
     │                    │                        │   invoice_id        │
     │                    │                        │                     │
     │                    │                        │   Update status +   │
     │                    │                        │   amount_paid       │
     │                    │                        │                     │
     │                    │                        │   Dispatch          │
     │                    │                        │   InvoicePaid       │
     │                    │                        │   (if 'finished')   │
     │                    │                        │                     │
```

### Step-by-Step Breakdown

1. **Guest arrives** at the checkout page without authentication.
2. **POST to `/checkout/invoice`** with amount, currency, `success_url`, and `cancel_url`.
3. **`getOrCreateGuestCustomer()`** — The controller checks for an existing Customer with the current session ID in its metadata. If none exists, it creates one with:
   - `nowpayments_customer_id`: `{prefix}guest_{session_id}`
   - `name`: `"Guest User"`
   - `metadata`: `{ session_key, source: "guest_checkout" }`
4. **`createInvoice()`** — Calls NOWPayments API with the IPN callback URL auto-configured.
5. **`persistInvoice()`** — Stores the invoice locally with `customer_id` pointing to the guest customer, `billable_id`/`billable_type` as `null` (since there is no authenticated billable model).
6. **Response** — Returns JSON with `invoice_url` for AJAX consumers, or issues a redirect for standard web requests.
7. **User pays** on the hosted NOWPayments page.
8. **NOWPayments sends IPN** to the webhook URL.
9. **`handleInvoice()`** finds the invoice by `nowpayments_invoice_id`, updates `status` and `amount_paid`, and dispatches `InvoicePaid` if the status is `finished`.

### Reconciling Guest Invoices to Users

If a guest later creates an account or logs in, you can reconcile their invoices by matching on email, `order_id`, or metadata:

```php
// Example: reconcile by order_id stored in session
$guestCustomer = Customer::whereJsonContains('metadata->session_key', session()->getId())->first();

$guestInvoices = $guestCustomer?->invoices()->whereNull('billable_id')->get();

foreach ($guestInvoices as $invoice) {
    $invoice->update([
        'billable_id' => $user->id,
        'billable_type' => $user->getMorphClass(),
    ]);
}
```

---

## Quick Reference

```php
// Create and redirect (authenticated)
$invoice = $user->invoice(99.00, 'USD')
    ->withDescription('Order #12345')
    ->withSuccessUrl(url('/payment/success'))
    ->withCancelUrl(url('/payment/cancel'))
    ->generate();

return $invoice->redirect();

// Create via AJAX (guest or authenticated)
// POST /cashier-nowpayments/checkout/invoice
// → returns { success: true, invoice_url: "..." }

// Check if an invoice is paid
$invoice->isPaid();

// Pay an invoice with a specific cryptocurrency
$payment = $user->payInvoice($invoice, 'eth');

// Listen for invoice completion
Event::listen(InvoicePaid::class, function (InvoicePaid $event) {
    $invoice = $event->invoice;
    // Fulfill the order...
});
```

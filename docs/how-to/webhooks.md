# Webhook Configuration & Handling

## Overview

NOWPayments uses **Instant Payment Notifications (IPN)** — HTTP POST webhooks — to notify your application whenever the status of a payment, subscription, invoice, or payout changes. This package provides a fully implemented webhook handler that verifies incoming requests, reconciles them against your local database, and fires Laravel events you can listen to.

The webhook route is automatically registered at:

```
POST /nowpayments/webhook
```

---

## 1. Webhook Architecture

### How IPN Works

1. A customer initiates a payment, subscription, or invoice through your application (or the NOWPayments dashboard).
2. NOWPayments processes the transaction on the blockchain.
3. When the transaction status changes (e.g., `pending` → `finished` or `failed`), NOWPayments sends a **POST request** to your configured webhook URL.
4. The payload contains the full state of the transaction — IDs, amounts, currencies, hashes, and status.
5. Your application verifies the request, updates local records, and fires events for downstream processing.

### Route Registration

The webhook route is registered automatically by the `CashierNowPaymentsServiceProvider`:

```php
// In CashierNowPaymentsServiceProvider::registerRoutes()
Route::post(
    config('cashier-nowpayments.webhook.path', '/nowpayments/webhook'),
    WebhookController::class
)->name('cashier-nowpayments.webhook')->middleware(['api']);
```

Key characteristics:

| Property | Value |
|---|---|
| **Method** | `POST` |
| **Default Path** | `/nowpayments/webhook` |
| **Middleware** | `api` (no CSRF protection) |
| **Controller** | `WebhookController` |
| **Core Trait** | `HandlesIpnWebhooks` (from `serenity_technologies/nowpayments`) |

The route uses the `api` middleware group, which means it does **not** include CSRF token verification. This is intentional — external services like NOWPayments cannot provide CSRF tokens.

### Controller Flow

```
NOWPayments
    │
    ▼
POST /nowpayments/webhook
    │
    ▼
WebhookController::__invoke()
    │
    ├── verifySignature()          ← HMAC SHA-512 verification
    ├── IpnHandler::handleRequest() ← Delegate to underlying package
    ├── validateTimestamp()         ← Replay attack prevention
    ├── processWebhookData()        ← Route to handler methods
    │   ├── handlePayment()
    │   ├── handleSubscription()
    │   ├── handleInvoice()
    │   ├── handlePayout()
    │   └── handleReDeposit()
    └── fireWebhookEvent()          ← Fire Laravel events from trait
```

The `WebhookController` uses the `HandlesIpnWebhooks` trait from the underlying NOWPayments package, which provides the `fireWebhookEvent()` method and base IPN handling utilities.

---

## 2. Configuring IPN in the NOWPayments Dashboard

Before your application can receive webhooks, you must configure IPN settings in the NOWPayments dashboard.

### Step 1: Generate an IPN Secret Key

1. Log in to your [NOWPayments Dashboard](https://account.nowpayments.io/).
2. Navigate to **Payment Settings** (or **Settings → Payment**).
3. Find the **IPN Secret Key** section.
4. Click **Generate** to create a new secret key.
5. **Copy and save the key immediately** — it may only be shown in full upon creation.

### Step 2: Store the Secret in Your Application

Add the IPN secret to your `.env` file:

```env
NOWPAYMENTS_IPN_SECRET=your_ipn_secret_key_here
```

The package reads this value via `config('cashier-nowpayments.ipn_secret')`.

### Step 3: Set the Notification URL

You have two options for configuring where NOWPayments sends webhooks:

#### Option A: Global IPN URL (Dashboard)

In the NOWPayments dashboard, set your **IPN Callback URL** to:

```
https://your-app.com/nowpayments/webhook
```

This URL receives notifications for **all** transactions in your account.

#### Option B: Per-Request IPN URL

When creating a payment, invoice, or payout, you can specify a custom `ipn_callback_url` that overrides the global setting:

```php
// Payment
$payment = $user
    ->charge(49.99, 'USD')
    ->payCurrency('BTC')
    ->ipnCallbackUrl('https://your-app.com/nowpayments/webhook')
    ->create();

// Invoice
$invoice = $user
    ->invoice(250.00, 'USD')
    ->ipnCallbackUrl('https://your-app.com/nowpayments/webhook')
    ->create();

// Payout
$payout = $user
    ->payout()
    ->amount(50.00)
    ->currency('USDTTRC20')
    ->address('TN2YxJ3kQvMqR5fG7wP8bC4dE6hA9sL1mX')
    ->ipnCallbackUrl('https://your-app.com/nowpayments/webhook')
    ->create();
```

### Step 4: Configure Retry Settings

In the NOWPayments dashboard under **Instant Payment Notifications**, you can configure:

| Setting | Description |
|---|---|
| **Timeout** | Interval between retry attempts (e.g., 1 minute) |
| **Number of Recurrent Notifications** | How many retries on error (e.g., 3) |

If your endpoint returns a non-2xx response, NOWPayments will retry at the configured interval up to the configured number of times.

### Step 5: Test with a Sandbox Payment

1. Create a test payment using a small amount.
2. Monitor your Laravel logs for incoming webhook requests:

```bash
tail -f storage/logs/laravel.log | grep NOWPayments
```

3. Verify that the webhook is received, verified, and processed successfully.

### Step 6: Whitelist NOWPayments IPs (If Required)

If your server uses a firewall or a service like Cloudflare, you may need to whitelist NOWPayments' IP addresses. Request the list from:

```
partners@nowpayments.io
```

---

## 3. Security

The package implements **dual-layer verification** to ensure that incoming webhooks are authentic and timely.

### Layer 1: HMAC SHA-512 Signature Verification

Every IPN webhook includes an `x-nowpayments-sig` header containing an HMAC signature of the request body. The `verifySignature()` method recomputes this signature and compares it:

```php
// In WebhookController::verifySignature()
protected function verifySignature(Request $request): bool
{
    $signature = $request->header('x-nowpayments-sig');
    $payload = $request->getContent();
    $ipnSecret = config('cashier-nowpayments.ipn_secret');

    if (empty($signature) || empty($ipnSecret)) {
        if (empty($ipnSecret)) {
            report('NOWPayments webhook: IPN secret not configured.');
        }
        return true; // Allow if no secret configured (IpnHandler will also check)
    }

    $computed = hash_hmac('sha512', $payload, trim($ipnSecret));

    return hash_equals($computed, $signature);
}
```

**How it works:**

1. The request body (raw JSON) is signed using HMAC with the SHA-512 algorithm and your IPN secret as the key.
2. The resulting hex digest is compared to the `x-nowpayments-sig` header using `hash_equals()` (constant-time comparison to prevent timing attacks).
3. If they match, the request is authentic. If not, a `403 Forbidden` response is returned.

### Layer 2: Timestamp Validation

To prevent replay attacks, the `validateTimestamp()` method checks that the webhook's `created_at` timestamp is within a configurable tolerance window:

```php
// In WebhookController::validateTimestamp()
protected function validateTimestamp(array $data): bool
{
    $tolerance = config('cashier-nowpayments.webhook.tolerance', 300);

    if (isset($data['created_at'])) {
        try {
            $webhookTime = Carbon::parse($data['created_at']);
            if ($webhookTime->diffInSeconds(now()) > $tolerance) {
                return false;
            }
        } catch (\Exception $e) {
            report('NOWPayments webhook: Unable to parse created_at: ' . $data['created_at']);
        }
    }

    return true;
}
```

**Configuration:**

```env
# Tolerance in seconds (default: 300 = 5 minutes)
CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE=300
```

If the timestamp is outside the tolerance window, a `403 Forbidden` response is returned.

### Fallback Behavior

If the IPN secret is **not configured** (`NOWPAYMENTS_IPN_SECRET` is empty or missing):

1. `verifySignature()` logs a warning via `report()` but returns `true`, allowing the request to proceed.
2. The underlying `IpnHandler` from the `serenity_technologies/nowpayments` package also performs its own signature check.

This means your webhooks will still be processed, but you will see warnings in your logs. **Always configure the IPN secret in production.**

### Signature Mismatch Handling

If the HMAC signature does not match:

```php
if (!$this->verifySignature($request)) {
    report('NOWPayments webhook: HMAC signature mismatch.');
    return response()->json(['error' => 'Invalid signature'], 403);
}
```

If you encounter signature mismatches in production:

1. Verify that `NOWPAYMENTS_IPN_SECRET` in your `.env` matches the key in the NOWPayments dashboard.
2. Ensure the secret has no trailing whitespace (the package trims it, but double-check).
3. Check that the request body is not being modified by middleware before verification.
4. Contact NOWPayments support at `support@nowpayments.io` if the issue persists.

---

## 4. Webhook Processing Flow

After signature and timestamp verification, the `processWebhookData()` method inspects the payload to determine what type of event it represents and routes it to the appropriate handler.

### Routing Logic

```php
// In WebhookController::processWebhookData()
protected function processWebhookData(array $data): void
{
    // 1. Payout: detected by currency + address without payment_id/subscription_id
    if (isset($data['currency']) && isset($data['address'])
        && !isset($data['payment_id']) && !isset($data['subscription_id'])) {
        $this->handlePayout($data);
        return;
    }

    // 2. Payment: detected by presence of payment_id
    if (isset($data['payment_id'])) {
        $this->handlePayment($data);
    }

    // 3. Subscription: detected by subscription_id or plan_id
    if (isset($data['subscription_id']) || isset($data['plan_id'])) {
        $this->handleSubscription($data);
    }

    // 4. Invoice: detected by invoice_id
    if (isset($data['invoice_id'])) {
        $this->handleInvoice($data);
    }

    // 5. Re-deposit: detected by parent_payment_id
    if (isset($data['parent_payment_id'])) {
        $this->handleReDeposit($data);
    }
}
```

### Detection Rules

| Webhook Type | Detection Key(s) | Handler Method |
|---|---|---|
| **Payout** | `currency` + `address` without `payment_id` or `subscription_id` | `handlePayout()` |
| **Payment** | `payment_id` | `handlePayment()` |
| **Subscription** | `subscription_id` or `plan_id` | `handleSubscription()` |
| **Invoice** | `invoice_id` | `handleInvoice()` |
| **Re-deposit** | `parent_payment_id` | `handleReDeposit()` |

**Important:** A single webhook payload can trigger **multiple handlers**. For example, a payment webhook for a subscription renewal may contain both `payment_id` and `subscription_id`, causing both `handlePayment()` and `handleSubscription()` to execute.

---

## 5. Payment Webhook Handling

The `handlePayment()` method is responsible for creating or updating local `Payment` records when a payment status changes.

### Flow

```
handlePayment($data)
    │
    ├── Find existing Payment by nowpayments_payment_id
    │   │
    │   ├── NOT FOUND → Create new Payment
    │   │   ├── getOrCreateCustomerFromWebhook($data)
    │   │   ├── Build Payment model from webhook fields
    │   │   └── Save
    │   │
    │   └── FOUND → Diff-based update
    │       ├── Compare status, amount_paid, payin_hash, payout_hash
    │       └── Update only changed fields
    │
    ├── If status == 'finished' and paid_at is null → Set paid_at
    │
    └── Fire events
        ├── 'finished'  → PaymentReceived::dispatch($payment, $data)
        └── 'failed'/'expired' → PaymentFailed::dispatch($payment, $data)
```

### Creating a New Payment

When a payment does not exist locally (e.g., it was initiated outside the package or the local record was lost), the handler creates one from the webhook payload:

```php
$customer = $this->getOrCreateCustomerFromWebhook($data);

$payment = new $paymentModel();
$payment->fill([
    'customer_id' => $customer->id,
    'nowpayments_payment_id' => (string) $data['payment_id'],
    'nowpayments_purchase_id' => $data['purchase_id'] ?? null,
    'parent_payment_id' => $data['parent_payment_id'] ?? null,
    'type' => 'onetime',
    'status' => $data['payment_status'],
    'currency' => $data['price_currency'] ?? null,
    'amount' => $data['price_amount'] ?? 0,
    'amount_paid' => $data['actually_paid'] ?? 0,
    'pay_currency' => $data['pay_currency'] ?? null,
    'pay_amount' => $data['pay_amount'] ?? null,
    'pay_address' => $data['pay_address'] ?? null,
    'order_id' => $data['order_id'] ?? null,
    'order_description' => $data['order_description'] ?? null,
    'payin_hash' => $data['payin_hash'] ?? null,
    'payout_hash' => $data['payout_hash'] ?? null,
    'fee' => $data['fee'] ?? null,
]);

if ($customer->billable !== null) {
    $payment->billable()->associate($customer->billable);
}

$payment->save();
```

### Efficient Diff-Based Update

When the payment already exists locally, only changed fields are updated:

```php
$changes = [];

if (($data['payment_status'] ?? '') !== $payment->status) {
    $changes['status'] = $data['payment_status'];
}

if (isset($data['actually_paid']) && (string) $data['actually_paid'] !== (string) $payment->amount_paid) {
    $changes['amount_paid'] = $data['actually_paid'];
}

if (isset($data['payin_hash']) && $data['payin_hash'] !== $payment->payin_hash) {
    $changes['payin_hash'] = $data['payin_hash'];
}

if (isset($data['payout_hash']) && $data['payout_hash'] !== $payment->payout_hash) {
    $changes['payout_hash'] = $data['payout_hash'];
}

if (!empty($changes)) {
    $payment->update($changes);
}
```

### Setting the `paid_at` Timestamp

When a payment transitions to `finished`, the `paid_at` column is set:

```php
if ($data['payment_status'] === 'finished' && $payment->paid_at === null) {
    $payment->update(['paid_at' => now()]);
}
```

This ensures `paid_at` is only set once, even if multiple `finished` webhooks arrive.

### Payment Status Values

| Status | Description | Event Fired |
|---|---|---|
| `waiting` | Payment address generated, awaiting funds | None |
| `confirming` | Transaction detected on blockchain, confirming | None |
| `confirmed` | Enough confirmations received | None |
| `sending` | Funds being sent to payout wallet | None |
| `finished` | Payment completed successfully | `PaymentReceived` |
| `failed` | Payment failed | `PaymentFailed` |
| `expired` | Payment timed out | `PaymentFailed` |
| `partially_paid` | Partial amount received | None |
| `refunded` | Payment refunded to customer | `PaymentRefunded` (not from webhook) |

---

## 6. Customer Reconciliation from Webhooks

When a payment webhook arrives and no local `Payment` record exists, the handler must also determine which `Customer` (and by extension, which billable model) the payment belongs to. This is done by `getOrCreateCustomerFromWebhook()`.

### Reconciliation Strategy

The method attempts to find or create a customer using a **cascading fallback** approach:

```
getOrCreateCustomerFromWebhook($data)
    │
    ├── 1. Find by email
    │   └── Customer::where('email', $data['email'])->first()
    │       └── Found → Return
    │
    ├── 2. Find by order_id via session cache
    │   └── Cache::get('checkout.billable.{order_id}')
    │       └── Found mapping → Customer::where(billable_type, billable_id)->first()
    │           └── Found → Return
    │
    ├── 3. Find by order_id in metadata
    │   └── Customer::whereJsonContains('metadata->order_id', $data['order_id'])->first()
    │       └── Found → Return
    │
    └── 4. Create new customer (fallback)
        └── nowpayments_customer_id = "np_payment_{payment_id}"
            metadata.source = "webhook_auto_created"
```

### Step 1: Find by Email

```php
if (isset($data['email']) && !empty($data['email'])) {
    $customer = $customerModel::where('email', $data['email'])->first();
    if ($customer !== null) {
        return $customer;
    }
}
```

If the webhook payload includes an email that matches an existing local customer, that customer is returned immediately.

### Step 2: Find by Order ID (Session Cache)

During the checkout flow, the package stores a mapping between the `order_id` and the billable model in the cache:

```php
if (isset($data['order_id']) && !empty($data['order_id'])) {
    $billableMapping = Cache::get('checkout.billable.' . $data['order_id']);
    if ($billableMapping !== null) {
        $customer = $customerModel::where('billable_type', $billableMapping['billable_type'])
            ->where('billable_id', $billableMapping['billable_id'])
            ->first();
        if ($customer !== null) {
            return $customer;
        }
    }
}
```

This is the **most reliable** reconciliation method for payments initiated through the package's checkout flow. The cache entry is set during the checkout session and maps `order_id` → `{billable_type, billable_id}`.

### Step 3: Find by Order ID in Metadata

```php
$customer = $customerModel::whereJsonContains('metadata->order_id', $data['order_id'])->first();
if ($customer !== null) {
    return $customer;
}
```

As a fallback, the method searches the `metadata` JSON column for a matching `order_id`.

### Step 4: Create New Customer (Fallback)

If no existing customer can be found, a new one is created:

```php
$customer = new $customerModel();
$customer->fill([
    'nowpayments_customer_id' => 'np_payment_' . $data['payment_id'],
    'email' => $data['email'] ?? null,
    'metadata' => [
        'order_id' => $data['order_id'] ?? null,
        'purchase_id' => $data['purchase_id'] ?? null,
        'source' => 'webhook_auto_created',
    ],
]);

$customer->save();
```

**Important:** Auto-created customers will have `metadata.source = 'webhook_auto_created'` and a synthetic `nowpayments_customer_id` of the form `np_payment_{payment_id}`. The `billable_id` and `billable_type` will be `null`, meaning the customer is not yet associated with a billable model.

### Manual Reconciliation of Auto-Created Customers

For customers created via the webhook fallback, you may need to manually reconcile the billable association. You can query for auto-created customers:

```php
use SerenityTechnologies\CashierNowPayments\Models\Customer;

// Find all auto-created customers
$unlinked = Customer::where('metadata->source', 'webhook_auto_created')
    ->whereNull('billable_id')
    ->get();

foreach ($unlinked as $customer) {
    // Try to find the billable by email
    $orderEmail = $customer->email;
    $user = \App\Models\User::where('email', $orderEmail)->first();

    if ($user) {
        $customer->update([
            'billable_id' => $user->id,
            'billable_type' => \App\Models\User::class,
        ]);
    }
}
```

You can automate this reconciliation in a scheduled command or by listening to the `PaymentReceived` event.

---

## 7. Subscription Webhook Handling

The `handleSubscription()` method updates local subscription records when their status changes on NOWPayments.

### Flow

```
handleSubscription($data)
    │
    ├── Find Subscription by nowpayments_subscription_id
    │   └── If not found → Skip (no local subscription)
    │
    ├── Capture old status
    │
    ├── Update status
    │
    └── If status changed → Fire events
        ├── SubscriptionUpdated::dispatch()  (always on status change)
        ├── SubscriptionCancelled::dispatch()  (if new status is 'cancelled' or 'expired')
        ├── SubscriptionExpired::dispatch()    (if new status is 'expired')
        └── SubscriptionRenewed::dispatch()    (if new status is 'paid' and was different)
```

### Implementation

```php
$subscription = $subscriptionModel::where(
    'nowpayments_subscription_id',
    $data['subscription_id'] ?? $data['id'] ?? null
)->first();

if ($subscription !== null) {
    $oldStatus = $subscription->status;
    $newStatus = $data['status'] ?? $subscription->status;

    $subscription->update(['status' => $newStatus]);

    if ($oldStatus !== $newStatus) {
        SubscriptionUpdated::dispatch($subscription, $data);

        if ($newStatus === 'cancelled' || $newStatus === 'expired') {
            SubscriptionCancelled::dispatch($subscription, $data);
        }

        if ($newStatus === 'expired') {
            SubscriptionExpired::dispatch($subscription, $data);
        }

        if ($newStatus === 'paid' && $oldStatus !== $newStatus) {
            SubscriptionRenewed::dispatch($subscription, $data);
        }
    }
}
```

### Key Behavior: Only Processes Existing Subscriptions

If the webhook arrives for a subscription that does **not** exist locally, it is silently skipped. This can happen if:

- The subscription was created directly in the NOWPayments dashboard.
- The local subscription record was deleted.

In these cases, you may need to manually create the subscription record.

### Subscription Status Transitions

| Old Status | New Status | Events Fired |
|---|---|---|
| `active` | `cancelled` | `SubscriptionUpdated`, `SubscriptionCancelled` |
| `active` | `expired` | `SubscriptionUpdated`, `SubscriptionCancelled`, `SubscriptionExpired` |
| `pending` | `paid` | `SubscriptionUpdated`, `SubscriptionRenewed` |
| `paid` | `paid` | (none — no status change) |
| `active` | `paused` | `SubscriptionUpdated` |

---

## 8. Invoice Webhook Handling

The `handleInvoice()` method updates local invoice records when their payment status changes.

### Flow

```
handleInvoice($data)
    │
    ├── Find Invoice by nowpayments_invoice_id
    │   └── If not found → Log warning and skip
    │
    ├── Update status and amount_paid
    │
    ├── If status == 'finished' and paid_at is null → Set paid_at
    │   └── Fire InvoicePaid::dispatch($invoice, $data)
    │
    └── If status == 'failed' or 'expired'
        └── Fire InvoicePaymentFailed::dispatch($invoice, $data)
```

### Implementation

```php
$invoice = $invoiceModel::where('nowpayments_invoice_id', $data['invoice_id'])->first();

if ($invoice === null) {
    report("NOWPayments webhook: Invoice {$data['invoice_id']} not found locally.");
    return;
}

$invoice->update([
    'status' => $data['payment_status'] ?? $invoice->status,
    'amount_paid' => $data['actually_paid'] ?? $invoice->amount_paid,
]);

if ($data['payment_status'] === 'finished' && $invoice->paid_at === null) {
    $invoice->update(['paid_at' => now()]);
    InvoicePaid::dispatch($invoice, $data);
}

if (in_array($data['payment_status'] ?? '', ['failed', 'expired'], true)) {
    InvoicePaymentFailed::dispatch($invoice, $data);
}
```

### Dashboard-Created Invoices

If an invoice was created outside this package (e.g., via the NOWPayments dashboard), the local `Invoice` record will not exist. In this case:

1. A warning is logged via `report()`.
2. The webhook is silently skipped.

To handle dashboard-created invoices, you would need to create a custom webhook listener or periodically reconcile invoices from the NOWPayments API using `remotePayments()`.

---

## 9. Payout Webhook Handling

The `handlePayout()` method updates local payout records when their status changes.

### Flow

```
handlePayout($data)
    │
    ├── Find Payout by nowpayments_payout_id or batch_withdrawal_id
    │   └── If not found → Skip
    │
    ├── Update status, hash, error, processed_at
    │
    └── Fire PayoutStatusUpdated::dispatch($payout, $data)
```

### Implementation

```php
$payoutId = $data['id'] ?? $data['batch_withdrawal_id'] ?? null;

$payout = $payoutModel::where('nowpayments_payout_id', $payoutId)
    ->orWhere('batch_withdrawal_id', $data['batch_withdrawal_id'] ?? null)
    ->first();

if ($payout !== null) {
    $payout->update([
        'status' => strtolower($data['status'] ?? $payout->status),
        'hash' => $data['hash'] ?? $payout->hash,
        'error' => $data['error'] ?? $payout->error,
        'processed_at' => $data['status'] === 'finished' && $payout->processed_at === null
            ? now()
            : $payout->processed_at,
    ]);

    PayoutStatusUpdated::dispatch($payout, $data);
}
```

### Payout Status Values

| Status | Description |
|---|---|
| `creating` | Payout is being prepared |
| `pending` | Payout is queued |
| `confirming` | Payout is being confirmed |
| `sending` | Payout is being sent |
| `finished` | Payout completed successfully |
| `failed` | Payout failed (check `error` field) |

The status is normalized to lowercase before storage.

### Sample Payout Webhook Payload

```json
{
    "id": "123456789",
    "batch_withdrawal_id": "987654321",
    "status": "FINISHED",
    "error": null,
    "currency": "usdttrc20",
    "amount": "50",
    "address": "TN2YxJ3kQvMqR5fG7wP8bC4dE6hA9sL1mX",
    "fee": null,
    "extra_id": null,
    "hash": "0xabc123...",
    "ipn_callback_url": "https://your-app.com/nowpayments/webhook",
    "created_at": "2025-04-09T15:29:40.803Z",
    "requested_at": null,
    "updated_at": "2025-04-09T15:30:01.123Z"
}
```

---

## 10. Re-Deposit Webhook Handling

Re-deposits occur when a customer sends additional funds to a previously used payment address. The `handleReDeposit()` method is a **placeholder** — it currently performs no action.

```php
protected function handleReDeposit(array $data): void
{
    // Re-deposits are linked to parent payment via parent_payment_id
    // They are handled in the handlePayment method
    // but we can add special logic here if needed
}
```

Re-deposits are still processed by `handlePayment()` because they include a `payment_id`. The `parent_payment_id` field in the payload links them to the original payment.

### Custom Re-Deposit Logic

If you need special handling for re-deposits, you can extend the `WebhookController` and override `handleReDeposit()`:

```php
// In your extended controller
protected function handleReDeposit(array $data): void
{
    $parentPayment = Payment::where(
        'nowpayments_payment_id',
        $data['parent_payment_id']
    )->first();

    if ($parentPayment) {
        // Custom logic: notify the user, update the order, etc.
        ReDepositReceived::dispatch($parentPayment, $data);
    }
}
```

Then register your custom controller in a service provider:

```php
Route::post(
    config('cashier-nowpayments.webhook.path', '/nowpayments/webhook'),
    YourCustomWebhookController::class
)->middleware(['api']);
```

---

## 11. Testing Webhooks Locally

Since NOWPayments cannot send webhooks to `localhost`, you need a publicly accessible URL during development.

### Using ngrok

[ngrok](https://ngrok.com/) creates a secure tunnel from a public URL to your local development server.

#### Step 1: Install ngrok

```bash
# macOS
brew install ngrok

# Or download from https://ngrok.com/download
```

#### Step 2: Start Your Laravel Application

```bash
php artisan serve
```

#### Step 3: Start ngrok

```bash
ngrok http 8000
```

ngrok will display a forwarding URL, such as:

```
Forwarding  https://abc123.ngrok-free.app -> http://localhost:8000
```

#### Step 4: Configure the Webhook URL

In the NOWPayments dashboard, set your IPN callback URL to the ngrok URL:

```
https://abc123.ngrok-free.app/nowpayments/webhook
```

Or set it per-request when creating a payment:

```php
$payment = $user
    ->charge(1.00, 'USD')
    ->ipnCallbackUrl('https://abc123.ngrok-free.app/nowpayments/webhook')
    ->create();
```

#### Step 5: Monitor Webhooks

Watch the ngrok inspector at `http://localhost:4040` to see incoming webhook requests in real time. You can also inspect the Laravel logs:

```bash
tail -f storage/logs/laravel.log
```

### Using the Postman Collection

The package includes a Postman collection at the project root:

```
NOWPayments API.postman_collection.json
```

This collection contains pre-configured requests for the NOWPayments API, including:

- API status checks
- Authentication
- Payment creation and status checks
- Invoice creation
- Payout creation
- And more

You can use the collection to:

1. **Create a test payment** and observe the resulting webhook.
2. **Check payment status** manually to verify the state before and after a webhook arrives.
3. **Test the API** to ensure your credentials are configured correctly.

Import the collection into Postman, configure the environment variables (`api-host`, `token`, `email`, `password`), and run the requests.

### Manual Test Requests

You can simulate a webhook manually using `curl` or Laravel's built-in HTTP testing:

#### Using curl

```bash
curl -X POST https://your-app.com/nowpayments/webhook \
  -H "Content-Type: application/json" \
  -H "x-nowpayments-sig: $(echo -n '{"payment_id":12345,"payment_status":"finished","price_amount":100,"price_currency":"usd","pay_amount":0.001,"pay_currency":"btc","actually_paid":0.001,"order_id":"TEST-001","purchase_id":"12345","pay_address":"bc1q...","created_at":"2025-04-09T12:00:00.000Z"}' | openssl dgst -sha512 -hmac 'your_ipn_secret')" \
  -d '{
    "payment_id": 12345,
    "payment_status": "finished",
    "price_amount": 100,
    "price_currency": "usd",
    "pay_amount": 0.001,
    "pay_currency": "btc",
    "actually_paid": 0.001,
    "order_id": "TEST-001",
    "purchase_id": "12345",
    "pay_address": "bc1q...",
    "created_at": "2025-04-09T12:00:00.000Z"
  }'
```

#### Using Laravel HTTP Testing

```php
// In a test file
public function test_payment_webhook(): void
{
    $payload = [
        'payment_id' => 12345,
        'payment_status' => 'finished',
        'price_amount' => 100,
        'price_currency' => 'usd',
        'pay_amount' => 0.001,
        'pay_currency' => 'btc',
        'actually_paid' => 0.001,
        'order_id' => 'TEST-001',
        'purchase_id' => '12345',
        'created_at' => now()->toIso8601String(),
    ];

    $signature = hash_hmac(
        'sha512',
        json_encode($payload, JSON_UNESCAPED_SLASHES),
        config('cashier-nowpayments.ipn_secret')
    );

    $response = $this->postJson('/nowpayments/webhook', $payload, [
        'x-nowpayments-sig' => $signature,
    ]);

    $response->assertOk();
    $this->assertDatabaseHas('cashier_nowpayments_payments', [
        'nowpayments_payment_id' => '12345',
        'status' => 'finished',
    ]);
}
```


## 12. All Dispatched Events

The webhook handler fires the following Laravel events based on incoming webhook data. All events extend `CashierNowPaymentsEvent`, which provides:

```php
abstract class CashierNowPaymentsEvent
{
    public readonly object $model;        // The local model instance
    public readonly array $nowpaymentsPayload;  // Raw NOWPayments webhook data
}
```

### Payment Events

| Event | Model | Trigger Condition | Payload Keys |
|---|---|---|---|
| `PaymentReceived` | `Payment` | `payment_status` = `finished` | `$event->payment`, `$event->nowpaymentsPayload` |
| `PaymentFailed` | `Payment` | `payment_status` = `failed` or `expired` | `$event->payment`, `$event->nowpaymentsPayload` |

### Subscription Events

| Event | Model | Trigger Condition | Payload Keys |
|---|---|---|---|
| `SubscriptionUpdated` | `Subscription` | Subscription status changed | `$event->subscription`, `$event->nowpaymentsPayload` |
| `SubscriptionCancelled` | `Subscription` | New status = `cancelled` or `expired` | `$event->subscription`, `$event->nowpaymentsPayload` |
| `SubscriptionExpired` | `Subscription` | New status = `expired` | `$event->subscription`, `$event->nowpaymentsPayload` |
| `SubscriptionRenewed` | `Subscription` | New status = `paid` (was different) | `$event->subscription`, `$event->nowpaymentsPayload` |

### Invoice Events

| Event | Model | Trigger Condition | Payload Keys |
|---|---|---|---|
| `InvoicePaid` | `Invoice` | `payment_status` = `finished` | `$event->invoice`, `$event->nowpaymentsPayload` |
| `InvoicePaymentFailed` | `Invoice` | `payment_status` = `failed` or `expired` | `$event->invoice`, `$event->nowpaymentsPayload` |

### Payout Events

| Event | Model | Trigger Condition | Payload Keys |
|---|---|---|---|
| `PayoutStatusUpdated` | `Payout` | Payout webhook received (for existing payout) | `$event->payout`, `$event->nowpaymentsPayload` |

### Embedded Widget Webhooks

When using the embedded payment widget, webhooks work identically to regular checkout. The widget creates an invoice on NOWPayments, and you receive the same webhook payloads:

```php
// Embedded widget creates invoice automatically
// Webhook payload includes:
{
    "invoice_id": "5253875336",
    "payment_status": "finished",
    "price_amount": "49.99",
    "price_currency": "usd",
    "pay_amount": "0.00123",
    "pay_currency": "btc",
    "actually_paid": "0.00123",
    "order_id": "INV-abc123",
    "purchase_id": "xyz789"
}

// Handled by WebhookController::handleInvoice()
// InvoicePaid event dispatched
```

**Key Points:**
- Embedded widget automatically sets `success_url` and `cancel_url`
- Invoice is created before widget loads
- Webhook fires when payment completes
- Same HMAC-SHA512 verification applies
- Guest customers are linked via session-based customer record
- Processing fee info is stored in payment metadata when applicable

### Listening to Events

Register event listeners in your `EventServiceProvider`:

```php
// app/Providers/EventServiceProvider.php
protected $listen = [
    \SerenityTechnologies\CashierNowPayments\Events\PaymentReceived::class => [
        \App\Listeners\HandlePaymentReceived::class,
    ],
    \SerenityTechnologies\CashierNowPayments\Events\PaymentFailed::class => [
        \App\Listeners\NotifyPaymentFailed::class,
    ],
    \SerenityTechnologies\CashierNowPayments\Events\InvoicePaid::class => [
        \App\Listeners\ProcessInvoicePaid::class,
    ],
    \SerenityTechnologies\CashierNowPayments\Events\SubscriptionCancelled::class => [
        \App\Listeners\HandleSubscriptionCancelled::class,
    ],
    \SerenityTechnologies\CashierNowPayments\Events\PayoutStatusUpdated::class => [
        \App\Listeners\UpdatePayoutTracking::class,
    ],
];
```

### Example Listener

```php
namespace App\Listeners;

use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;

class HandlePaymentReceived
{
    public function handle(PaymentReceived $event): void
    {
        $payment = $event->payment;
        $rawPayload = $event->nowpaymentsPayload;

        // Grant access, fulfill order, send receipt, etc.
        if ($payment->billable) {
            $payment->billable->grantAccessTo($payment->order_id);
        }

        // Log the raw payload for debugging
        logger()->info('Payment received from NOWPayments', $rawPayload);
    }
}
```

### Using Event Subscribers

For handling multiple events in one class, use an event subscriber:

```php
namespace App\Listeners;

use SerenityTechnologies\CashierNowPayments\Events\{
    PaymentReceived,
    PaymentFailed,
    InvoicePaid,
    InvoicePaymentFailed,
    SubscriptionUpdated,
    SubscriptionCancelled,
    PayoutStatusUpdated,
};

class NowPaymentsWebhookSubscriber
{
    public function subscribe($events): array
    {
        return [
            PaymentReceived::class => 'handlePaymentReceived',
            PaymentFailed::class => 'handlePaymentFailed',
            InvoicePaid::class => 'handleInvoicePaid',
            InvoicePaymentFailed::class => 'handleInvoicePaymentFailed',
            SubscriptionUpdated::class => 'handleSubscriptionUpdated',
            SubscriptionCancelled::class => 'handleSubscriptionCancelled',
            PayoutStatusUpdated::class => 'handlePayoutStatusUpdated',
        ];
    }

    public function handlePaymentReceived(PaymentReceived $event): void
    {
        // Process successful payment
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        // Notify user of failed payment
    }

    public function handleInvoicePaid(InvoicePaid $event): void
    {
        // Process paid invoice
    }

    public function handleInvoicePaymentFailed(InvoicePaymentFailed $event): void
    {
        // Handle failed invoice payment
    }

    public function handleSubscriptionUpdated(SubscriptionUpdated $event): void
    {
        // Update user's subscription status
    }

    public function handleSubscriptionCancelled(SubscriptionCancelled $event): void
    {
        // Revoke subscription access
    }

    public function handlePayoutStatusUpdated(PayoutStatusUpdated $event): void
    {
        // Update payout tracking
    }
}
```

Register the subscriber:

```php
// In EventServiceProvider
protected $subscribe = [
    \App\Listeners\NowPaymentsWebhookSubscriber::class,
];
```

---

## 13. Customizing the Webhook Handler

If you need to extend or modify the webhook handling behavior, you can create a custom controller that extends `WebhookController`.

### Step 1: Create Custom Controller

```php
namespace App\Http\Controllers;

use SerenityTechnologies\CashierNowPayments\Http\Controllers\WebhookController;

class CustomWebhookController extends WebhookController
{
    // Override any protected method
    protected function handlePayment(array $data): void
    {
        parent::handlePayment($data);

        // Additional custom logic
        if (isset($data['payment_status']) && $data['payment_status'] === 'finished') {
            // Send custom notification, update external systems, etc.
        }
    }

    // Or add entirely new handlers
    protected function handleCustomEvent(array $data): void
    {
        // Your custom logic
    }
}
```

### Step 2: Register Custom Route

In your `AppServiceProvider` or a dedicated route file:

```php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomWebhookController;

// Override the default webhook route
Route::post(
    config('cashier-nowpayments.webhook.path', '/nowpayments/webhook'),
    CustomWebhookController::class
)->name('cashier-nowpayments.webhook')->middleware(['api']);
```

**Note:** If you register the route after the package's service provider boot, you may need to remove the default route first or use a different path.

---

## 14. Troubleshooting

### Webhooks Not Being Received

1. **Verify the IPN URL is publicly accessible** — ngrok or a live server is required.
2. **Check NOWPayments dashboard** — confirm the IPN callback URL is set correctly.
3. **Check firewall/IP whitelisting** — NOWPayments may be blocked by Cloudflare or your server firewall.
4. **Review Laravel logs** — look for `NOWPayments webhook` entries in `storage/logs/laravel.log`.

### Signature Mismatch Errors

```
NOWPayments webhook: HMAC signature mismatch.
```

1. Verify `NOWPAYMENTS_IPN_SECRET` matches the dashboard exactly.
2. Ensure no middleware modifies the request body before verification.
3. Check for BOM or encoding issues in the request body.

### Timestamp Outside Tolerance

```
NOWPayments webhook: Timestamp outside tolerance.
```

1. Ensure your server clock is synchronized (use NTP).
2. Increase the tolerance if your server experiences latency:

```env
CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE=600
```

### Payment Not Linked to Billable Model

If payments are created but `billable_id` is `null`:

1. Ensure the `order_id` is set when creating the payment.
2. Verify the session cache entry exists at `checkout.billable.{order_id}`.
3. Query for auto-created customers and reconcile manually (see [Section 6](#6-customer-reconciliation-from-webhooks)).

### Duplicate Webhooks

NOWPayments may send the same webhook multiple times (due to retries or dashboard re-sends). The handler is designed to be **idempotent**:

- Payment updates use diff-based logic, so re-processing a `finished` webhook does not duplicate data.
- `paid_at` is only set if it is currently `null`.
- Subscription events only fire when the status actually changes.

---

## Configuration Reference

| Config Key | Environment Variable | Default | Description |
|---|---|---|---|
| `cashier-nowpayments.ipn_secret` | `NOWPAYMENTS_IPN_SECRET` | `null` | HMAC secret for webhook signature verification |
| `cashier-nowpayments.webhook.path` | `CASHIER_NOWPAYMENTS_WEBHOOK_PATH` | `/nowpayments/webhook` | Webhook endpoint path |
| `cashier-nowpayments.webhook.tolerance` | `CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE` | `300` | Timestamp tolerance in seconds |
| `cashier-nowpayments.model.payment` | — | `Payment::class` | Payment model class |
| `cashier-nowpayments.model.subscription` | — | `Subscription::class` | Subscription model class |
| `cashier-nowpayments.model.invoice` | — | `Invoice::class` | Invoice model class |
| `cashier-nowpayments.model.payout` | — | `Payout::class` | Payout model class |
| `cashier-nowpayments.model.customer` | — | `Customer::class` | Customer model class |

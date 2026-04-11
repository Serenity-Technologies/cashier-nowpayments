# HOW-TO: Payouts (Crypto Withdrawals)

This guide covers everything you need to know about creating, managing, and tracking crypto payouts (withdrawals) using the Laravel Cashier NOWPayments package.

---

## Table of Contents

- [1. Creating Payouts](#1-creating-payouts)
- [2. Persisting Payouts](#2-persisting-payouts)
- [3. The Payout Model](#3-the-payout-model)
- [4. The PayoutWithdrawal Model](#4-the-payoutwithdrawal-model)
- [5. Billable Trait Methods](#5-billable-trait-methods)
- [6. Payout Utilities](#6-payout-utilities)
- [7. Payout Webhook Handling](#7-payout-webhook-handling)
- [8. Common Patterns](#8-common-patterns)

---

## 1. Creating Payouts

Payouts are created using the fluent `PayoutBuilder`, accessed via the `payout()` method on any billable model (typically your `User` model).

### Basic Setup

Ensure your billable model uses the `ManagesPayouts` trait:

```php
use SerenityTechnologies\CashierNowPayments\Concerns\ManagesPayouts;

class User extends Authenticatable
{
    use ManagesPayouts;
}
```

### The PayoutBuilder

Call `$user->payout()` to start building a payout. The builder provides these methods:

| Method | Description |
|--------|-------------|
| `->to($address, $currency, $amount, $extraId)` | Add a withdrawal recipient. Can be called multiple times for batch payouts. |
| `->withDescription($description)` | Set a description for the payout. |
| `->scheduledFor(Carbon $dateTime)` | Schedule the payout for future execution. |
| `->withMetadata(array $metadata)` | Attach custom metadata. |
| `->create()` | Send the payout via the NOWPayments API only (does not persist locally). Returns `PayoutResponse` DTO. |
| `->send()` | Create the payout via the API **and** persist it to the database. Returns a `Payout` model. |

### Creating a Single Payout (API Only)

```php
use Carbon\Carbon;

$response = $user->payout()
    ->to('0x742d35Cc6634C0532925a3b844Bc9e7595f5eE2B', 'eth', 1.5)
    ->withDescription('Monthly affiliate payout')
    ->create();

// $response is a PayoutResponse DTO from the NOWPayments SDK
echo $response->id;          // NOWPayments payout ID
echo $response->status;      // e.g., "waiting", "processing", "finished"
```

The `create()` method:
- Validates that at least one withdrawal has been added (throws `InvalidArgumentException` otherwise).
- Builds a `PayoutRequest` DTO with all withdrawals, description, scheduled time, and the IPN callback URL.
- Calls `NowPayments::createPayout()` to submit the payout to the NOWPayments API.
- Dispatches the `PayoutCreated` event with the billable, customer, and API response.
- Returns the `PayoutResponse` DTO without persisting anything locally.

### Creating and Persisting a Payout

Use `send()` when you want the payout created via the API **and** stored in your local database:

```php
$payout = $user->payout()
    ->to('0x742d35Cc6634C0532925a3b844Bc9e7595f5eE2B', 'eth', 1.5)
    ->withDescription('Monthly affiliate payout')
    ->send();

// $payout is a persisted Payout model
echo $payout->id;                    // Local ULID
echo $payout->nowpayments_payout_id; // NOWPayments payout ID
echo $payout->status;                // Current status
```

### Batch Payouts (Multiple Recipients)

Chain multiple `->to()` calls to send to multiple addresses in a single batch:

```php
$payout = $user->payout()
    ->to('0x742d35Cc6634C0532925a3b844Bc9e7595f5eE2B', 'eth', 1.5)
    ->to('bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh', 'btc', 0.005)
    ->to('DQzKpZ7qY8qZ9qY8qZ9qY8qZ9qY8qZ9qY8', 'usdttrc20', 100.00, 'memo_or_extra_id_if_needed')
    ->withDescription('Q4 vendor payouts')
    ->send();
```

When multiple withdrawals are present, the `persistWithdrawals()` method creates individual `PayoutWithdrawal` records for each one, all linked to the parent `Payout`.

### Scheduled Payouts

Defer execution to a future time:

```php
use Carbon\Carbon;

$payout = $user->payout()
    ->to('0x742d35Cc6634C0532925a3b844Bc9e7595f5eE2B', 'eth', 2.0)
    ->scheduledFor(Carbon::now()->addHours(24))
    ->send();
```

The `execute_at` timestamp is stored locally and passed to the NOWPayments API as an ISO 8601 string.

### With Metadata

Attach arbitrary key-value data for your own tracking:

```php
$payout = $user->payout()
    ->to('0x742d35Cc6634C0532925a3b844Bc9e7595f5eE2B', 'eth', 1.5)
    ->withMetadata([
        'campaign_id' => 42,
        'quarter' => 'Q4',
        'approved_by' => auth()->id(),
    ])
    ->send();
```

Metadata is stored as JSON on the `Payout` model and is automatically cast to/from arrays.

### IPN Callback URL

Both `create()` and `send()` automatically include the IPN callback URL via the `GeneratesWebhookUrl` trait. The URL is constructed from your `app.url` config and the webhook path defined in `cashier-nowpayments.webhook.path` (default: `/nowpayments/webhook`).

---

## 2. Persisting Payouts

When you call `send()`, the `persistPayout()` method handles local storage:

### How It Works

1. **Validates the API response** — throws `RuntimeException` if the response contains no withdrawals.
2. **Resolves the Payout model class** from `config('cashier-nowpayments.model.payout')`, defaulting to `\SerenityTechnologies\CashierNowPayments\Models\Payout`.
3. **Creates the primary Payout record** using data from the **first withdrawal** in the response:
   - `customer_id` — linked to the billable's Customer
   - `billable_id` / `billable_type` — polymorphic association
   - `nowpayments_payout_id` — from `$response->id` or `$response->batchWithdrawalId`
   - `batch_withdrawal_id` — from `$response->batchWithdrawalId` (if present)
   - `status` — normalized to lowercase
   - `currency`, `amount`, `address`, `extra_id` — from the first withdrawal
   - `hash` — transaction hash from the response
   - `error` — any error message from the response
   - `ipn_callback_url` — the auto-generated webhook URL
   - `execute_at` — the scheduled time (if set)
   - `metadata` — custom metadata (if set)
4. **Persists individual withdrawal records** — if there are multiple withdrawals, `persistWithdrawals()` creates a `PayoutWithdrawal` record for each one, all linked to the parent `Payout` via `payout_id`.

### Persisting Withdrawals

Each withdrawal in a batch payout gets its own record:

```php
protected function persistWithdrawals(Payout $payout, array $withdrawals): void
{
    $withdrawalModelClass = PayoutWithdrawal::class;

    foreach ($withdrawals as $withdrawal) {
        $withdrawalRecord = new $withdrawalModelClass();
        $withdrawalRecord->fill([
            'payout_id' => $payout->id,
            'currency' => $withdrawal->currency ?? null,
            'amount' => $withdrawal->amount ?? 0,
            'address' => $withdrawal->address ?? null,
            'extra_id' => $withdrawal->extraId ?? null,
            'status' => strtolower($payout->status),
        ]);
        $withdrawalRecord->save();
    }
}
```

The `nowpayments_withdrawal_id` and `batch_withdrawal_id` fields are mapped from the API response to enable accurate webhook matching.

---

## 3. The Payout Model

The `Payout` model represents a payout operation. It uses ULIDs as primary keys and a configurable table prefix.

### Database Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `customer_id` | ULID | Foreign key to the Customer table |
| `billable_type` | string | Morph type (e.g., `App\Models\User`) |
| `billable_id` | mixed | Morph ID (the billable's primary key) |
| `nowpayments_payout_id` | string | Unique ID from NOWPayments API |
| `batch_withdrawal_id` | string | Batch ID for multi-withdrawal payouts |
| `status` | string | Current status (`creating`, `waiting`, `processing`, `sending`, `finished`, `failed`, `rejected`, `cancelled`) |
| `currency` | string | Currency code (e.g., `eth`, `btc`) |
| `amount` | decimal(20,8) | Payout amount |
| `address` | string | Destination wallet address |
| `extra_id` | string | Memo/tag/extra ID (for networks that require it) |
| `hash` | string | Transaction hash after processing |
| `error` | text | Error message if the payout failed |
| `ipn_callback_url` | string | The webhook URL sent to NOWPayments |
| `execute_at` | timestamp | Scheduled execution time |
| `processed_at` | timestamp | When the payout completed |
| `metadata` | JSON | Custom key-value data |
| `created_at` / `updated_at` | timestamp | Standard Laravel timestamps |

### Relationships

```php
// Owner customer
$payout->customer; // BelongsTo Customer

// Polymorphic billable model
$payout->billable; // MorphTo (e.g., User)

// Individual withdrawal records (for batch payouts)
$payout->withdrawals; // HasMany PayoutWithdrawal
```

### Scopes

```php
// Only successful (finished) payouts
User::payouts()->successful()->get();

// Only pending payouts (creating, waiting, processing, sending)
User::payouts()->pending()->get();

// Only failed payouts (failed, rejected)
User::payouts()->failed()->get();

// Only cancelled payouts
User::payouts()->cancelled()->get();
```

### Status Check Methods

```php
$payout->isSuccessful(); // true if status === 'finished'
$payout->isPending();    // true if status in ['creating', 'waiting', 'processing', 'sending']
$payout->isFailed();     // true if status in ['failed', 'rejected']
$payout->isCancelled();  // true if status === 'cancelled'
```

### Syncing Status

Manually refresh the payout status from the NOWPayments API:

```php
$payout->syncStatus();

// The method updates:
// - status
// - hash
// - error
// - processed_at (set to now() if status changed to 'finished')
```

### Cancelling a Payout

```php
if (! $payout->isSuccessful() && ! $payout->isCancelled()) {
    $payout->cancel();
}
```

Cancelling is a no-op if the payout is already finished or cancelled.

### Verifying a Payout (2FA)

If your NOWPayments account requires 2FA for payouts:

```php
$verified = $payout->verify('123456'); // 2FA verification code
```

---

## 4. The PayoutWithdrawal Model

The `PayoutWithdrawal` model represents an **individual withdrawal** within a batch payout. When a payout contains only one withdrawal, individual withdrawal records are still created for consistency.

### Database Schema

| Column | Type | Description |
|--------|------|-------------|
| `id` | ULID | Primary key |
| `payout_id` | ULID | Foreign key to the parent Payout |
| `nowpayments_withdrawal_id` | string | Individual withdrawal ID from NOWPayments |
| `currency` | string | Currency code |
| `amount` | decimal(20,8) | Withdrawal amount |
| `address` | string | Destination wallet address |
| `extra_id` | string | Memo/tag/extra ID |
| `status` | string | Withdrawal status |
| `hash` | string | Transaction hash |
| `error` | text | Error message if failed |
| `processed_at` | timestamp | When the withdrawal completed |
| `metadata` | JSON | Custom key-value data |
| `created_at` / `updated_at` | timestamp | Standard Laravel timestamps |

### Relationship

```php
$withdrawal->payout; // BelongsTo the parent Payout
```

### Status Check Methods

```php
$withdrawal->isSuccessful(); // true if status === 'finished'
$withdrawal->isPending();    // true if status in ['creating', 'waiting', 'processing', 'sending']
$withdrawal->isFailed();     // true if status in ['failed', 'rejected']
```

### Accessing Withdrawals from a Payout

```php
$payout = $user->payouts()->first();

foreach ($payout->withdrawals as $withdrawal) {
    echo "{$withdrawal->currency}: {$withdrawal->amount} to {$withdrawal->address}";
    echo " — Status: {$withdrawal->status}";
}
```

---

## 5. Billable Trait Methods

The `ManagesPayouts` trait provides these methods on your billable model:

### `$user->payout()` — Start the Builder

Returns a new `PayoutBuilder` instance. Automatically creates or retrieves the associated `Customer` record.

```php
$builder = $user->payout();
```

### `$user->payouts()` — Local Payouts Relationship

Returns a `HasMany` relationship to the billable's local `Payout` records (via the Customer model).

```php
// All payouts
$payouts = $user->payouts()->get();

// With filtering
$pendingPayouts = $user->payouts()->pending()->get();
$successfulPayouts = $user->payouts()->successful()->get();
```

### `$user->remotePayouts($filters)` — API Payout History

Fetches payout history from the NOWPayments API with local filtering to prevent data leakage in multi-tenant setups:

```php
// All remote payouts (filtered locally to this billable's customer)
$response = $user->remotePayouts();

// With API filters (e.g., pagination, date range)
$response = $user->remotePayouts([
    'limit' => 50,
    'page' => 1,
]);
```

**How local filtering works:**

When no `customer_id` filter is explicitly provided, the method:
1. Fetches all payouts from the NOWPayments API.
2. Retrieves the local `nowpayments_payout_id` values for this billable's payouts.
3. Filters the API response to only include payouts that exist locally.

This prevents other tenants' payout data from being exposed when the API key is shared.

---

## 6. Payout Utilities

### Validating a Payout Address

Before sending, verify that a wallet address is valid for a given currency:

```php
$isValid = $user->validatePayoutAddress(
    address: '0x742d35Cc6634C0532925a3b844Bc9e7595f5eE2B',
    currency: 'eth',
    extraId: null // optional, for networks that require a memo/tag
);

if (! $isValid) {
    throw new \Exception('Invalid wallet address for ETH.');
}
```

### Minimum Withdrawal Amount

Get the minimum withdrawal amount for a specific coin (static method):

```php
use App\Models\User;

$response = User::minimumWithdrawalAmount('eth');

echo $response->currency;  // "eth"
echo $response->minAmount; // minimum allowed amount
```

### Payout Fee Estimate

Get an estimate of the network fee for payouts (static method):

```php
use App\Models\User;

$response = User::payoutFeeEstimate();

echo $response->feeCurrency; // currency the fee is charged in
echo $response->feeAmount;   // estimated fee amount
```

---

## 7. Payout Webhook Handling

When NOWPayments processes your payout, it sends IPN (Instant Payment Notification) webhooks to the callback URL registered at payout creation time.

### Detection Logic

The `WebhookController` detects payout webhooks by the presence of `currency` and `address` fields **without** `payment_id` or `subscription_id`:

```php
if (isset($data['currency']) && isset($data['address']) && !isset($data['payment_id']) && !isset($data['subscription_id'])) {
    $this->handlePayout($data);
}
```

### Matching Logic

The `handlePayout()` method matches incoming webhook data to local records using either:

- `nowpayments_payout_id` — matched against `$data['id']` or `$data['batch_withdrawal_id']`
- `batch_withdrawal_id` — matched against `$data['batch_withdrawal_id']`

```php
$payoutId = $data['id'] ?? $data['batch_withdrawal_id'] ?? null;

$payout = Payout::where('nowpayments_payout_id', $payoutId)
    ->orWhere('batch_withdrawal_id', $data['batch_withdrawal_id'] ?? null)
    ->first();
```

### Updates Applied

When a matching payout is found, the following fields are updated:

```php
$payout->update([
    'status' => strtolower($data['status'] ?? $payout->status),
    'hash' => $data['hash'] ?? $payout->hash,
    'error' => $data['error'] ?? $payout->error,
    'processed_at' => $data['status'] === 'finished' && $payout->processed_at === null
        ? now()
        : $payout->processed_at,
]);
```

### Event Dispatched

After updating, the `PayoutStatusUpdated` event is dispatched:

```php
PayoutStatusUpdated::dispatch($payout, $data);
```

Listen to this event in your `EventServiceProvider` to trigger custom logic:

```php
protected $listen = [
    \SerenityTechnologies\CashierNowPayments\Events\PayoutStatusUpdated::class => [
        SendPayoutCompletedNotification::class,
        UpdateLedgerOnPayoutComplete::class,
    ],
];
```

### Signature Verification

All webhooks undergo dual verification:
1. **HMAC signature verification** via the `x-nowpayments-sig` header, using the `cashier-nowpayments.ipn_secret` config value.
2. **Timestamp validation** — webhooks older than the configured tolerance (default: 300 seconds) are rejected.

---

## 8. Common Patterns

### Single Withdrawal

The most common pattern — send to one address:

```php
$payout = $user->payout()
    ->to('0x742d35Cc6634C0532925a3b844Bc9e7595f5eE2B', 'eth', 1.5)
    ->send();
```

### Batch Payout

Chain multiple `->to()` calls for multi-recipient payouts:

```php
$payout = $user->payout()
    ->to('0xAddress1...', 'eth', 1.0)
    ->to('0xAddress2...', 'eth', 0.5)
    ->to('bc1qAddress...', 'btc', 0.01)
    ->withDescription('Weekly rewards pool distribution')
    ->withMetadata(['pool_id' => 7, 'period' => '2024-W42'])
    ->send();

// Access individual withdrawal records
foreach ($payout->withdrawals as $withdrawal) {
    // Each withdrawal tracked separately
}
```

### Scheduled Payout

Defer execution to a later time:

```php
use Carbon\Carbon;

$payout = $user->payout()
    ->to('0x742d35Cc6634C0532925a3b844Bc9e7595f5eE2B', 'eth', 2.0)
    ->scheduledFor(Carbon::now()->addHours(24))
    ->withDescription('Scheduled daily payout')
    ->send();
```

### Validate Before Sending

Always validate the address before creating the payout:

```php
$address = '0x742d35Cc6634C0532925a3b844Bc9e7595f5eE2B';
$currency = 'eth';

if (! $user->validatePayoutAddress($address, $currency)) {
    throw new \InvalidArgumentException("Invalid {$currency} address: {$address}");
}

$payout = $user->payout()
    ->to($address, $currency, 1.5)
    ->send();
```

### Check Minimum Amount

Ensure the payout meets the minimum withdrawal threshold:

```php
use App\Models\User;

$minAmount = User::minimumWithdrawalAmount('eth');

if ($amount < $minAmount->minAmount) {
    throw new \InvalidArgumentException(
        "Amount {$amount} is below minimum ({$minAmount->minAmount} ETH)"
    );
}
```

### Monitor Payout Status

Track payout progress and handle completions:

```php
// Check local status
$payout = $user->payouts()->latest()->first();

if ($payout->isPending()) {
    // Sync with NOWPayments API for latest status
    $payout->syncStatus();
}

if ($payout->isSuccessful()) {
    echo "Payout completed. TX hash: {$payout->hash}";
}

if ($payout->isFailed()) {
    echo "Payout failed: {$payout->error}";
}
```

### Cancel a Pending Payout

```php
$payout = $user->payouts()->pending()->first();

if ($payout && ! $payout->isSuccessful() && ! $payout->isCancelled()) {
    $payout->cancel();
}
```

### Retrieve Full Payout History from API

```php
// Remote payouts filtered to this user's local records
$response = $user->remotePayouts(['limit' => 100]);

foreach ($response->data as $payoutData) {
    echo "Remote payout: {$payoutData->id} — {$payoutData->status}";
}
```

---

## Events Reference

| Event | Dispatched When |
|-------|----------------|
| `PayoutCreated` | A payout is successfully created via the API (`create()` or `send()`) |
| `PayoutStatusUpdated` | A webhook updates the status of a local payout |

### Event Payloads

**`PayoutCreated`**:
```php
public readonly object $billable;
public readonly object $customer;
public readonly PayoutResponse $payoutResponse;
```

**`PayoutStatusUpdated`**:
```php
public readonly Payout $payout;
public readonly array $webhookData;
```

---

## Embedded Widget for Payout Confirmations

When paying out to recipients, you can use the embedded payment widget for a better UX if the recipient needs to confirm payout details:

```php
// For recipient-facing payout confirmation
return redirect()->route('cashier-nowpayments.checkout.embedded', [
    'amount' => $payout->amount,
    'currency' => $payout->currency,
    'description' => 'Payout to ' . $payout->address,
    'order_id' => 'PAYOUT-' . $payout->id,
    'success_url' => route('payouts.confirmation'),
    'cancel_url' => route('payouts.show', $payout),
    'metadata' => [
        'payout_id' => $payout->id,
        'type' => 'payout_confirmation',
    ],
]);
```

---

## Configuration

Ensure these environment variables are set for payouts to work:

```env
NOWPAYMENTS_API_KEY=your_api_key
NOWPAYMENTS_IPN_SECRET=your_ipn_secret_for_webhook_verification

CASHIER_NOWPAYMENTS_WEBHOOK_PATH=/nowpayments/webhook
```

The `ipn_secret` is required for HMAC signature verification on incoming webhooks. Without it, signature verification is skipped (but logged).

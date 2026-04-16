# Eloquent Models

Laravel Cashier NOWPayments provides nine Eloquent models that represent the core entities of the subscription and payment lifecycle. All models use **ULID primary keys** (via `HasUlids`) and resolve their table names from the `cashier-nowpayments.prefix` config value (default: `cashier_nowpayments_`).

---

## Customer

- **Class:** `SerenityTechnologies\CashierNowPayments\Models\Customer`
- **Table:** `{prefix}customers`
- **Primary Key:** ULID
- **Traits:** `SoftDeletes`, `HasUlids`

### Key Columns

| Column | Type | Notes |
|---|---|---|
| `billable_id` | ULID | Polymorphic owner |
| `billable_type` | string | Class of the owner (e.g. `App\Models\User`) |
| `metadata` | JSON | Arbitrary metadata |
| `trial_ends_at` | datetime | Global trial expiration |
| `deleted_at` | datetime | Soft delete timestamp |

### Casts

```php
protected $casts = [
    'metadata' => 'array',
    'trial_ends_at' => 'datetime',
];
```

### Relationships

| Method | Type | Returns |
|---|---|---|
| `billable()` | `MorphTo` | The owning model (e.g. `User`) |
| `subscriptions()` | `HasMany` | `Subscription` records |
| `payments()` | `HasMany` | `Payment` records |
| `invoices()` | `HasMany` | `Invoice` records |
| `payouts()` | `HasMany` | `Payout` records |
| `credits()` | `HasMany` | `Credit` ledger entries |

### Methods

#### `creditBalance(bool $forceRefresh = false): string`

Returns the sum of all unapplied, non-expired credits. Results are cached on the model instance to improve performance during complex calculations.

```php
$balance = $customer->creditBalance(); // "15.50000000"

// Force a fresh database query
$balance = $customer->creditBalance(true);
```

#### `getOriginalAmountForCredit(Model $credit): string`

Returns the originally issued amount for a credit. For partially consumed credits this reads `metadata['original_amount']`; otherwise it returns the current `amount`.

```php
$original = $customer->getOriginalAmountForCredit($credit);
```

#### `applyCredits(float $chargeAmount): array{covered: string, remaining: string}`

Consumes credits in **FIFO order** (oldest first) up to the charge amount. Uses **pessimistic locking** (`lockForUpdate()`) to prevent race conditions when multiple processes attempt to apply credits simultaneously.

Credits are consumed atomically. A credit that is partially consumed retains its remaining balance in the `amount` column and records each partial consumption in `metadata['partial_applications']`. Fully consumed credits have `applied_at` set and `metadata['fully_applied'] = true`.

```php
$result = $customer->applyCredits(50.00);
// ['covered' => '35.00', 'remaining' => '15.00']

// $covered is what credits paid, $remaining is what still needs to be charged
```

#### `clearCreditBalanceCache(): void`

Manually clears the internal credit balance cache.

```php
$customer->clearCreditBalanceCache();
```

#### `expireCredits(): int`

Marks all unapplied credits past their `expires_at` as expired by setting their `expired_at` timestamp. Dispatches a `CreditExpired` event with the expired credits, count, and total amount. Unlike consumption, expiration does not set the `applied_at` column.

```php
$count = $customer->expireCredits(); // number of credits expired
```

#### `onTrial(): bool`

```php
if ($customer->onTrial()) {
    // trial is still active
}
```

#### `subscribed(string $type = 'default', ?string $planId = null): bool`

Checks whether the customer has an active subscription of the given type, optionally filtered by plan ID. Accepts all active status variants from the NOWPayments API: `paid`, `active`, `waiting_pay`, `waiting`, `confirming`.

```php
if ($customer->subscribed()) { ... }
if ($customer->subscribed('premium', 'plan_abc123')) { ... }
```

#### `subscription(string $type = 'default'): ?Subscription`

Returns the first subscription matching the type, or `null`.

```php
$subscription = $customer->subscription('premium');
```

#### `hasIncompletePayment(): bool`

Returns `true` if any payment is in `waiting`, `confirming`, or `partially_paid` status.

```php
if ($customer->hasIncompletePayment()) {
    // prompt user to complete payment
}
```

---

## Payment

- **Class:** `SerenityTechnologies\CashierNowPayments\Models\Payment`
- **Table:** `{prefix}payments`
- **Primary Key:** ULID
- **Traits:** `HasUlids`

### Casts

```php
protected $casts = [
    'fee' => 'array',
    'metadata' => 'array',
    'paid_at' => 'datetime',
    'refunded_at' => 'datetime',
];
```

### Relationships

| Method | Type | Returns |
|---|---|---|
| `customer()` | `BelongsTo` | `Customer` |
| `billable()` | `MorphTo` | The owning model |
| `subscription()` | `BelongsTo` | `Subscription` |

### Scopes

```php
Payment::scopeSuccessful()->get();       // status = 'finished'
Payment::scopePending()->get();          // status IN (waiting, confirming, confirmed, sending, partially_paid)
Payment::scopeFailed()->get();           // status IN (failed, expired)
Payment::scopeForSubscription($id)->get(); // subscription_id = $id
```

### Methods

#### `isSuccessful(): bool`

```php
$payment->isSuccessful(); // status === 'finished'
```

#### `isPending(): bool`

```php
$payment->isPending(); // status in waiting, confirming, confirmed, sending, partially_paid
```

#### `isFailed(): bool`

```php
$payment->isFailed(); // status in failed, expired
```

#### `isRefunded(): bool`

```php
$payment->isRefunded(); // status === 'refunded' || refunded_at is not null
```

#### `syncStatus(): self`

Fetches the latest status from the NOWPayments API and updates the local record. Dispatches a `PaymentStatusSynced` event.

```php
$payment->syncStatus();
```

#### `refund(?string $reason = null): self`

Marks the payment as refunded locally and dispatches a `PaymentRefunded` event. Throws `InvalidArgumentException` if the payment is not in `finished` status or is already refunded.

> **Note:** NOWPayments does not provide a direct refund API endpoint. Refunds must be initiated via the dashboard or by contacting support. This method updates the local record only.

```php
$payment->refund('Customer requested cancellation');
```

---

## Invoice

- **Class:** `SerenityTechnologies\CashierNowPayments\Models\Invoice`
- **Table:** `{prefix}invoices`
- **Primary Key:** ULID
- **Traits:** `HasUlids`

### Casts

```php
protected $casts = [
    'metadata' => 'array',
    'paid_at' => 'datetime',
    'expires_at' => 'datetime',
];
```

### Relationships

| Method | Type | Returns |
|---|---|---|
| `customer()` | `BelongsTo` | `Customer` |
| `billable()` | `MorphTo` | The owning model |
| `payments()` | `HasMany` | `Payment` records (joined on `order_id`) |

The `payments()` relationship joins on `order_id` rather than a foreign key, allowing you to retrieve all payments associated with the same invoice order.

```php
$invoice->payments; // Collection<Payment> where order_id matches
```

### Methods

#### `isPaid(): bool`

Returns `true` if status is `paid` **or** `finished`.

```php
if ($invoice->isPaid()) { ... }
```

#### `isActive(): bool`

```php
$invoice->isActive(); // status === 'active'
```

#### `isExpired(): bool`

Checks both the `expires_at` timestamp and the `status` column.

```php
$invoice->isExpired(); // true if expires_at is in the past OR status is 'expired'
```

#### `redirect(): RedirectResponse`

Redirects the user to the invoice's payment URL.

```php
public function showInvoice(Invoice $invoice)
{
    return $invoice->redirect();
}
```

---

## Subscription

- **Class:** `SerenityTechnologies\CashierNowPayments\Models\Subscription`
- **Table:** `{prefix}subscriptions`
- **Primary Key:** ULID
- **Traits:** `SoftDeletes`, `HasUlids`

### Key Columns

| Column | Type | Notes |
|---|---|---|
| `customer_id` | ULID | FK to customers |
| `nowpayments_subscription_id` | string | Remote ID on NOWPayments |
| `nowpayments_plan_id` | string | Remote plan ID |
| `type` | string | Subscription type (default: `default`) |
| `status` | string | NOWPayments status |
| `total_price` | decimal(2) | Total subscription price |
| `currency` | string | Currency code |
| `quantity` | int | Subscription quantity |
| `interval_days` | int | Billing interval in days |
| `trial_ends_at` | datetime | Trial end |
| `renews_at` | datetime | Next billing date |
| `cancels_at` | datetime | When cancellation was requested |
| `ends_at` | datetime | When the subscription actually ends |
| `metadata` | JSON | Arbitrary metadata |

### Casts

```php
protected $casts = [
    'metadata' => 'array',
    'trial_ends_at' => 'datetime',
    'ends_at' => 'datetime',
    'renews_at' => 'datetime',
    'cancels_at' => 'datetime',
    'total_price' => 'decimal:8',
];
```

### Relationships

| Method | Type | Returns |
|---|---|---|
| `customer()` | `BelongsTo` | `Customer` |
| `items()` | `HasMany` | `SubscriptionItem` records |
| `payments()` | `HasMany` | `Payment` records |
| `credits()` | `HasMany` | `Credit` records |
| `plan()` | `BelongsTo` | `Plan` record (synced via `nowpayments_plan_id`) |

### Scopes

```php
Subscription::scopeActive()->get();    // ends_at IS NULL
Subscription::scopeOnTrial()->get();   // trial_ends_at > now
Subscription::scopeCancelled()->get(); // ends_at IS NOT NULL
Subscription::scopeExpired()->get();   // status = 'expired'
```

### Methods

#### State Checks

```php
$subscription->isActive();        // ends_at IS NULL
$subscription->isOnTrial();       // trial_ends_at is in the future
$subscription->isCancelled();     // ends_at IS NOT NULL
$subscription->isExpired();       // status === 'expired'
$subscription->hasIncompletePayment(); // has payments in waiting/confirming/partially_paid
```

#### `cancel(): self`

Cancels the subscription at the **end of the current billing period**. Calls the NOWPayments API to delete the remote subscription, sets `cancels_at` to now, and sets `ends_at` to `renews_at` (or `now + interval_days` as a fallback). Dispatches `SubscriptionCancelled`.

```php
$subscription->cancel();
// Subscription remains active until the end of the billing period
```

#### `cancelNow(): self`

Cancels the subscription **immediately**. Sets both `cancels_at` and `ends_at` to now. Dispatches `SubscriptionCancelled`.

```php
$subscription->cancelNow();
```

#### `resume(): self`

Throws a `RuntimeException`. NOWPayments does not support resuming cancelled subscriptions -- the remote subscription is deleted when cancelled. Create a new subscription instead.

```php
try {
    $subscription->resume();
} catch (\RuntimeException $e) {
    // "NOWPayments does not support resuming cancelled subscriptions..."
}
```

#### `swap(string $newPlanId, ?string $prorationMode = null): self`

Swaps the subscription to a new plan using the specified proration mode. The entire operation runs in a **database transaction** to prevent partial failures.

**Proration Modes:**
- `CREDIT` (default) — Calculates prorated credit for the unused portion and issues a credit ledger entry.
- `IMMEDIATE` — Immediately charges or credits the difference. For upgrades, a checkout session is created for the remaining amount if credits don't cover it.
- `END_OF_PERIOD` — No proration; the new price is charged at the next renewal.
- `NONE` — No proration and no credits issued.

**Proration formula:**

```
proratedAmount = (remainingDays / totalBillingDays) * totalPrice
```
Where `remainingDays` is the days from now until `renews_at`, and `totalBillingDays` is derived from `interval_days` (defaulting to 30 from config).

The swap performs these steps atomically:

1. Calculates the prorated credit for the remaining billing period.
2. Deletes the current subscription on NOWPayments.
3. Creates a new subscription with the requested plan.
4. Updates the local record (`nowpayments_plan_id`, `nowpayments_subscription_id`, `total_price`, `renews_at`).
5. Updates all `SubscriptionItem` records with the new plan ID.
6. Records a `Credit` entry (if mode is `CREDIT`).
7. Dispatches `SubscriptionSwapped` (and `SubscriptionUpdated`) with details of the swap.

```php
$subscription->swap('new_plan_id');

// The credit from the old plan expires at renews_at
// and will be applied to future charges via applyCredits()
```

The credit metadata includes:

```php
'metadata' => [
    'old_price' => 29.99,
    'new_price' => 49.99,
    'prorated_amount' => 12.50,
    'difference' => '-20.00',
    'swap_type' => 'upgrade', // or 'downgrade' or 'lateral'
],
```

#### Quantity Methods

```php
$subscription->incrementQuantity(2); // adds 2
$subscription->decrementQuantity();  // subtracts 1
$subscription->updateQuantity(5);    // sets to 5 (minimum: 1)
```

> **Note:** Quantity updates are local-only. NOWPayments does not support quantity adjustments via API. To change the billed amount, use `swap()`.

---

## SubscriptionItem

- **Class:** `SerenityTechnologies\CashierNowPayments\Models\SubscriptionItem`
- **Table:** `{prefix}subscription_items`
- **Primary Key:** ULID
- **Traits:** `HasUlids`

Represents individual line items within a subscription. Each item tracks its own `nowpayments_plan_id` so that plan swaps can update all items atomically.

### Relationships

| Method | Type | Returns |
|---|---|---|
| `subscription()` | `BelongsTo` | `Subscription` |

### Usage

```php
// Access items through the subscription
$items = $subscription->items;

foreach ($items as $item) {
    echo $item->nowpayments_plan_id;
}
```

---

## Payout

- **Class:** `SerenityTechnologies\CashierNowPayments\Models\Payout`
- **Table:** `{prefix}payouts`
- **Primary Key:** ULID
- **Traits:** `HasUlids`

Represents an outgoing payout to an external wallet. A single payout may contain multiple individual withdrawals (tracked via `PayoutWithdrawal`).

### Key Columns

| Column | Type | Notes |
|---|---|---|
| `customer_id` | ULID | FK to customers |
| `nowpayments_payout_id` | string | Remote ID on NOWPayments |
| `status` | string | Payout status |
| `hash` | string | Transaction hash after processing |
| `error` | string | Error message if failed |
| `execute_at` | datetime | Scheduled execution time |
| `processed_at` | datetime | When the payout was completed |
| `metadata` | JSON | Arbitrary metadata |

### Casts

```php
protected $casts = [
    'metadata' => 'array',
    'execute_at' => 'datetime',
    'processed_at' => 'datetime',
];
```

### Relationships

| Method | Type | Returns |
|---|---|---|
| `customer()` | `BelongsTo` | `Customer` |
| `billable()` | `MorphTo` | The owning model |

### Scopes

```php
Payout::scopeSuccessful()->get();  // status = 'finished'
Payout::scopePending()->get();     // status IN (creating, waiting, processing, sending)
Payout::scopeFailed()->get();      // status IN (failed, rejected)
Payout::scopeCancelled()->get();   // status = 'cancelled'
```

### Methods

#### State Checks

```php
$payout->isSuccessful(); // status === 'finished'
$payout->isPending();    // status in creating, waiting, processing, sending
$payout->isFailed();     // status in failed, rejected
$payout->isCancelled();  // status === 'cancelled'
```

#### `syncStatus(): self`

Fetches the latest status from NOWPayments and updates the local record.

```php
$payout->syncStatus();
```

#### `cancel(): self`

Cancels the payout via the NOWPayments API. No-op if the payout is already successful or cancelled.

```php
$payout->cancel();
```

#### `verify(string $verificationCode): bool`

Verifies the payout with a 2FA code.

```php
$verified = $payout->verify('123456');
```

---

## PayoutWithdrawal

- **Class:** `SerenityTechnologies\CashierNowPayments\Models\PayoutWithdrawal`
- **Table:** `{prefix}payout_withdrawals`
- **Primary Key:** ULID
- **Traits:** `HasUlids`

Tracks individual withdrawals within a batch payout. A single `Payout` can have many `PayoutWithdrawal` records, each representing a transfer to a specific address.

### Casts

```php
protected $casts = [
    'amount' => 'decimal:20,8',
    'metadata' => 'array',
    'processed_at' => 'datetime',
];
```

### Relationships

| Method | Type | Returns |
|---|---|---|
| `payout()` | `BelongsTo` | `Payout` |

### Methods

```php
$withdrawal->isSuccessful(); // status === 'finished'
$withdrawal->isPending();    // status in creating, waiting, processing, sending
$withdrawal->isFailed();     // status in failed, rejected
```

### Usage

```php
// Access withdrawals through a payout
$withdrawals = $payout->payoutWithdrawals;

$totalPaid = $withdrawals
    ->filter(fn ($w) => $w->isSuccessful())
    ->sum('amount');
```

---

## Credit

- **Class:** `SerenityTechnologies\CashierNowPayments\Models\Credit`
- **Table:** `{prefix}credits`
- **Primary Key:** ULID
- **Traits:** `HasUlids`

Credit ledger entries track every credit event: plan swaps, refunds, and manual adjustments. Credits are consumed by `Customer::applyCredits()` in FIFO order.

### Key Columns

| Column | Type | Notes |
|---|---|---|
| `customer_id` | ULID | FK to customers |
| `subscription_id` | ULID | FK to subscriptions (nullable) |
| `reference_id` | ULID | Polymorphic reference |
| `reference_type` | string | Polymorphic type |
| `type` | string | `swap`, `refund`, or `adjustment` |
| `amount` | decimal(20,8) | Current remaining credit amount |
| `balance_before` | decimal(20,8) | Customer balance before this credit |
| `balance_after` | decimal(20,8) | Customer balance after this credit |
| `currency` | string | Currency code |
| `old_plan_id` | string | For swap credits: old plan |
| `new_plan_id` | string | For swap credits: new plan |
| `description` | string | Human-readable description |
| `applied_at` | datetime | When the credit was consumed (nullable) |
| `expired_at` | datetime | When the credit expired (nullable) |
| `expires_at` | datetime | Expiration date (nullable) |
| `metadata` | JSON | Arbitrary metadata |

### Casts

```php
protected $casts = [
    'amount' => 'decimal:8',
    'balance_before' => 'decimal:8',
    'balance_after' => 'decimal:8',
    'metadata' => 'array',
    'applied_at' => 'datetime',
    'expired_at' => 'datetime',
];
```

### Relationships

| Method | Type | Returns |
|---|---|---|
| `customer()` | `BelongsTo` | `Customer` |
| `subscription()` | `BelongsTo` | `Subscription` |
| `reference()` | `MorphTo` | The originating model |

### Scopes

```php
Credit::scopeSwaps()->get();       // type = 'swap'
Credit::scopeRefunds()->get();     // type = 'refund'
Credit::scopeAdjustments()->get(); // type = 'adjustment'
```

### Methods

```php
$credit->isSwap();       // type === 'swap'
$credit->isRefund();     // type === 'refund'
$credit->isAdjustment(); // type === 'adjustment'
```

### Usage

```php
// All credits for a customer
$credits = $customer->credits;

// Only swap credits
$swaps = $customer->credits()->swaps()->get();

// Credits that have not yet been applied
$available = $customer->credits()->whereNull('applied_at')->get();

// Expired but unapplied credits
$expired = $customer->credits()
    ->whereNull('applied_at')
    ->where('expires_at', '<=', now())
    ->get();
```

### Credit Lifecycle

1. **Creation:** Credits are created by plan swaps (automatic proration), refunds, or manual adjustments.
2. **Expiration:** Credits with an `expires_at` in the past are marked as applied via `Customer::expireCredits()`.
3. **Consumption:** Credits are consumed in FIFO order by `Customer::applyCredits()`, using pessimistic locking to prevent races.
4. **Partial consumption:** If a credit is only partially consumed, its `amount` is reduced and each consumption event is recorded in `metadata['partial_applications']`. The original amount is preserved in `metadata['original_amount']` for audit purposes.

---

## Plan

- **Class:** `SerenityTechnologies\CashierNowPayments\Models\Plan`
- **Table:** `{prefix}plans`
- **Primary Key:** ULID
- **Traits:** `HasUlids`

Locally cached subscription plans. Plans are synced from the NOWPayments API and used to avoid repeated API calls for plan details.

### Key Columns

| Column | Type | Notes |
|---|---|---|
| `nowpayments_plan_id` | string | Remote plan ID |
| `name` | string | Plan title |
| `amount` | decimal(2) | Plan price |
| `currency` | string | Currency code |
| `interval_days` | int | Billing interval |
| `status` | string | Plan status |
| `success_url` | string | Redirect after successful payment |
| `cancel_url` | string | Redirect after cancelled payment |
| `metadata` | JSON | Arbitrary metadata |

### Casts

```php
protected $casts = [
    'amount' => 'decimal:2',
    'metadata' => 'array',
];
```

### Relationships

| Method | Type | Returns |
|---|---|---|
| `subscriptionItems()` | `HasMany` | `SubscriptionItem` (joined on `nowpayments_plan_id`) |
| `subscriptions()` | `HasMany` | `Subscription` (joined on `nowpayments_plan_id`) |

### Scopes

```php
Plan::scopeActive()->get(); // status = 'active'
```

### Methods

#### `isActive(): bool`

```php
$plan->isActive(); // status === 'active'
```

#### `syncFromApi(): self`

Fetches the latest plan details from NOWPayments and updates the local record.

```php
$plan->syncFromApi();
```

### Usage

```php
// List all active plans
$plans = Plan::active()->get();

// Sync a plan from the API
$plan = Plan::where('nowpayments_plan_id', 'plan_123')->first();
$plan->syncFromApi();

// Find subscriptions on a specific plan
$subscriptions = $plan->subscriptions;
```

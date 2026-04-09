# How-To: Subscription Plans & Recurring Payments

This guide covers the complete lifecycle of subscription plans and recurring payments in the Laravel Cashier NOWPayments package -- from creating plans, subscribing users, managing active subscriptions, swapping plans with proration, to handling webhook-driven status updates.

---

## Table of Contents

1. [Creating Plans](#1-creating-plans)
2. [Creating Subscriptions](#2-creating-subscriptions)
3. [Managing Subscriptions](#3-managing-subscriptions)
4. [Proration Logic](#4-proration-logic)
5. [Querying Subscriptions](#5-querying-subscriptions)
6. [Subscription Model Reference](#6-subscription-model-reference)
7. [Subscription Webhook Handling](#7-subscription-webhook-handling)
8. [Full Subscription Lifecycle Example](#8-full-subscription-lifecycle-example)

---

## 1. Creating Plans

Plans define the billing parameters for recurring subscriptions: amount, currency, interval, and redirect URLs. Plans are created via the **PlanBuilder** and are persisted both on the NOWPayments API and in your local database.

### Quick Start

```php
use App\Models\User;

$user = User::find(1);

$plan = $user->newPlan('monthly-pro')
    ->withName('Monthly Pro Plan')
    ->withAmount(29.99)
    ->withCurrency('usd')
    ->withIntervalDays(30)
    ->withSuccessUrl('https://example.com/subscription/active')
    ->withCancelUrl('https://example.com/subscribe')
    ->withMetadata(['tier' => 'pro', 'features' => 'unlimited'])
    ->create();
```

The `create()` method:

1. Sends a `PlanRequest` to the NOWPayments API (`POST /v1/subscriptions/recurrence`).
2. Receives a `PlanResponse` DTO containing the remote plan ID and details.
3. Persists the plan locally via `persistPlan()`, performing an **upsert** -- if a plan with the same `nowpayments_plan_id` already exists, it updates the existing record rather than creating a duplicate.

### Builder Methods

| Method | Parameters | Description |
|--------|-----------|-------------|
| `withName` | `string $name` | Human-readable plan name. Defaults to the plan ID. |
| `withAmount` | `float $amount` | Plan price. Defaults to `0`. |
| `withCurrency` | `string $currency` | Currency code. Defaults to `config('cashier-nowpayments.currency', 'usd')`. |
| `withIntervalDays` | `int $days` | Billing interval in days. Defaults to `30`. |
| `withSuccessUrl` | `string $url` | Redirect URL after successful subscription payment. |
| `withCancelUrl` | `string $url` | Redirect URL if subscription payment is cancelled. |
| `withMetadata` | `array $metadata` | Arbitrary key-value metadata attached to the plan. |

### Local Plan Model

The persisted `Plan` model stores these fields:

| Column | Type | Description |
|--------|------|-------------|
| `nowpayments_plan_id` | string | The plan's ID on NOWPayments. |
| `name` | string | Human-readable plan name. |
| `amount` | decimal(2) | Plan price. |
| `currency` | string | Currency code (e.g., `usd`). |
| `interval_days` | int | Billing interval in days. |
| `status` | string | Plan status (e.g., `active`). |
| `success_url` | string | Success redirect URL. |
| `cancel_url` | string | Cancel redirect URL. |
| `metadata` | json | Arbitrary metadata. |

### Static Plan Helpers

The `ManagesPlans` trait provides two static helpers on your billable model:

#### `User::listPlans(array $filters = [])`

Fetches all plans from the NOWPayments API:

```php
use App\Models\User;

$plans = User::listPlans();

foreach ($plans->plans as $plan) {
    echo $plan->id . ' - ' . $plan->title . ' (' . $plan->amount . ' ' . $plan->currency . ')';
}
```

#### `User::updatePlan(string $planId, array $data)`

Updates a plan on the NOWPayments API:

```php
use App\Models\User;

$response = User::updatePlan('monthly-pro', [
    'title' => 'Monthly Pro Plan (Updated)',
    'amount' => 34.99,
]);
```

> **Note:** `updatePlan()` updates the remote plan on NOWPayments. To update the local `Plan` record, you can either re-run the `PlanBuilder` (which performs an upsert) or call `syncFromApi()` on the local model:
>
> ```php
> $localPlan = Plan::where('nowpayments_plan_id', 'monthly-pro')->first();
> $localPlan->syncFromApi();
> ```

---

## 2. Creating Subscriptions

Subscriptions are created via the **SubscriptionBuilder**, which ties a billable user to a plan. The builder handles plan resolution, API subscription creation, local persistence, and event dispatching in one call.

### Quick Start

```php
use App\Models\User;

$user = User::find(1);

// Ensure a Customer record exists first
$customer = $user->createOrGetCustomer();

$subscription = $user->newSubscription('default', $planId)
    ->quantity(1)
    ->withTrialDays(14)
    ->withMetadata(['source' => 'checkout_page'])
    ->create();
```

### Builder Methods

| Method | Parameters | Description |
|--------|-----------|-------------|
| `quantity` | `int $quantity` | Subscription quantity (minimum 1). Throws `InvalidArgumentException` if less than 1. |
| `withTrialDays` | `int $days` | Trial period in days (minimum 1). Throws `InvalidArgumentException` if less than 1. |
| `withTrialUntil` | `Carbon $trialEndsAt` | Explicit trial end date. |
| `withMetadata` | `array $metadata` | Arbitrary key-value metadata. |

### What `create()` Does

1. **Fetches the plan** from NOWPayments via `NowPayments::getPlan($planId)`. If the plan does not exist, throws:
   ```
   InvalidArgumentException: Plan '12345' does not exist in NOWPayments. Create it via $user->newPlan()->create() first.
   ```
2. **Creates the subscription** on NOWPayments via `NowPayments::createSubscription($request)`.
3. **Persists locally** -- creates a `Subscription` record and an associated `SubscriptionItem` record.
4. **Dispatches** the `SubscriptionCreated` event with `($billable, $customer, $subscription, $response)`.

### Trial Support

Trials are tracked **locally only** -- the NOWPayments API does not natively support trial periods. The `trial_ends_at` column on the `Subscription` model stores the trial end date:

```php
// Trial specified as days
$subscription = $user->newSubscription('default', $planId)
    ->withTrialDays(14)
    ->create();
// trial_ends_at = now()->addDays(14)

// Trial specified as explicit date
use Carbon\Carbon;

$subscription = $user->newSubscription('default', $planId)
    ->withTrialUntil(Carbon::parse('2025-06-01'))
    ->create();
// trial_ends_at = 2025-06-01
```

Check if a subscription is still on trial:

```php
if ($subscription->isOnTrial()) {
    // trial_ends_at is in the future
}
```

---

## 3. Managing Subscriptions

The `Subscription` model provides methods for cancelling, resuming, swapping plans, and adjusting quantity.

### Cancelling a Subscription

#### `cancel()` -- Cancel at End of Billing Period

Deletes the subscription on NOWPayments and sets `ends_at` to the `renews_at` date (or calculates it from the plan's interval days as a fallback):

```php
$subscription = $user->subscription('default');
$subscription->cancel();

// ends_at = renews_at (or now()->addDays(interval_days) if renews_at is null)
// cancels_at = now()
// status = 'cancelled'
```

The `SubscriptionCancelled` event is dispatched.

#### `cancelNow()` -- Immediate Cancellation

Deletes the subscription on NOWPayments and sets `ends_at` to the current time:

```php
$subscription->cancelNow();

// ends_at = now()
// cancels_at = now()
// status = 'cancelled'
```

### Resuming a Subscription

The `resume()` method **always throws an exception** because NOWPayments does not support resuming cancelled subscriptions -- the remote subscription is deleted when cancelled:

```php
try {
    $subscription->resume();
} catch (\RuntimeException $e) {
    // "NOWPayments does not support resuming cancelled subscriptions.
    //  The remote subscription was deleted when cancelled.
    //  Create a new subscription instead."
}
```

To re-subscribe a cancelled user, create a new subscription:

```php
$newSubscription = $user->newSubscription('default', $planId)->create();
```

### Swapping Plans

The `swap()` method performs a full plan change flow inside a database transaction:

```php
$subscription->swap($newPlanId);
```

Steps performed atomically:

1. **Calculates prorated credit** from the remaining billing period of the old plan.
2. **Deletes the old subscription** on NOWPayments.
3. **Creates a new subscription** on NOWPayments with the new plan.
4. **Updates the local record** with the new plan ID, subscription ID, price, and currency.
5. **Updates subscription items** to reference the new plan.
6. **Records a credit ledger entry** with the prorated amount (if `customer_id` exists and credit > 0).
7. **Dispatches `SubscriptionUpdated`** with metadata including old/new plan IDs, prices, and prorated credit.

See [Proration Logic](#4-proration-logic) for details on the credit calculation.

### Adjusting Quantity

Quantity methods operate on the **local record only** -- NOWPayments subscriptions do not support quantity adjustments via API. To change the billed amount, use `swap()` to move to a different plan.

```php
// Increment by 1 (default)
$subscription->incrementQuantity();

// Increment by 3
$subscription->incrementQuantity(3);

// Decrement by 1 (default)
$subscription->decrementQuantity();

// Decrement by 2
$subscription->decrementQuantity(2);

// Set to a specific value
$subscription->updateQuantity(5);
// Throws InvalidArgumentException if quantity < 1
```

---

## 4. Proration Logic

When `swap()` is called, the package calculates a prorated credit for the unused portion of the current billing cycle.

### Formula

```
prorated_amount = (remaining_days / total_billing_days) * total_price
```

### Calculation Steps

The `calculateProratedCredit()` method:

1. Uses `renews_at` as the billing period end. If `renews_at` is null, returns `0.0` (no credit).
2. If the current time is past `renews_at`, returns `0.0` (no remaining value).
3. Calculates the period start: `period_start = renews_at - interval_days`.
4. Computes:
   - `total_days = period_start.diffInDays(renews_at)`
   - `remaining_days = now.diffInDays(renews_at)`
5. Applies the formula and rounds to 2 decimal places.

### Credit Ledger Entry

A `Credit` record is created when:

- `customer_id` is present on the subscription.
- The prorated credit is greater than zero.

The credit entry stores:

| Field | Description |
|-------|-------------|
| `balance_before` | Running balance before this credit (`SUM(amount)` on all existing credits). |
| `balance_after` | New balance after adding this credit (computed via `bcadd` for precision). |
| `amount` | The prorated credit amount. |
| `currency` | The subscription's currency. |
| `expires_at` | Set to `renews_at` -- credit expires at the end of the current billing cycle. |
| `metadata['swap_type']` | `upgrade`, `downgrade`, or `lateral` (based on price difference). |
| `metadata['old_price']` | Full price of the old plan. |
| `metadata['new_price']` | Full price of the new plan. |
| `metadata['prorated_amount']` | The prorated credit amount. |
| `metadata['difference']` | `old_price - new_price` (computed via `bcsub`). |

### Swap Type Classification

```php
$difference = old_price - new_price;

// difference > 0  => 'downgrade'  (moving to a cheaper plan, user gets a credit)
// difference < 0  => 'upgrade'    (moving to a more expensive plan)
// difference == 0 => 'lateral'    (same price, different plan)
```

### Precision

All monetary calculations use `bcmath` functions (`bcadd`, `bcsub`, `bccomp`) with 8-decimal precision to avoid floating-point errors.

---

## 5. Querying Subscriptions

The `Billable` trait provides several methods for querying subscription state.

### Get a Subscription by Type

```php
$subscription = $user->subscription('default');
```

Returns the `Subscription` model for the given type, or `null` if none exists.

### Get All Subscriptions

```php
$subscriptions = $user->subscriptions()->get();

// Each subscription is an Eloquent model:
foreach ($subscriptions as $sub) {
    echo $sub->type . ' - ' . $sub->status;
}
```

### Get Remote Subscriptions (from NOWPayments API)

```php
$response = $user->remoteSubscriptions();

foreach ($response->subscriptions as $remoteSub) {
    echo $remoteSub->id . ' - ' . $remoteSub->status;
}
```

### Check Trial Status

```php
if ($user->onTrial('default')) {
    // User is on trial
}

// Check trial for a specific plan
if ($user->onTrial('default', $planId)) {
    // User is on trial for this specific plan
}
```

The `onTrial()` method checks both the local Customer `trial_ends_at` and the Subscription `trial_ends_at`:

1. If `$user->customer->onTrial()` is true (customer-level trial), returns `true`.
2. Otherwise, checks if the subscription's `trial_ends_at` is in the future.

### Check Subscription Status

```php
if ($user->subscribed('default')) {
    // User has an active subscription
}

// Check for a specific plan
if ($user->subscribed('default', $planId)) {
    // User is subscribed to this specific plan
}
```

The `subscribed()` method on the `Customer` model checks against multiple active status variants to avoid false negatives from API status variations:

```php
$activeStatuses = ['paid', 'active', 'waiting_pay', 'waiting', 'confirming'];
```

A subscription is considered active when:
- `ends_at` is null (not cancelled), AND
- `status` is one of the active statuses above, AND
- (optionally) `nowpayments_plan_id` matches the specified `$planId`.

---

## 6. Subscription Model Reference

### Key Fields

| Column | Type | Description |
|--------|------|-------------|
| `nowpayments_plan_id` | string | ID of the plan on NOWPayments. |
| `nowpayments_subscription_id` | string | ID of the subscription on NOWPayments. |
| `type` | string | Subscription type identifier (e.g., `default`, `premium`). |
| `status` | string | Subscription status (e.g., `waiting_pay`, `paid`, `cancelled`, `expired`). |
| `currency` | string | Currency code. |
| `total_price` | decimal(2) | Total price of the subscription. |
| `quantity` | int | Number of seats/units. |
| `trial_ends_at` | datetime | When the trial period ends (null if no trial). |
| `ends_at` | datetime | When the subscription ends (null if active). |
| `renews_at` | datetime | When the next billing cycle renews. |
| `cancels_at` | datetime | When the subscription was cancelled. |
| `interval_days` | int | Billing interval in days (stored locally for proration). |
| `metadata` | json | Arbitrary metadata. |

### Scopes

```php
// Active subscriptions (ends_at is null)
$active = Subscription::active()->get();

// Subscriptions currently on trial (trial_ends_at > now)
$trials = Subscription::onTrial()->get();

// Cancelled subscriptions (ends_at is not null)
$cancelled = Subscription::cancelled()->get();

// Expired subscriptions (status = 'expired')
$expired = Subscription::expired()->get();
```

### Instance Methods

| Method | Returns | Description |
|--------|---------|-------------|
| `isActive()` | `bool` | True if `ends_at` is null. |
| `isOnTrial()` | `bool` | True if `trial_ends_at` is in the future. |
| `isCancelled()` | `bool` | True if `ends_at` is not null. |
| `isExpired()` | `bool` | True if `status` is `expired`. |
| `hasIncompletePayment()` | `bool` | True if any payments have status `waiting`, `confirming`, or `partially_paid`. |
| `cancel()` | `self` | Cancel at end of billing period. |
| `cancelNow()` | `self` | Cancel immediately. |
| `resume()` | throws | Throws `RuntimeException` (not supported by NOWPayments). |
| `swap($planId)` | `self` | Swap to a new plan with proration. |
| `incrementQuantity($count)` | `self` | Increase quantity locally. |
| `decrementQuantity($count)` | `self` | Decrease quantity locally. |
| `updateQuantity($qty)` | `self` | Set quantity locally. |

### Relationships

```php
$subscription->customer;   // BelongsTo -> Customer
$subscription->items;      // HasMany -> SubscriptionItem
$subscription->payments;   // HasMany -> Payment
$subscription->credits;    // HasMany -> Credit
```

---

## 7. Subscription Webhook Handling

The `WebhookController` handles incoming IPN webhooks from NOWPayments. Subscription events are processed in the `handleSubscription()` method.

### How It Works

1. The webhook payload is received and passes HMAC signature verification and timestamp validation.
2. If the payload contains a `subscription_id` or `plan_id`, `handleSubscription()` is called.
3. The subscription is looked up locally by `nowpayments_subscription_id`.
4. If found, the `status` is updated to match the webhook payload.
5. If the status changed, appropriate events are dispatched.

### Subscription Events Dispatched by Webhook

| Condition | Event Dispatched |
|-----------|-----------------|
| Status changed (any change) | `SubscriptionUpdated` |
| Status changed to `cancelled` or `expired` | `SubscriptionCancelled` |
| Status changed to `expired` | `SubscriptionExpired` |
| Status changed to `paid` (from a different status) | `SubscriptionRenewed` |

### Event Payloads

```php
// SubscriptionUpdated
event($subscription, $data);
// $data = raw webhook payload array

// SubscriptionCancelled
event($subscription, $data);

// SubscriptionExpired
event($subscription, $data);

// SubscriptionRenewed
event($subscription, $data);
```

### Listening to Events

Register event listeners in your `EventServiceProvider`:

```php
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCreated;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionUpdated;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCancelled;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionRenewed;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionExpired;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        SubscriptionCreated::class => [
            SendSubscriptionWelcomeEmail::class,
        ],
        SubscriptionUpdated::class => [
            LogPlanChange::class,
        ],
        SubscriptionCancelled::class => [
            SendCancellationEmail::class,
            RevokeAccess::class,
        ],
        SubscriptionRenewed::class => [
            SendRenewalConfirmation::class,
        ],
        SubscriptionExpired::class => [
            NotifyExpiredSubscription::class,
        ],
    ];
}
```

---

## 8. Full Subscription Lifecycle Example

This end-to-end example demonstrates the complete lifecycle: create a plan, subscribe a user, run through a trial period, swap to a different plan, cancel, and attempt to resume.

### Step 1: Create the Plans

```php
use App\Models\User;

$user = User::find(1);

// Basic plan
$basicPlan = $user->newPlan('basic-monthly')
    ->withName('Basic Monthly')
    ->withAmount(9.99)
    ->withCurrency('usd')
    ->withIntervalDays(30)
    ->create();

// Pro plan
$proPlan = $user->newPlan('pro-monthly')
    ->withName('Pro Monthly')
    ->withAmount(29.99)
    ->withCurrency('usd')
    ->withIntervalDays(30)
    ->create();
```

### Step 2: Subscribe the User

```php
// Ensure the user has a Customer record
$customer = $user->createOrGetCustomer();

$subscription = $user->newSubscription('default', $basicPlan->nowpayments_plan_id)
    ->withTrialDays(7)
    ->withMetadata(['signup_source' => 'homepage'])
    ->create();

echo $subscription->id;                       // Local ULID
echo $subscription->nowpayments_subscription_id;
echo $subscription->nowpayments_plan_id;      // basic-monthly's remote ID
echo $subscription->status;                   // 'waiting_pay'
echo $subscription->trial_ends_at;            // 7 days from now
echo $subscription->isOnTrial();              // true
```

The `SubscriptionCreated` event fires, which could trigger a welcome email.

### Step 3: Trial Period

During the 7-day trial, check trial status:

```php
if ($user->onTrial('default')) {
    // Grant trial access
    $user->grantTrialAccess();
}

// Check if the specific subscription is on trial
if ($subscription->isOnTrial()) {
    echo "Trial ends at: " . $subscription->trial_ends_at->format('Y-m-d');
}
```

After the trial ends, `isOnTrial()` returns `false` and normal billing begins.

### Step 4: Regular Billing

Once the trial ends, the subscription is active. The status will transition through `waiting_pay`, `paid`, etc. as NOWPayments processes recurring payments. Webhook notifications keep the local status in sync.

Check subscription health:

```php
if ($subscription->isActive()) {
    echo "Subscription is active, renews at: " . $subscription->renews_at;
}

if ($subscription->hasIncompletePayment()) {
    // User has a pending payment -- consider sending a reminder
}
```

### Step 5: Swap to a Higher Plan

The user wants to upgrade from Basic to Pro:

```php
$subscription->swap($proPlan->nowpayments_plan_id);

// What happened:
// 1. Prorated credit calculated for remaining Basic days
// 2. Old subscription deleted on NOWPayments
// 3. New subscription created with Pro plan
// 4. Local record updated (plan_id, subscription_id, total_price)
// 5. Credit ledger entry created with prorated amount
// 6. SubscriptionUpdated event dispatched

echo $subscription->nowpayments_plan_id;   // Now pro-monthly's remote ID
echo $subscription->total_price;           // 29.99
```

### Step 6: Cancel the Subscription

At the end of the billing period:

```php
$subscription->cancel();

echo $subscription->status;      // 'cancelled'
echo $subscription->cancels_at;  // now()
echo $subscription->ends_at;     // renews_at (or now()->addDays(30))
echo $subscription->isCancelled(); // true
echo $subscription->isActive();  // false (ends_at is set)
```

Or cancel immediately:

```php
$subscription->cancelNow();

echo $subscription->ends_at;  // now()
```

### Step 7: Attempt to Resume (Fails)

```php
try {
    $subscription->resume();
} catch (\RuntimeException $e) {
    echo $e->getMessage();
    // "NOWPayments does not support resuming cancelled subscriptions.
    //  The remote subscription was deleted when cancelled.
    //  Create a new subscription instead."
}

// Correct approach: create a new subscription
$newSubscription = $user->newSubscription('default', $proPlan->nowpayments_plan_id)
    ->create();
```

### Summary of the Lifecycle

```
Create Plans
  -> newPlan('basic-monthly')->withAmount(9.99)->create()
  -> newPlan('pro-monthly')->withAmount(29.99)->create()

Subscribe
  -> newSubscription('default', $planId)->withTrialDays(7)->create()
  -> SubscriptionCreated event dispatched

Trial Period
  -> onTrial('default') returns true
  -> isOnTrial() on subscription checks trial_ends_at > now

Active Billing
  -> subscribed('default') returns true
  -> isActive() on subscription checks ends_at === null
  -> Webhooks update status automatically

Swap Plan
  -> swap($newPlanId) in DB transaction
  -> Proration credit calculated and recorded
  -> SubscriptionUpdated event dispatched

Cancel
  -> cancel() or cancelNow()
  -> SubscriptionCancelled event dispatched
  -> ends_at set, isActive() returns false

Resume (Not Supported)
  -> resume() throws RuntimeException
  -> Must create a new subscription instead
```

---

## Events Reference

All subscription-related events are located in `SerenityTechnologies\CashierNowPayments\Events`:

| Event | Dispatched When | Payload |
|-------|----------------|---------|
| `SubscriptionCreated` | `SubscriptionBuilder::create()` | `($billable, $customer, $subscription, $response)` |
| `SubscriptionUpdated` | `swap()` or webhook status change | `($subscription, $data)` |
| `SubscriptionCancelled` | `cancel()`, `cancelNow()`, or webhook | `($subscription, $data)` |
| `SubscriptionRenewed` | Webhook: status changed to `paid` | `($subscription, $data)` |
| `SubscriptionExpired` | Webhook: status changed to `expired` | `($subscription, $data)` |

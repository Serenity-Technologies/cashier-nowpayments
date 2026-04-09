---
name: nowpayments-subscriptions
description: Create and manage recurring subscription plans and subscriptions using the Laravel Cashier NOWPayments package, including plan creation, user subscription, plan swaps with proration, and cancellation.
---

# NOWPayments Subscriptions & Plans

## When to use this skill

Use this skill when:
- Creating subscription plans on NOWPayments
- Subscribing users to recurring billing
- Swapping users between plans with automatic proration credits
- Managing subscription lifecycle (trial, cancel, resume)
- Checking subscription status for access control

## Billable Model Setup

```php
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

## Creating Plans

Use `PlanBuilder` via `$user->newPlan()`:

```php
$plan = $user->newPlan('premium-monthly')
    ->withName('Premium Monthly')
    ->withAmount(29.99)
    ->withCurrency('usd')
    ->withIntervalDays(30)
    ->withSuccessUrl('https://yoursite.com/welcome')
    ->withCancelUrl('https://yoursite.com/pricing')
    ->withMetadata(['tier' => 'premium'])
    ->create();  // Creates on NOWPayments + persists locally (upsert)

// Static helpers
User::listPlans();                    // List all plans from API
User::updatePlan($planId, [...]);     // Update plan on API
```

### PlanBuilder Methods

| Method | Purpose |
|--------|---------|
| `withName($name)` | Display name for the plan |
| `withAmount($amount)` | Price in the plan's currency |
| `withCurrency($currency)` | Currency code (usd, eur, etc.) |
| `withIntervalDays($days)` | Billing interval in days |
| `withSuccessUrl($url)` | Redirect after successful subscription |
| `withCancelUrl($url)` | Redirect after cancellation |
| `withMetadata($array)` | Additional metadata |
| `create()` | Create/update on API + persist locally |

## Subscribing Users

Use `SubscriptionBuilder` via `$user->newSubscription()`:

```php
$subscription = $user->newSubscription('default', $planId)
    ->quantity(1)
    ->withTrialDays(7)
    ->withMetadata(['source' => 'web'])
    ->create();  // Creates on NOWPayments + persists locally
```

### SubscriptionBuilder Methods

| Method | Purpose |
|--------|---------|
| `quantity($count)` | Number of seats (default: 1) |
| `withTrialDays($days)` | Trial period in days |
| `withTrialUntil($carbon)` | Trial ends at specific date |
| `withMetadata($array)` | Additional metadata |
| `create()` | Create on API + persist locally with SubscriptionItem |

## Managing Subscriptions

### Cancel

```php
// Cancel at end of billing period
$subscription->cancel();

// Cancel immediately
$subscription->cancelNow();
```

### Resume (Not Supported)

```php
// resume() throws RuntimeException — NOWPayments deletes cancelled subscriptions
// Create a new subscription instead:
$subscription = $user->newSubscription('default', $planId)->create();
```

### Swap Plans

```php
$subscription->swap($newPlanId);
```

The swap operation is wrapped in a DB transaction and performs:
1. Calculates prorated credit: `(remaining_days / total_billing_days) * total_price`
2. Deletes old subscription on NOWPayments
3. Creates new subscription on NOWPayments
4. Updates local record (plan ID, subscription ID, price, currency)
5. Records credit ledger entry with `balance_before`, `balance_after`, swap type
6. Dispatches `SubscriptionUpdated` event

### Quantity Management

```php
$subscription->incrementQuantity(2);  // Local only
$subscription->decrementQuantity();   // Local only
$subscription->updateQuantity(5);     // Local only
```

> NOWPayments API does not support quantity adjustments. These methods update the local record only. Use `swap()` to change the billed amount.

## Subscription Model

### Key Fields

| Field | Type | Description |
|-------|------|-------------|
| `nowpayments_plan_id` | string | Linked plan ID |
| `nowpayments_subscription_id` | string | NOWPayments subscription ID |
| `status` | string | Current status |
| `currency` | string | Billing currency |
| `total_price` | decimal | Plan price |
| `quantity` | int | Number of seats |
| `trial_ends_at` | datetime | Trial expiration |
| `ends_at` | datetime | Subscription end (null = active) |
| `renews_at` | datetime | Next billing date |
| `cancels_at` | datetime | When cancellation was requested |
| `interval_days` | int | Billing interval (stored locally) |

### Scopes

```php
Subscription::active()->get();      // ends_at IS NULL
Subscription::onTrial()->get();     // trial_ends_at > now()
Subscription::cancelled()->get();   // ends_at IS NOT NULL
Subscription::expired()->get();     // status = 'expired'
```

### Methods

```php
$subscription->isActive();          // ends_at === null
$subscription->isOnTrial();         // trial_ends_at is future
$subscription->isCancelled();       // ends_at !== null
$subscription->isExpired();         // status === 'expired'
$subscription->hasIncompletePayment();
```

### Relationships

```php
$subscription->customer;    // BelongsTo Customer
$subscription->items;       // HasMany SubscriptionItem
$subscription->payments;    // HasMany Payment
$subscription->credits;     // HasMany Credit
```

## Checking Subscription Status

```php
// Via Billable trait
$user->subscribed('default');                    // Is subscribed?
$user->subscribed('default', $planId);           // Is subscribed to specific plan?
$user->onTrial('default');                       // Is on trial?
$user->subscription('default');                  // Get subscription by type
$user->subscriptions();                          // All subscriptions
$user->remoteSubscriptions(['status' => 'paid']); // From NOWPayments API
```

## Subscription Webhooks

When NOWPayments sends subscription status updates, the `WebhookController` handles them automatically:

```php
// handleSubscription() fires these events based on status changes:
SubscriptionUpdated::class       // On any status change
SubscriptionCancelled::class     // When status becomes 'cancelled' or 'expired'
SubscriptionExpired::class       // When status is 'expired'
SubscriptionRenewed::class       // When status changes to 'paid'
```

## Events

| Event | When |
|-------|------|
| `SubscriptionCreated` | When `create()` is called |
| `SubscriptionUpdated` | On plan swap or webhook status change |
| `SubscriptionCancelled` | On `cancel()`, `cancelNow()`, or webhook |
| `SubscriptionExpired` | On webhook with 'expired' status |
| `SubscriptionRenewed` | On webhook with 'paid' status (after non-paid status) |

## Configuration

```env
CASHIER_NOWPAYMENTS_NOTIFY_SUBSCRIPTION_ACTIVATED=true
```

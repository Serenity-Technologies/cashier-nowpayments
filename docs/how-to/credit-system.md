# How to Use the Credit System

The Laravel Cashier NOWPayments package includes a full credit/ledger system that tracks plan swap proration credits, refunds, and manual adjustments. Credits are consumed in **FIFO order** (oldest first) against charges, with pessimistic locking to prevent race conditions.

---

## Table of Contents

1. [Credit Overview](#1-credit-overview)
2. [Credit Model](#2-credit-model)
3. [Checking Credit Balance](#3-checking-credit-balance)
4. [Applying Credits Against Charges](#4-applying-credits-against-charges)
5. [Plan Swap Credits](#5-plan-swap-credits)
6. [Using Credits with PaymentBuilder](#6-using-credits-with-paymentbuilder)
7. [Credit Expiration](#7-credit-expiration)
8. [Getting Original Credit Amount](#8-getting-original-credit-amount)
9. [Audit Trail](#9-audit-trail)
10. [Common Patterns](#10-common-patterns)

---

## 1. Credit Overview

### What Are Credits?

Credits are ledger entries that represent monetary value owed back to a customer. They are created automatically by the system in three scenarios:

| Source | Type | Trigger |
|---|---|---|
| **Plan swap proration** | `swap` | When a customer swaps their subscription plan (downgrade, upgrade, or lateral). The unused portion of the old plan is credited back. |
| **Refunds** | `refund` | When a payment is manually refunded via `$payment->refund()`. |
| **Manual adjustments** | `adjustment` | When you manually create a credit entry for a customer (e.g., goodwill credits, billing corrections). |

### Credit Lifecycle

Every credit entry tracks the following fields:

| Field | Description |
|---|---|
| `type` | One of `swap`, `refund`, or `adjustment`. |
| `amount` | The **current remaining** credit amount. This decreases as the credit is consumed. |
| `currency` | The currency of the credit (e.g., `usd`). |
| `balance_before` | The customer's total credit balance before this credit was issued (for audit trail). |
| `balance_after` | The customer's total credit balance after this credit was issued. |
| `applied_at` | Nullable timestamp. Set when the credit is **fully consumed**. Unapplied credits have `null`. |
| `expires_at` | Nullable timestamp. The deadline by which the credit must be used. Swap credits expire at `renews_at` (end of the current billing cycle). |

When a credit is created, `applied_at` is `null`. When it is fully consumed, `applied_at` is set to the current timestamp. Partially consumed credits retain a reduced `amount` and record each consumption event in their `metadata`.

---

## 2. Credit Model

### Class and Table

```php
use SerenityTechnologies\CashierNowPayments\Models\Credit;
```

- **Table:** `{prefix}credits` (default: `cashier_nowpayments_credits`)
- **Primary Key:** ULID
- **Traits:** `HasUlids`

### Key Columns

```php
$credit->customer_id;       // ULID — FK to customers table
$credit->subscription_id;   // ULID — FK to subscriptions (nullable)
$credit->type;              // string — 'swap', 'refund', or 'adjustment'
$credit->amount;            // decimal(20,8) — current remaining amount
$credit->currency;          // string — e.g., 'usd'
$credit->balance_before;    // decimal(20,8) — running balance before this credit
$credit->balance_after;     // decimal(20,8) — running balance after this credit
$credit->old_plan_id;       // string — for swap credits: the old plan
$credit->new_plan_id;       // string — for swap credits: the new plan
$credit->description;       // string — human-readable description
$credit->applied_at;        // datetime — when the credit was consumed (null = available)
$credit->expires_at;        // datetime — expiration deadline (null = no expiry)
$credit->metadata;          // JSON — audit data, partial applications, swap details
```

### Metadata Structure

The `metadata` JSON field stores different data depending on the credit type:

**Swap credits:**
```php
[
    'old_price' => 29.99,
    'new_price' => 49.99,
    'prorated_amount' => 12.50,
    'difference' => '-20.00',
    'swap_type' => 'upgrade', // 'upgrade', 'downgrade', or 'lateral'
]
```

**Partially consumed credits** (additional fields appended):
```php
[
    'original_amount' => '12.50000000',
    'total_consumed' => '7.50000000',
    'partial_applications' => [
        [
            'applied_at' => '2026-04-09T10:30:00+00:00',
            'amount_used' => '5.00000000',
            'remaining_after' => '7.50000000',
            'original_amount' => '12.50000000',
        ],
        // ... more entries
    ],
    'fully_applied' => true, // set only when fully consumed
]
```

### Relationships

```php
// The customer who owns this credit
$credit->customer; // BelongsTo Customer

// The subscription that generated this credit (for swap credits)
$credit->subscription; // BelongsTo Subscription

// Polymorphic reference to the originating model
$credit->reference; // MorphTo — e.g., the Payment that was refunded
```

### Scopes

```php
// Filter by credit type
$swaps = Credit::swaps()->get();
$refunds = Credit::refunds()->get();
$adjustments = Credit::adjustments()->get();

// Chain with customer queries
$customerSwapCredits = $customer->credits()->swaps()->get();
```

### Type-checking Methods

```php
$credit->isSwap();       // true if type === 'swap'
$credit->isRefund();     // true if type === 'refund'
$credit->isAdjustment(); // true if type === 'adjustment'
```

---

## 3. Checking Credit Balance

Use the `creditBalance()` method on the `Customer` model to get the sum of all unapplied, non-expired credits:

```php
$customer = $user->customer; // or however you resolve the Customer

$balance = $customer->creditBalance(); // returns string, e.g., "15.50000000"
```

### Important Notes

- Returns a **string** formatted to 8 decimal places (bcmath precision). Never a float.
- Only counts credits where `applied_at IS NULL` and (`expires_at IS NULL` or `expires_at > now()`).
- Returns `"0"` if no credits are available.

### Example: Display Available Balance

```php
$balance = $customer->creditBalance();

if (bccomp($balance, '0', 8) > 0) {
    echo "You have {$balance} in credits available.";
} else {
    echo "No credits available.";
}
```

### Example: Filter Credits Before Checking

If you need to check the balance for a specific credit type only:

```php
// Swap credits only
$swapBalance = $customer->credits()
    ->swaps()
    ->whereNull('applied_at')
    ->where(function ($query) {
        $query->whereNull('expires_at')
            ->orWhere('expires_at', '>', now());
    })
    ->sum('amount');

// Refund credits only
$refundBalance = $customer->credits()
    ->refunds()
    ->whereNull('applied_at')
    ->where(function ($query) {
        $query->whereNull('expires_at')
            ->orWhere('expires_at', '>', now());
    })
    ->sum('amount');
```

---

## 4. Applying Credits Against Charges

The `applyCredits()` method consumes credits in **FIFO order** (oldest first) against a given charge amount.

### Basic Usage

```php
$result = $customer->applyCredits(50.00);

// Returns:
// [
//     'covered' => '35.00000000',   // amount paid by credits
//     'remaining' => '15.00000000', // amount still to be charged
// ]
```

### How It Works

1. **FIFO ordering:** Credits are fetched ordered by `created_at ASC` (oldest first).
2. **Pessimistic locking:** The query uses `lockForUpdate()` to acquire a row-level lock, preventing race conditions when multiple processes try to apply credits simultaneously.
3. **Full consumption:** If a credit's amount is less than or equal to the remaining charge, the entire credit is consumed. `applied_at` is set to `now()` and `metadata['fully_applied'] = true`.
4. **Partial consumption:** If a credit's amount exceeds the remaining charge, only the needed portion is consumed. The credit's `amount` is reduced, and the consumption event is recorded in `metadata['partial_applications']`.
5. **bcmath arithmetic:** All calculations use bcmath functions (`bcadd`, `bcsub`, `bccomp`) to avoid floating-point precision issues.

### Partial Consumption Detail

When a credit is partially consumed, the following happens:

```php
// Before: credit.amount = 12.50
$result = $customer->applyCredits(5.00);

// After:
// credit.amount = 7.50000000 (reduced)
// credit.applied_at = null (still available)
// credit.metadata = [
//     'original_amount' => '12.50000000',
//     'total_consumed' => '5.00000000',
//     'partial_applications' => [
//         [
//             'applied_at' => '2026-04-09T10:30:00+00:00',
//             'amount_used' => '5.00000000',
//             'remaining_after' => '7.50000000',
//             'original_amount' => '12.50000000',
//         ],
//     ],
// ]
```

### Full Consumption Detail

```php
// Before: credit.amount = 5.00, applied_at = null
$result = $customer->applyCredits(5.00);

// After:
// credit.amount = 5.00000000 (unchanged)
// credit.applied_at = 2026-04-09 10:30:00 (set)
// credit.metadata['fully_applied'] = true
```

### Edge Cases

- **Zero or negative charge:** Returns `['covered' => '0', 'remaining' => $chargeAmount]` without touching any credits.
- **No available credits:** Returns `['covered' => '0', 'remaining' => $chargeAmount]`.
- **Credits exceed charge:** Only consumes what is needed. Returns `['covered' => $chargeAmount, 'remaining' => '0']`.

### Using Within a Transaction

Because `applyCredits()` uses `lockForUpdate()`, it must be called within a database transaction:

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($customer, $chargeAmount) {
    $result = $customer->applyCredits($chargeAmount);

    if (bccomp($result['remaining'], '0', 8) > 0) {
        // Charge the remaining amount via NOWPayments
        $user->charge((float) $result['remaining'], 'usd');
    }
});
```

---

## 5. Plan Swap Credits

When a customer swaps their subscription plan, the system automatically calculates a prorated credit for the unused portion of the current billing period.

### The Swap Flow

Calling `$subscription->swap($newPlanId)` performs these steps atomically in a database transaction:

1. **Calculate prorated credit:** Determines the unused value of the current plan.
2. **Delete old subscription:** Removes the subscription from NOWPayments.
3. **Create new subscription:** Creates a new subscription on NOWPayments with the new plan.
4. **Update local record:** Updates `nowpayments_plan_id`, `nowpayments_subscription_id`, `total_price`, `renews_at`.
5. **Update subscription items:** Sets the new plan ID on all `SubscriptionItem` records.
6. **Record credit entry:** Creates a `Credit` of type `swap` with the prorated amount.
7. **Dispatch event:** Fires `SubscriptionUpdated` with old/new plan IDs, prices, and prorated credit.

```php
$subscription->swap('premium_plan_v2');
```

### Proration Formula

```
proratedAmount = (remainingDays / totalBillingDays) * totalPrice
```

Where:

- **`remainingDays`** = days from now until `renews_at`
- **`totalBillingDays`** = `interval_days` (locally stored, defaults to 30)
- **`totalPrice`** = the subscription's `total_price`

The period start is calculated as `renews_at - interval_days`, ensuring proration is based on the current billing cycle rather than the subscription's creation date.

### Credit Expiration for Swaps

Swap credits have `expires_at` set to the subscription's `renews_at` (the end of the current billing cycle). This means:

- Credits from a swap **must be used before the next billing date**.
- If not consumed by then, `expireCredits()` will mark them as applied.

### Swap Type Classification

The system classifies each swap based on the price difference:

```php
$difference = oldPrice - newPrice;

if ($difference > 0) {
    $swapType = 'downgrade';  // customer is paying less, gets a credit
} elseif ($difference < 0) {
    $swapType = 'upgrade';    // customer is paying more, still gets prorated credit
} else {
    $swapType = 'lateral';    // same price, still gets prorated credit
}
```

This is stored in the credit's `metadata['swap_type']`.

### Example: Inspecting Swap Credits

```php
$swaps = $customer->credits()->swaps()->get();

foreach ($swaps as $credit) {
    echo "Plan swap: {$credit->old_plan_id} -> {$credit->new_plan_id}\n";
    echo "Prorated amount: {$credit->metadata['prorated_amount']}\n";
    echo "Swap type: {$credit->metadata['swap_type']}\n";
    echo "Expires at: {$credit->expires_at}\n";
    echo "---\n";
}
```

### Example: Swap with Zero Prorated Credit

If the swap happens after the `renews_at` date (i.e., the billing cycle has already ended), the prorated credit is `0` and no credit entry is created:

```php
// If renews_at is in the past, calculateProratedCredit() returns 0.0
// and no Credit record is created.
$subscription->swap('another_plan');
```

---

## 6. Using Credits with PaymentBuilder

The `PaymentBuilder` provides a `withCredits()` method that automatically consumes available credits before creating a charge on NOWPayments.

### Basic Usage

```php
$payment = $user->charge(50.00, 'usd')
    ->withCredits(true)
    ->charge();
```

### What Happens When Credits Are Applied

1. The builder calls `$customer->applyCredits($amount)` in FIFO order.
2. If credits **partially cover** the amount:
   - The remaining amount is charged via NOWPayments.
   - `metadata['credits_applied']` stores the amount covered by credits.
   - `metadata['original_amount']` stores the original charge amount.
3. If credits **fully cover** the amount:
   - A payment is still created for tracking purposes.
   - `metadata['credits_applied']` stores the full amount covered.
4. If credits **do not cover** any amount:
   - The full amount is charged via NOWPayments normally.

### Example: Partial Coverage

```php
// Customer has $15.00 in credits
$payment = $user->charge(50.00, 'usd')
    ->withCredits(true)
    ->withDescription('Premium plan charge')
    ->charge();

// Result:
// - $15.00 covered by credits (consumed from oldest first)
// - $35.00 charged via NOWPayments
// - $payment->metadata = [
//       'credits_applied' => '15.00000000',
//       'original_amount' => 35.0,  // the amount sent to NOWPayments
//   ]
```

### Example: Full Coverage

```php
// Customer has $60.00 in credits
$payment = $user->charge(50.00, 'usd')
    ->withCredits(true)
    ->withDescription('Order #12345')
    ->charge();

// Result:
// - $50.00 covered by credits
// - $0.00 charged via NOWPayments (payment created for tracking)
// - $payment->metadata = [
//       'credits_applied' => '50.00000000',
//   ]
```

### Example: No Coverage

```php
// Customer has no credits or credits are expired
$payment = $user->charge(50.00, 'usd')
    ->withCredits(true)
    ->charge();

// Result: full $50.00 charged via NOWPayments
```

### Checking Balance Before Charging

You can combine `creditBalance()` with `withCredits()` for conditional logic:

```php
$balance = $customer->creditBalance();

if (bccomp($balance, '0', 8) > 0) {
    $payment = $user->charge(50.00, 'usd')
        ->withCredits(true)
        ->withDescription("Using {$balance} in credits")
        ->charge();

    echo "Charged {$payment->metadata['credits_applied']} from credits.";
} else {
    $payment = $user->charge(50.00, 'usd')
        ->withDescription('No credits available')
        ->charge();
}
```

---

## 7. Credit Expiration

Credits with an `expires_at` timestamp are automatically expired when that time passes. The `expireCredits()` method marks all expired, unapplied credits as applied.

### Basic Usage

```php
$expiredCount = $customer->expireCredits();

// Returns: int — number of credits expired
```

### What Happens

1. All credits where `applied_at IS NULL` and `expires_at <= now()` are updated to set `applied_at = now()`.
2. If any credits were expired, a `CreditExpired` event is dispatched containing:
   - `$credits` — Collection of expired Credit models
   - `$count` — number of credits expired
   - `$totalAmount` — sum of the expired credit amounts (string)

### The CreditExpired Event

```php
use SerenityTechnologies\CashierNowPayments\Events\CreditExpired;

// Listen in your EventServiceProvider:
protected $listen = [
    CreditExpired::class => [
        SendCreditExpirationNotification::class,
    ],
];
```

Example listener:

```php
namespace App\Listeners;

use SerenityTechnologies\CashierNowPayments\Events\CreditExpired;

class SendCreditExpirationNotification
{
    public function handle(CreditExpired $event): void
    {
        // $event->count — number of expired credits
        // $event->totalAmount — total amount expired (string)
        // $event->credits — Collection of Credit models

        logger()->info("Expired {$event->count} credits totaling {$event->totalAmount}");

        // Notify the customer
        $customer = $event->credits->first()->customer;
        $customer->billable->notify(new CreditsExpiredNotification($event));
    }
}
```

### Scheduled Cron Job

To automatically expire credits on a schedule, add a command to your `Kernel.php`:

```php
// In app/Console/Kernel.php
protected function schedule(Schedule $schedule): void
{
    // Expire credits daily at midnight
    $schedule->call(function () {
        \SerenityTechnologies\CashierNowPayments\Models\Customer::query()
            ->each(function ($customer) {
                $customer->expireCredits();
            });
    })->daily();
}
```

Or use an Artisan command:

```php
// In routes/console.php
Artisan::command('credits:expire', function () {
    $totalExpired = 0;

    \SerenityTechnologies\CashierNowPayments\Models\Customer::query()
        ->each(function ($customer) use (&$totalExpired) {
            $totalExpired += $customer->expireCredits();
        });

    $this->info("Expired {$totalExpired} credits.");
})->daily();
```

Then schedule it:

```php
$schedule->command('credits:expire')->daily();
```

### Manual Expiration Check

You can also check expiration before performing an operation:

```php
$before = $customer->creditBalance();
$expired = $customer->expireCredits();

if ($expired > 0) {
    logger()->info("{$expired} credits expired for customer {$customer->id}");
}

$after = $customer->creditBalance();
```

---

## 8. Getting Original Credit Amount

For partially consumed credits, the `amount` column reflects the **remaining** balance, not the original issued amount. Use `getOriginalAmountForCredit()` to retrieve the original value.

### Basic Usage

```php
$originalAmount = $customer->getOriginalAmountForCredit($credit);
```

### How It Works

- If the credit has been **partially consumed**, `metadata['original_amount']` is returned.
- If the credit is **fully intact** (never consumed), the current `amount` is returned (formatted to 8 decimal places).
- Returns a **string** for bcmath compatibility.

### Example: Displaying Credit History

```php
$credits = $customer->credits()->get();

foreach ($credits as $credit) {
    $original = $customer->getOriginalAmountForCredit($credit);
    $remaining = number_format((float) $credit->amount, 2, '.', '');

    echo "Credit #{$credit->id}\n";
    echo "  Type: {$credit->type}\n";
    echo "  Original: {$original}\n";
    echo "  Remaining: {$remaining}\n";

    if ($credit->applied_at) {
        echo "  Status: Fully consumed at {$credit->applied_at}\n";
    } else {
        echo "  Status: Available\n";
    }
    echo "\n";
}
```

### Example: Calculating Consumption Percentage

```php
$credit = $customer->credits()->first();
$original = (float) $customer->getOriginalAmountForCredit($credit);
$remaining = (float) $credit->amount;
$consumed = $original - $remaining;
$percentage = ($consumed / $original) * 100;

echo "Credit is " . round($percentage, 1) . "% consumed\n";
```

---

## 9. Audit Trail

The credit system maintains a complete audit trail through metadata tracking. Every partial consumption event is recorded.

### Metadata Fields for Audit

| Field | Type | Description |
|---|---|---|
| `original_amount` | string | The amount the credit was issued with. Preserved for all partially consumed credits. |
| `total_consumed` | string | Running total of all amounts consumed from this credit. |
| `partial_applications` | array | Ordered list of consumption events. |
| `fully_applied` | bool | Set to `true` when the credit is fully consumed. |

### Partial Applications Structure

Each entry in `metadata['partial_applications']` contains:

```php
[
    'applied_at' => '2026-04-09T10:30:00+00:00',  // ISO 8601 timestamp
    'amount_used' => '5.00000000',                 // Amount consumed in this event
    'remaining_after' => '7.50000000',             // Balance after this consumption
    'original_amount' => '12.50000000',            // Original issued amount (duplicated for safety)
]
```

### Example: Tracing a Credit's Full History

```php
$credit = Credit::find('credit_ulid_here');

echo "Credit issued: {$credit->created_at}\n";
echo "Type: {$credit->type}\n";
echo "Original amount: {$credit->metadata['original_amount']}\n";
echo "Current amount: {$credit->amount}\n";
echo "Total consumed: {$credit->metadata['total_consumed']}\n";

if (isset($credit->metadata['partial_applications'])) {
    echo "\nConsumption history:\n";
    foreach ($credit->metadata['partial_applications'] as $i => $event) {
        echo "  {$i + 1}. {$event['amount_used']} used at {$event['applied_at']}";
        echo " (remaining: {$event['remaining_after']})\n";
    }
}

if ($credit->applied_at) {
    echo "\nFully consumed at: {$credit->applied_at}\n";
}
```

### Example: Reconciling Balances

You can verify the running balance at any point:

```php
$credits = $customer->credits()
    ->orderBy('created_at', 'asc')
    ->get();

$runningBalance = '0';

foreach ($credits as $credit) {
    $original = $customer->getOriginalAmountForCredit($credit);

    // Verify balance_before matches our running total
    if (bccomp($credit->balance_before, $runningBalance, 8) !== 0) {
        logger()->warning("Balance mismatch for credit {$credit->id}", [
            'expected' => $runningBalance,
            'got' => $credit->balance_before,
        ]);
    }

    $runningBalance = bcadd($runningBalance, $original, 8);
}

echo "Expected final balance: {$runningBalance}\n";
echo "Actual balance from query: {$customer->creditBalance()}\n";
```

### Balance Before/After on Swap Credits

For swap credits, `balance_before` and `balance_after` represent the customer's total credit balance at the moment the swap occurred:

```php
$swapCredit = $customer->credits()->swaps()->latest()->first();

echo "Balance before swap credit: {$swapCredit->balance_before}\n";
echo "Prorated amount issued: {$swapCredit->metadata['prorated_amount']}\n";
echo "Balance after swap credit: {$swapCredit->balance_after}\n";

// Verify: balance_after = balance_before + prorated_amount
$expected = bcadd($swapCredit->balance_before, number_format((float) $swapCredit->metadata['prorated_amount'], 8, '.', ''), 8);
assert(bccomp($expected, $swapCredit->balance_after, 8) === 0);
```

---

## 10. Common Patterns

### Checking Balance Before a Charge

```php
use SerenityTechnologies\CashierNowPayments\Models\Customer;

$customer = $user->customer;
$chargeAmount = 100.00;

$balance = $customer->creditBalance();

if (bccomp($balance, '0', 8) > 0) {
    echo "Available credits: {$balance}\n";

    // Let PaymentBuilder handle it automatically
    $payment = $user->charge($chargeAmount, 'usd')
        ->withCredits(true)
        ->withDescription('Order #ORD-12345')
        ->charge();

    $creditsUsed = $payment->metadata['credits_applied'] ?? '0';
    $charged = bccomp($creditsUsed, '0', 8) > 0
        ? bcsub(number_format($chargeAmount, 8, '.', ''), $creditsUsed, 8)
        : number_format($chargeAmount, 8, '.', '');

    echo "Credits applied: {$creditsUsed}\n";
    echo "Amount charged via NOWPayments: {$charged}\n";
} else {
    echo "No credits available. Full charge: {$chargeAmount}\n";
    $user->charge($chargeAmount, 'usd')->charge();
}
```

### Manual Credit Adjustment

To manually create a credit entry (e.g., goodwill credit, billing correction):

```php
use SerenityTechnologies\CashierNowPayments\Models\Credit;

// Create a manual credit adjustment
$credit = new Credit();
$credit->fill([
    'customer_id' => $customer->id,
    'subscription_id' => null, // or a specific subscription if relevant
    'type' => 'adjustment',
    'amount' => 25.00,
    'currency' => 'usd',
    'balance_before' => $customer->creditBalance(),
    'balance_after' => bcadd($customer->creditBalance(), '25.00000000', 8),
    'description' => 'Goodwill credit for service disruption on 2026-04-01',
    'metadata' => [
        'admin_id' => auth()->id(),
        'ticket_id' => 'SUP-4521',
        'reason' => 'service_disruption',
    ],
    // No expires_at — this credit does not expire
    // Or set expires_at to enforce a deadline:
    // 'expires_at' => now()->addMonths(3),
]);
$credit->save();
```

### Reporting: Credits by Type

```php
// Total swap credits issued this month
$swapTotal = Credit::swaps()
    ->where('created_at', '>=', now()->startOfMonth())
    ->sum('amount');

// Total refund credits this quarter
$refundTotal = Credit::refunds()
    ->where('created_at', '>=', now()->startOfQuarter())
    ->sum('amount');

// All adjustment credits for a specific customer
$adjustments = $customer->credits()
    ->adjustments()
    ->orderBy('created_at', 'desc')
    ->get();
```

### Reporting: Date Range Query

```php
$startDate = now()->subMonths(6);
$endDate = now();

$credits = Credit::where('customer_id', $customer->id)
    ->whereBetween('created_at', [$startDate, $endDate])
    ->orderBy('created_at', 'desc')
    ->get();

foreach ($credits as $credit) {
    $status = $credit->applied_at ? 'Consumed' : 'Available';
    echo "[{$credit->type}] {$credit->amount} {$credit->currency} — {$status} — {$credit->created_at}\n";
}
```

### Reporting: Available vs. Consumed

```php
$availableCredits = $customer->credits()
    ->whereNull('applied_at')
    ->where(function ($query) {
        $query->whereNull('expires_at')
            ->orWhere('expires_at', '>', now());
    })
    ->get();

$consumedCredits = $customer->credits()
    ->whereNotNull('applied_at')
    ->get();

echo "Available: {$availableCredits->count()} credits\n";
echo "Consumed: {$consumedCredits->count()} credits\n";
echo "Available balance: {$customer->creditBalance()}\n";
```

### Scheduled Expiration Cron

Set up a scheduled task to expire credits daily:

```php
// In app/Console/Commands/ExpireCredits.php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use SerenityTechnologies\CashierNowPayments\Models\Customer;

class ExpireCredits extends Command
{
    protected $signature = 'credits:expire';
    protected $description = 'Expire all past-due credits for all customers';

    public function handle(): int
    {
        $totalExpired = 0;
        $affectedCustomers = 0;

        Customer::query()
            ->each(function (Customer $customer) use (&$totalExpired, &$affectedCustomers) {
                $expired = $customer->expireCredits();
                if ($expired > 0) {
                    $totalExpired += $expired;
                    $affectedCustomers++;
                }
            });

        $this->info("Expired {$totalExpired} credits for {$affectedCustomers} customers.");

        return Command::SUCCESS;
    }
}
```

Schedule it in `Kernel.php`:

```php
protected function schedule(Schedule $schedule): void
{
    $schedule->command('credits:expire')->dailyAt('00:00');
}
```

### Combined: Check, Apply, and Charge

A complete flow that checks credits, applies them, and handles the remaining charge:

```php
use Illuminate\Support\Facades\DB;

public function processOrder(User $user, float $amount): array
{
    $customer = $user->customer;

    return DB::transaction(function () use ($customer, $amount) {
        // Step 1: Check available balance
        $balance = $customer->creditBalance();
        $originalAmount = number_format($amount, 2, '.', '');

        // Step 2: Expire any past-due credits first
        $customer->expireCredits();

        // Step 3: Apply credits against the charge
        $creditResult = $customer->applyCredits($amount);

        // Step 4: Charge remaining via NOWPayments (if any)
        $payment = null;
        if (bccomp($creditResult['remaining'], '0', 8) > 0) {
            $payment = $user->charge((float) $creditResult['remaining'], 'usd')
                ->withDescription("Order charge ({$creditResult['covered']} covered by credits)")
                ->charge();
        }

        return [
            'original_amount' => $originalAmount,
            'credits_applied' => $creditResult['covered'],
            'amount_charged' => $creditResult['remaining'],
            'payment_id' => $payment?->id,
            'nowpayments_id' => $payment?->nowpayments_payment_id,
        ];
    });
}
```

### Querying by Customer and Date Range

```php
// All credit activity for a customer in a date range
$credits = $customer->credits()
    ->whereBetween('created_at', ['2026-01-01', '2026-03-31'])
    ->orderBy('created_at', 'desc')
    ->get()
    ->map(function ($credit) use ($customer) {
        return [
            'id' => $credit->id,
            'type' => $credit->type,
            'original_amount' => $customer->getOriginalAmountForCredit($credit),
            'current_amount' => $credit->amount,
            'currency' => $credit->currency,
            'status' => $credit->applied_at ? 'consumed' : 'available',
            'created_at' => $credit->created_at,
            'expires_at' => $credit->expires_at,
        ];
    });
```

---

## Summary of Key Classes and Methods

| Class | Method | Purpose |
|---|---|---|
| `Customer` | `creditBalance(): string` | Get total available credit balance |
| `Customer` | `applyCredits(float): array` | Consume credits in FIFO order against a charge |
| `Customer` | `expireCredits(): int` | Mark expired credits as applied |
| `Customer` | `getOriginalAmountForCredit(Model): string` | Get original amount for partially consumed credits |
| `Subscription` | `swap(string): self` | Swap plan, creates prorated credit automatically |
| `PaymentBuilder` | `withCredits(bool): self` | Enable automatic credit application during charge |
| `Credit` | `isSwap()`, `isRefund()`, `isAdjustment()` | Type-checking methods |
| `Credit` | `scopeSwaps()`, `scopeRefunds()`, `scopeAdjustments()` | Query scopes by type |
| `CreditExpired` | Event | Dispatched when credits expire |

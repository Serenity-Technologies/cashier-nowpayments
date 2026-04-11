---
name: nowpayments-payouts
description: Send cryptocurrency payouts to external wallets using the Laravel Cashier NOWPayments package, including single withdrawals, batch payouts, scheduled payouts, and address validation.
---

# NOWPayments Payouts

## When to use this skill

Use this skill when:
- Sending crypto payouts to external wallet addresses
- Creating batch payouts for multiple recipients
- Scheduling payouts for future execution
- Validating wallet addresses before sending
- Checking minimum withdrawal amounts and fees

## Billable Model Setup

```php
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

## Creating Payouts

### Single Withdrawal

```php
$payout = $user->payout()
    ->to('0xAbC123...', 'eth', 1.5)
    ->withDescription('Affiliate commission')
    ->send();

echo $payout->status;    // 'creating', 'processing', 'finished', 'failed'
echo $payout->address;   // Recipient address
echo $payout->amount;    // Payout amount
```

### Batch Payout

```php
$payout = $user->payout()
    ->to('0xAbC...', 'eth', 1.0)
    ->to('0xDeF...', 'usdttrc20', 50.0)
    ->to('ltc1q...', 'ltc', 10.0)
    ->withDescription('Monthly affiliate payouts')
    ->send();

// Creates one Payout record + individual PayoutWithdrawal records
foreach ($payout->withdrawals as $withdrawal) {
    echo "{$withdrawal->address}: {$withdrawal->amount} {$withdrawal->currency}";
}
```

### Scheduled Payout

```php
$payout = $user->payout()
    ->to('0xAbC...', 'eth', 1.5)
    ->scheduledFor(now()->addHours(24))
    ->send();
```

## PayoutBuilder Methods

| Method | Purpose |
|--------|---------|
| `to($address, $currency, $amount, $extraId)` | Add withdrawal (chainable for batches) |
| `withDescription($text)` | Payout description |
| `scheduledFor($carbon)` | Schedule for future execution |
| `withMetadata($array)` | Additional metadata |
| `create()` | Create on API only (returns DTO) |
| `send()` | Create on API + persist locally (returns Payout model) |

## Payout Model

### Key Fields

| Field | Type | Description |
|-------|------|-------------|
| `nowpayments_payout_id` | string | NOWPayments payout ID |
| `batch_withdrawal_id` | string | Batch withdrawal ID (for batch payouts) |
| `status` | string | Payout status |
| `currency` | string | Cryptocurrency |
| `amount` | decimal | Payout amount |
| `address` | string | Recipient wallet address |
| `extra_id` | string | Memo/destination tag (for XRP, XMR, etc.) |
| `hash` | string | Transaction hash (when completed) |
| `error` | string | Error message (if failed) |
| `processed_at` | datetime | When payout was processed |
| `execute_at` | datetime | Scheduled execution time |

### Methods

```php
$payout->syncStatus();     // Sync with NOWPayments API
$payout->cancel();         // Cancel pending payout
$payout->verify();         // Verify payout status
```

## PayoutWithdrawal Model

Individual withdrawal records for batch payouts:

```php
$withdrawal->isSuccessful();  // status === 'finished'
$withdrawal->isPending();     // creating, waiting, processing, sending
$withdrawal->isFailed();      // failed, rejected
```

## Payout Utilities

### Validate Address

```php
$isValid = $user->validatePayoutAddress('0xAbC...', 'eth');
$isValid = $user->validatePayoutAddress('0xAbC...', 'xrp', 'destination_tag');
```

### Minimum Withdrawal

```php
$minAmount = User::minimumWithdrawalAmount('eth');
echo $minAmount->currency;   // 'eth'
echo $minAmount->min_amount; // 0.01
```

### Fee Estimate

```php
$fee = User::payoutFeeEstimate();
echo $fee->fee;  // Estimated fee
```

## Remote Payout History

```php
// Local payouts
$payouts = $user->payouts()->get();

// From NOWPayments API (with local billable filtering)
$remotePayouts = $user->remotePayouts(['limit' => 20]);

// With explicit filters
$remotePayouts = $user->remotePayouts([
    'date_from' => now()->subDays(30),
    'status' => 'finished',
]);
```

> `remotePayouts()` automatically filters results to only include payouts belonging to this billable model, preventing data leakage in multi-tenant setups.

## Payout Webhook Handling

When NOWPayments sends payout status updates:

```php
// WebhookController::handlePayout():
// 1. Matches by nowpayments_payout_id or batch_withdrawal_id
// 2. Updates status, hash, error, processed_at
// 3. Dispatches PayoutStatusUpdated event
```

## Events

| Event | When |
|-------|------|
| `PayoutCreated` | When `send()` or `create()` is called |
| `PayoutStatusUpdated` | When webhook updates payout status |

## Payout Recipient Payment Widget

When paying out to recipients, you can use the embedded widget for a better UX if the recipient needs to confirm payout details:

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

## Configuration

```env
CASHIER_NOWPAYMENTS_CURRENCY=usd   # Default currency for payouts
```

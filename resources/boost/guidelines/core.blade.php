## Laravel Cashier NOWPayments

This package provides a Laravel Cashier-style abstraction over the NOWPayments cryptocurrency API, enabling one-time payments, hosted invoices, recurring subscriptions, crypto payouts, and a credit system with FIFO consumption.

### Package Structure

```
src/
├── Billable.php                          # Main trait — aggregates all concerns
├── PaymentBuilder.php                    # Fluent builder for one-time payments
├── InvoiceBuilder.php                    # Fluent builder for hosted invoices
├── SubscriptionBuilder.php               # Fluent builder for recurring subscriptions
├── PayoutBuilder.php                     # Fluent builder for crypto payouts
├── PlanBuilder.php                       # Fluent builder for subscription plans
├── Concerns/
│   ├── ManagesCustomer.php               # Customer record management
│   ├── ManagesPayments.php               # Payment creation & querying
│   ├── ManagesInvoices.php               # Invoice creation & payment
│   ├── ManagesSubscriptions.php          # Subscription & plan management
│   ├── ManagesPayouts.php                # Payout creation & validation
│   ├── ManagesBalance.php                # Account balance
│   ├── ManagesCurrencies.php             # Currency listing (cached 1h)
│   ├── ManagesConversions.php            # Crypto-to-crypto conversions
│   ├── ManagesFiatPayouts.php            # Fiat payout providers & methods
│   ├── ManagesPlans.php                  # Plan listing & updating
│   └── ProvidesCheckoutHelpers.php       # checkoutButton() / checkoutUrl()
├── Models/
│   ├── Customer.php                      # Billable's customer record
│   ├── Payment.php                       # Payment records
│   ├── Invoice.php                       # Invoice records
│   ├── Subscription.php                  # Subscription records
│   ├── SubscriptionItem.php              # Subscription line items
│   ├── Payout.php                        # Payout records
│   ├── PayoutWithdrawal.php              # Individual batch withdrawal records
│   ├── Credit.php                        # Credit ledger entries
│   └── Plan.php                          # Locally cached plans
├── Http/
│   ├── Controllers/
│   │   ├── CheckoutController.php        # Checkout overlay + payment/invoice/subscription creation
│   │   ├── PaymentStatusController.php   # Remote & local payment status polling
│   │   └── WebhookController.php         # IPN webhook handler (HMAC + timestamp verified)
│   └── Middleware/
│       └── EnsurePaymentBelongsToUser.php # Auth middleware for payment status endpoints
├── Console/
│   └── InstallMigrationsCommand.php      # `php artisan cashier-nowpayments:install`
├── Events/                               # 17 events across 5 domains
├── Notifications/                        # 3 Laravel notifications
└── Support/
    └── GeneratesWebhookUrl.php           # Shared webhook URL generation trait
```

### Core Conventions

- All models use ULID primary keys and soft deletes where appropriate
- Tables use a configurable prefix (`cashier_nowpayments_` by default)
- Builder classes follow a fluent pattern: chain `with*()` methods, then call a terminal method
- Terminal methods: `create()` (API-only, returns DTO), `charge()`/`generate()`/`send()` (API + persist, returns model)
- All persistence operations in `charge()` are wrapped in `DB::transaction()`
- The `Billable` trait must be added to the model that represents your billable entity (typically `User`)
- Webhook route (`POST /nowpayments/webhook`) uses `api` middleware — no CSRF protection
- Payment status endpoints use the `nowpayments.payment.auth` middleware (configurable auth gate)

### Setup

Add the `Billable` trait to your model:

@verbatim
<code-snippet name="Billable Model Setup" lang="php">
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;
}
</code-snippet>
@endverbatim

### One-Time Payments

Create a payment using the fluent `PaymentBuilder`:

@verbatim
<code-snippet name="Create One-Time Payment" lang="php">
$payment = $user->newPayment(49.99, 'usd')
    ->withPayCurrency('btc')
    ->withDescription('Premium ebook')
    ->withOrderId('ORDER-123')
    ->withFixedRate()           // Lock rate for 20 min
    ->withFeePaidByUser()       // Payer covers network fee
    ->withCredits()             // Apply available credits first
    ->charge();                 // API + persist in DB transaction

echo $payment->pay_address;   // BTC deposit address
echo $payment->pay_amount;    // Amount in BTC
</code-snippet>
@endverbatim

`->create()` returns the API DTO without persisting. `->charge()` returns the `Payment` model.

### Hosted Invoices

Generate a NOWPayments hosted invoice page:

@verbatim
<code-snippet name="Create Invoice" lang="php">
$invoice = $user->invoice(49.99, 'usd')
    ->withDescription('Monthly membership')
    ->withOrderId('INV-456')
    ->withSuccessUrl('https://yoursite.com/success')
    ->withCancelUrl('https://yoursite.com/cancel')
    ->generate();

return redirect($invoice->invoice_url);
</code-snippet>
@endverbatim

### Subscriptions & Plans

Create a plan and subscribe a user:

@verbatim
<code-snippet name="Create Plan & Subscribe" lang="php">
// Create plan on NOWPayments + persist locally
$plan = $user->newPlan('premium-monthly')
    ->withName('Premium Monthly')
    ->withAmount(29.99)
    ->withCurrency('usd')
    ->withIntervalDays(30)
    ->create();

// Subscribe user with trial
$subscription = $user->newSubscription('default', $plan->id)
    ->withTrialDays(7)
    ->withMetadata(['source' => 'web'])
    ->create();
</code-snippet>
@endverbatim

Swap plans with automatic proration:

@verbatim
<code-snippet name="Swap Subscription Plan" lang="php">
$subscription->swap($newPlanId);
// Automatically: calculates prorated credit, deletes old sub on API,
// creates new sub, updates local record, records credit ledger entry
</code-snippet>
@endverbatim

### Crypto Payouts

Send single or batch payouts:

@verbatim
<code-snippet name="Send Payout" lang="php">
// Single withdrawal
$payout = $user->payout()
    ->to('0xAbC...', 'eth', 1.5)
    ->withDescription('Affiliate commission')
    ->send();

// Batch payout
$payout = $user->payout()
    ->to('0xAbC...', 'eth', 1.0)
    ->to('0xDeF...', 'usdttrc20', 50.0)
    ->scheduledFor(now()->addHours(24))
    ->send();
</code-snippet>
@endverbatim

### Checkout Overlay

Render a checkout button in Blade:

@verbatim
<code-snippet name="Checkout Button" lang="blade">
{!! $user->checkoutButton(49.99, 'usd', [
    'text' => 'Pay with Crypto',
    'description' => 'Order #12345',
    'success_url' => route('payment.success'),
    'cancel_url' => route('cart'),
]) !!}
</code-snippet>
@endverbatim

Or use the JS modal:

@verbatim
<code-snippet name="JS Modal Checkout" lang="javascript">
CashierCheckout.open({
    amount: 49.99,
    currency: 'usd',
    description: 'Premium Plan',
    success_url: 'https://yoursite.com/success',
    cancel_url: 'https://yoursite.com/cancel',
}).then(result => {
    console.log('Payment:', result.purchase_id);
});
</code-snippet>
@endverbatim

### Webhook Configuration

Register `https://your-app.com/nowpayments/webhook` in the NOWPayments dashboard. The package automatically verifies HMAC SHA-512 signatures and timestamps. Ensure `NOWPAYMENTS_IPN_SECRET` is set in your `.env`.

### Credit System

Credits are created automatically during plan swaps (proration) or manually. They are consumed FIFO when `->withCredits()` is used on `PaymentBuilder`:

@verbatim
<code-snippet name="Apply Credits" lang="php">
$balance = $customer->creditBalance();  // Returns string (bcmath)

$payment = $user->newPayment(49.99, 'usd')
    ->withCredits()  // Consumes credits before charging
    ->charge();
</code-snipet>
@endverbatim

### Key Configuration

All config is in `config/cashier-nowpayments.php`. Key settings:

| Key | Default | Purpose |
|-----|---------|---------|
| `prefix` | `cashier_nowpayments_` | Database table prefix |
| `payment_status.auth.enabled` | `true` | Gate payment status endpoints behind auth |
| `payment_status.auth.guard` | `web` | Auth guard to use |
| `payment_status.cache_seconds` | `10` | Remote status polling cache TTL |
| `checkout.payment_timeout_seconds` | `900` | Checkout countdown timer |
| `checkout.sync_cooldown_seconds` | `15` | Minimum seconds between API sync calls |

### Important Rules for AI

- Always use `->charge()` (not `->create()`) when you need a persisted `Payment` model
- Always use `->generate()` for persisted `Invoice` models
- Always use `->send()` for persisted `Payout` models
- Subscriptions: `resume()` throws — NOWPayments deletes cancelled subscriptions. Create a new subscription instead.
- Invoice `isPaid()` checks both `'paid'` and `'finished'` statuses
- Webhook endpoint must NOT be behind CSRF — it uses `api` middleware
- When creating payments for guests, use `$this->getOrCreateGuestCustomer($request)` from `CheckoutController`
- Idempotency is handled automatically — duplicate requests within 5 minutes return cached responses
- All monetary calculations use `bcmath` — never use floating-point arithmetic for amounts

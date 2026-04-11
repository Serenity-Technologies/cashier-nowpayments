# Builders & Services

Laravel Cashier NOWPayments provides five builder classes that implement the Fluent Interface pattern. Each builder encaps the configuration and creation of a specific domain object, offering chainable methods for optional parameters and terminal methods that execute the API call and optionally persist the result.

---

## CheckoutService (Recommended)

**Namespace:** `SerenityTechnologies\CashierNowPayments\Services\CheckoutService`

The `CheckoutService` provides a unified, service-oriented API for all checkout scenarios. It's the recommended approach for new implementations.

```php
use SerenityTechnologies\CashierNowPayments\Facades\Checkout;

// Simple payment
$payment = Checkout::createPayment(49.99, 'usd', 'btc');

// Session management
$session = Checkout::createSession(49.99, 'usd', [
    'description' => 'Order #123',
    'success_url' => route('checkout.success'),
    'cancel_url' => route('checkout.cancel'),
]);

// Validation with automatic processing fee
$validation = Checkout::validateAmount(49.99, 'usd', 'btc');
if (!$validation->isValid()) {
    // Fee automatically added to meet minimum
}

// Access via Billable trait
$service = $user->checkout();
```

See [CheckoutService Documentation](CHECKOUT_SERVICE.md) for full details.

---

## PaymentBuilder

**Namespace:** `SerenityTechnologies\CashierNowPayments\PaymentBuilder`

The `PaymentBuilder` constructs one-time crypto payments. It supports optional credit application, fixed-rate pricing, fee configuration, and metadata attachment.

### Constructor

```php
__construct(
    Model $billable,    // The billable entity (e.g., User)
    Customer $customer, // The associated Customer model
    float $amount,      // Payment amount (e.g., 49.99)
    string $currency    // Currency code (e.g., 'usd')
)
```

### Fluent Methods

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `withDescription` | `string $description` | `self` | Sets the payment description. |
| `withOrderId` | `string $orderId` | `self` | Sets a custom internal order ID. Auto-generates a ULID-based ID if omitted. |
| `withPayCurrency` | `string $currency` | `self` | Specifies the cryptocurrency to pay with (e.g., `'btc'`, `'eth'`). |
| `withFixedRate` | `bool $fixed = true` | `self` | Enables fixed-rate pricing to lock the exchange rate. |
| `withFeePaidByUser` | `bool $paidByUser = true` | `self` | Shifts the network fee to the payer. |
| `withMetadata` | `array $metadata` | `self` | Attaches arbitrary key-value metadata to the payment. |
| `withCredits` | `bool $apply = true` | `self` | Enables automatic credit application. Consumes available customer credits in FIFO order before charging. |
| `withRedirectUrl` | `string $url` | `self` | Sets the URL to redirect to after payment completion. |

### Terminal Methods

#### `create(): PaymentResponse`

Executes the payment via the NOWPayments API **without persisting** to the local database. Dispatches the `PaymentCreated` event.

```php
use App\Models\User;

$user = User::find(1);
$customer = $user->customer;

$response = $user->newPayment(49.99, 'usd')
    ->withDescription('Premium upgrade')
    ->withPayCurrency('btc')
    ->withFixedRate()
    ->withFeePaidByUser()
    ->withMetadata(['tier' => 'premium'])
    ->create();

// $response is a PaymentResponse DTO from the NOWPayments SDK
echo $response->pay_address;  // BTC deposit address
echo $response->pay_amount;   // Amount to send
```

#### `charge(): Payment`

Executes the API call **and persists** the payment record to the database within a DB transaction. Returns the local `Payment` Eloquent model.

```php
$payment = $user->newPayment(49.99, 'usd')
    ->withDescription('Order #1234')
    ->withOrderId('ORDER-1234')
    ->charge();

// $payment is the local Payment model
echo $payment->id;               // Local primary key
echo $payment->nowpayments_payment_id;
echo $payment->status;           // e.g., 'waiting'
echo $payment->pay_address;
```

### Credit Application

When `withCredits(true)` is enabled, the builder automatically calls `$customer->applyCredits($amount)` before creating the payment. Credits are consumed in **FIFO order** (oldest first):

```php
// Customer has $20.00 in credits
$payment = $user->newPayment(49.99, 'usd')
    ->withCredits(true)
    ->charge();

// Result: credits cover $20.00, payment is created for $29.99
// Metadata will contain:
//   ['credits_applied' => 20.00, 'original_amount' => 49.99]
```

If credits fully cover the amount, a payment is still created for tracking purposes with the covered amount recorded in metadata.

### Design Patterns

- **Fluent Interface:** All configuration methods return `$this` for chaining.
- **Builder Pattern:** Separates construction from final execution (`create` vs `charge`).
- **`GeneratesWebhookUrl` Trait:** Automatically resolves the IPN callback URL from `config('cashier-nowpayments.webhook.path')` and `config('app.url')`.
- **Transaction Wrapping:** `charge()` wraps the API call and persistence in `DB::transaction()` for atomicity.

### Event Dispatched

- **`PaymentCreated`** — dispatched with `($billable, $customer, PaymentResponse $response)` on `create()`.

---

## InvoiceBuilder

**Namespace:** `SerenityTechnologies\CashierNowPayments\InvoiceBuilder`

The `InvoiceBuilder` creates payment invoices with a hosted payment page URL. Invoices are ideal for scenarios where you send a payment link to the customer rather than displaying a deposit address directly.

### Constructor

```php
__construct(
    Model $billable,    // The billable entity
    Customer $customer, // The associated Customer model
    float $amount,      // Invoice amount
    string $currency    // Currency code
)
```

### Fluent Methods

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `withDescription` | `string $description` | `self` | Sets the invoice description. |
| `withOrderId` | `string $orderId` | `self` | Sets a custom internal order ID. Auto-generates a ULID-based ID prefixed with `INV-` if omitted. |
| `withSuccessUrl` | `string $url` | `self` | URL to redirect to after successful payment. |
| `withCancelUrl` | `string $url` | `self` | URL to redirect to if payment is cancelled. |
| `withMetadata` | `array $metadata` | `self` | Attaches arbitrary key-value metadata. |
| `withFixedRate` | `bool $fixed = true` | `self` | Enables fixed-rate pricing. |

### Terminal Methods

#### `create(): InvoiceResponse`

Creates the invoice via the NOWPayments API **without persisting**. Dispatches the `InvoiceCreated` event.

```php
$response = $user->newInvoice(99.00, 'usd')
    ->withDescription('Monthly subscription')
    ->withSuccessUrl('https://example.com/payment/success')
    ->withCancelUrl('https://example.com/payment/cancel')
    ->create();

echo $response->invoice_url;  // Hosted payment page URL
```

#### `generate(): Invoice`

Creates the invoice via API **and persists** it to the database. Returns the local `Invoice` Eloquent model.

```php
$invoice = $user->newInvoice(99.00, 'usd')
    ->withDescription('Consulting invoice')
    ->withOrderId('INV-2024-001')
    ->withSuccessUrl('https://example.com/thank-you')
    ->generate();

echo $invoice->id;            // Local primary key
echo $invoice->invoice_url;   // Payment page URL
echo $invoice->status;        // e.g., 'active'
```

### Design Patterns

- **Fluent Interface:** All configuration methods return `$this` for chaining.
- **Builder Pattern:** `create()` for API-only, `generate()` for API + persist.
- **`GeneratesWebhookUrl` Trait:** Automatically configures the `ipnCallbackUrl` for webhook notifications.

### Event Dispatched

- **`InvoiceCreated`** — dispatched with `($billable, $customer, InvoiceResponse $response)` on `create()`.

---

## SubscriptionBuilder

**Namespace:** `SerenityTechnologies\CashierNowPayments\SubscriptionBuilder`

The `SubscriptionBuilder` creates recurring subscriptions tied to a NOWPayments plan. It handles both the API subscription creation and local persistence, including the subscription item record.

### Constructor

```php
__construct(
    Model $billable,    // The billable entity
    Customer $customer, // The associated Customer model
    string $type,       // Subscription type identifier (e.g., 'default', 'premium')
    int $planId         // The NOWPayments plan ID
)
```

### Fluent Methods

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `quantity` | `int $quantity` | `self` | Sets the subscription quantity (minimum 1). Throws `InvalidArgumentException` if less than 1. |
| `withTrialDays` | `int $days` | `self` | Sets a trial period in days (minimum 1). Throws `InvalidArgumentException` if less than 1. |
| `withTrialUntil` | `Carbon $trialEndsAt` | `self` | Sets an explicit trial end date. |
| `withMetadata` | `array $metadata` | `self` | Attaches arbitrary key-value metadata. |

### Terminal Method

#### `create(): Subscription`

Creates the subscription via the NOWPayments API **and persists** it to the database. Returns the local `Subscription` Eloquent model. This method also creates an associated `SubscriptionItem` record.

```php
use Carbon\Carbon;

$subscription = $user->newSubscription('default', $nowpaymentsPlanId)
    ->quantity(2)
    ->withTrialDays(14)
    ->withMetadata(['source' => 'landing_page'])
    ->create();

echo $subscription->id;                    // Local primary key
echo $subscription->nowpayments_subscription_id;
echo $subscription->nowpayments_plan_id;
echo $subscription->status;                // e.g., 'waiting_pay'
echo $subscription->quantity;              // 2
echo $subscription->trial_ends_at;         // Carbon instance (14 days from now)

// Access the subscription item
$item = $subscription->subscriptionItems->first();
echo $item->amount;      // Plan price
echo $item->quantity;    // 2
echo $item->description; // Plan name
```

Using an explicit trial end date:

```php
$subscription = $user->newSubscription('premium', $planId)
    ->withTrialUntil(Carbon::parse('2025-06-01'))
    ->create();
```

### Important Note on Trials

Trial periods are tracked **locally only**. The NOWPayments API does not natively support trial periods. The `trial_ends_at` column on the local `Subscription` model is used by your application logic to determine when billing should begin.

### Plan Resolution

The builder automatically fetches plan details from NOWPayments during creation. If the plan does not exist, it throws a descriptive `InvalidArgumentException`:

```
Plan '12345' does not exist in NOWPayments. Create it via $user->newPlan()->create() first.
```

### Design Patterns

- **Fluent Interface:** Configuration methods return `$this` for chaining.
- **Builder Pattern:** Encapsulates subscription creation logic including plan resolution and item persistence.

### Event Dispatched

- **`SubscriptionCreated`** — dispatched with `($billable, $customer, Subscription $subscription, SubscriptionResponse $response)` on `create()`.

---

## PayoutBuilder

**Namespace:** `SerenityTechnologies\CashierNowPayments\PayoutBuilder`

The `PayoutBuilder` handles outgoing payments (withdrawals) to crypto addresses. It supports single and batch payouts, scheduled execution, and automatic persistence of both the payout and individual withdrawal records.

### Constructor

```php
__construct(
    Model $billable,    // The billable entity
    Customer $customer  // The associated Customer model
)
```

### Fluent Methods

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `to` | `string $address, string $currency, float $amount, ?string $extraId = null` | `self` | Adds a withdrawal destination. Call multiple times for batch payouts. |
| `withDescription` | `string $description` | `self` | Sets the payout description. |
| `scheduledFor` | `Carbon $dateTime` | `self` | Schedules the payout for future execution instead of immediate processing. |
| `withMetadata` | `array $metadata` | `self` | Attaches arbitrary key-value metadata. |

### Terminal Methods

#### `create(): PayoutResponse`

Executes the payout via the NOWPayments API **without persisting**. Validates that at least one withdrawal has been added. Dispatches the `PayoutCreated` event.

```php
$response = $user->newPayout()
    ->to('bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh', 'btc', 0.05)
    ->withDescription('Affiliate commission payout')
    ->create();

echo $response->id;       // Payout ID from NOWPayments
echo $response->status;   // e.g., 'creating'
```

Batch payout with multiple destinations:

```php
$response = $user->newPayout()
    ->to('bc1q...', 'btc', 0.05)
    ->to('0xabc...', 'eth', 1.5)
    ->to('ltc1q...', 'ltc', 10.0)
    ->withDescription('Batch payouts')
    ->create();
```

Scheduled payout:

```php
use Carbon\Carbon;

$response = $user->newPayout()
    ->to('bc1q...', 'btc', 0.1)
    ->withDescription('Scheduled weekly payout')
    ->scheduledFor(Carbon::now()->addDays(7))
    ->create();
```

#### `send(): Payout`

Executes the API call **and persists** the payout and withdrawal records to the database. Returns the local `Payout` Eloquent model.

```php
$payout = $user->newPayout()
    ->to('bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh', 'btc', 0.05)
    ->withDescription('Refund')
    ->send();

echo $payout->id;                    // Local primary key
echo $payout->nowpayments_payout_id;
echo $payout->status;                // e.g., 'creating'
echo $payout->address;               // bc1q...
echo $payout->amount;                // 0.05
```

### Batch Payout Persistence

When a payout contains multiple withdrawals, the `persistPayout()` method stores the first withdrawal's details on the main `Payout` record and creates separate `PayoutWithdrawal` records for each additional withdrawal via `persistWithdrawals()`.

### Validation

The builder validates that the withdrawals array is non-empty before making the API call:

```
InvalidArgumentException: At least one withdrawal is required.
```

### Design Patterns

- **Fluent Interface:** Configuration methods return `$this` for chaining.
- **Builder Pattern:** `create()` for API-only, `send()` for API + persist.
- **`GeneratesWebhookUrl` Trait:** Automatically configures the `ipnCallbackUrl` for payout status notifications.
- **Composite Persistence:** Both `Payout` and child `PayoutWithdrawal` records are persisted together.

### Event Dispatched

- **`PayoutCreated`** — dispatched with `($billable, $customer, PayoutResponse $response)` on `create()`.

---

## PlanBuilder

**Namespace:** `SerenityTechnologies\CashierNowPayments\PlanBuilder`

The `PlanBuilder` creates and manages subscription plans in NOWPayments. Unlike the other builders, this one does **not** require a billable model or customer — it operates at the account/merchant level.

### Constructor

```php
__construct(
    string $planId  // Unique plan identifier (used as the default name)
)
```

The constructor initializes defaults for `name` (same as `$planId`) and `currency` (from `config('cashier-nowpayments.currency', 'usd')`).

### Fluent Methods

| Method | Parameters | Returns | Description |
|--------|-----------|---------|-------------|
| `withName` | `string $name` | `self` | Sets the human-readable plan name. Defaults to the plan ID. |
| `withAmount` | `float $amount` | `self` | Sets the plan price. Defaults to `0`. |
| `withCurrency` | `string $currency` | `self` | Sets the plan currency. Defaults to `config('cashier-nowpayments.currency', 'usd')`. |
| `withIntervalDays` | `int $days` | `self` | Sets the billing interval in days. Defaults to `30`. |
| `withSuccessUrl` | `string $url` | `self` | URL to redirect to after successful subscription payment. |
| `withCancelUrl` | `string $url` | `self` | URL to redirect to if subscription payment is cancelled. |
| `withMetadata` | `array $metadata` | `self` | Attaches arbitrary key-value metadata to the plan. |

### Terminal Method

#### `create(): PlanResponse`

Creates the plan via the NOWPayments API **and persists** it to the local database. If a plan with the same `nowpayments_plan_id` already exists, it performs an **update** instead of creating a duplicate.

```php
// Create a new plan
$plan = app(\SerenityTechnologies\CashierNowPayments\PlanBuilder::class, ['planId' => 'monthly-pro'])
    ->withName('Monthly Pro Plan')
    ->withAmount(29.99)
    ->withCurrency('usd')
    ->withIntervalDays(30)
    ->withSuccessUrl('https://example.com/subscription/active')
    ->withCancelUrl('https://example.com/subscribe')
    ->create();

echo $plan->id;             // NOWPayments plan ID
echo $plan->title;          // 'Monthly Pro Plan'
echo $plan->amount;         // 29.99
echo $plan->interval_days;  // 30
```

Creating an annual plan:

```php
$plan = app(\SerenityTechnologies\CashierNowPayments\PlanBuilder::class, ['planId' => 'annual-pro'])
    ->withName('Annual Pro Plan')
    ->withAmount(299.99)
    ->withCurrency('usd')
    ->withIntervalDays(365)
    ->create();
```

### Upsert Behavior

The `persistPlan()` method checks for an existing plan by `nowpayments_plan_id`:

- **If found:** Updates `name`, `amount`, `currency`, `interval_days`, `status`, `success_url`, and `cancel_url`.
- **If not found:** Creates a new `Plan` record.

This means calling `create()` on the same plan ID multiple times is safe and will update rather than duplicate.

### Design Patterns

- **Fluent Interface:** Configuration methods return `$this` for chaining.
- **Builder Pattern:** Single terminal method (`create()`) that handles both API and persistence.
- **Upsert Pattern:** `persistPlan()` performs a check-then-create-or-update cycle.
- **No `GeneratesWebhookUrl`:** Plans do not require webhook callbacks; webhook configuration is handled at the subscription/payment level.

---

## Usage Patterns Across All Builders

### Accessing Builders via the Billable Trait

In practice, builders are typically instantiated through convenience methods on the billable model (provided by the `HasNowPayments` trait):

```php
// Payment
$payment = $user->newPayment(49.99, 'usd')
    ->withDescription('Purchase')
    ->charge();

// Invoice
$invoice = $user->newInvoice(99.00, 'usd')
    ->withDescription('Invoice')
    ->generate();

// Subscription
$subscription = $user->newSubscription('default', $planId)
    ->withTrialDays(14)
    ->create();

// Payout
$payout = $user->newPayout()
    ->to('bc1q...', 'btc', 0.05)
    ->send();
```

### API-Only vs API+Persist Terminal Methods

| Builder | API-Only Method | API+Persist Method |
|---------|----------------|-------------------|
| `PaymentBuilder` | `create(): PaymentResponse` | `charge(): Payment` |
| `InvoiceBuilder` | `create(): InvoiceResponse` | `generate(): Invoice` |
| `SubscriptionBuilder` | *(none)* | `create(): Subscription` |
| `PayoutBuilder` | `create(): PayoutResponse` | `send(): Payout` |
| `PlanBuilder` | *(none)* | `create(): PlanResponse` |

The API-only variants are useful when you need the raw NOWPayments response DTO for custom persistence logic, webhook handling, or integration with external systems.

### Shared Design Patterns

1. **Fluent Interface:** Every builder uses chainable methods returning `$this`, enabling expressive, readable construction chains.
2. **Terminal Method Separation:** Builders separate the API call from persistence concerns, giving you control over when and how data is stored.
3. **`GeneratesWebhookUrl` Trait:** `PaymentBuilder`, `InvoiceBuilder`, and `PayoutBuilder` all use this trait to automatically resolve the IPN callback URL from configuration, ensuring webhook notifications are properly routed.
4. **Event Dispatching:** All builders dispatch a `*Created` event after the API call succeeds, enabling reactive patterns (logging, notifications, analytics).
5. **Configurable Models:** Each builder resolves its Eloquent model class from `config('cashier-nowpayments.model.*')`, supporting custom model implementations.
6. **Validation:** Builders validate required fields (amount > 0, non-empty currency, at least one withdrawal for payouts) before making API calls, providing early, descriptive error messages.

# Billable Trait & Concerns

## Overview

The `Billable` trait is the single entry point for integrating NOWPayments into your Laravel application. Include it on any Eloquent model — typically your `User` model — and you immediately gain access to the full suite of payment, subscription, invoicing, and payout capabilities.

```php
use SerenityTechnologies\CashierNowPayments\Billable;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use Billable;
}
```

Behind the scenes, `Billable` aggregates **11 concern traits**, each responsible for a focused area of functionality. You only ever need to use `Billable` — the concerns are pulled in automatically.

---

## 1. ManagesCustomer

**File:** `src/Concerns/ManagesCustomer.php`

The `ManagesCustomer` concern establishes the relationship between your billable model and the local `Customer` record that mirrors the entity in NOWPayments.

### `customer()`

Returns a `MorphOne` relationship to the `Customer` model.

```php
$customer = $user->customer;
```

This is a polymorphic relationship, so the `Customer` model stores `billable_id` and `billable_type`, enabling any model to be billable.

### `createOrGetCustomer(array $attributes = [])`

Creates the customer record if it does not exist, or returns the existing one. This method is called internally by most other concerns, so you rarely need to invoke it directly.

```php
$customer = $user->createOrGetCustomer();
$customer = $user->createOrGetCustomer([
    'metadata' => ['source' => 'web'],
]);
```

The method auto-populates `nowpayments_customer_id` (prefixed with the configured prefix), `email`, and `name` from the billable model's attributes.

### `markAsCustomer(array $nowpaymentsData)`

Marks the billable model as a customer by storing NOWPayments-provided data into the local customer record.

```php
$customer = $user->markAsCustomer([
    'customer_id' => 'np_12345',
    'email' => 'user@example.com',
]);
```

### `getBillableEmail()` / `getBillableName()`

Protected helper methods that resolve the email and name from the billable model. Override these in your model if your column names differ.

```php
// In your User model
protected function getBillableEmail(): ?string
{
    return $this->email_address;
}

protected function getBillableName(): ?string
{
    return $this->full_name;
}
```

---

## 2. ManagesPayments

**File:** `src/Concerns/ManagesPayments.php`

Handles one-time crypto payments, estimates, and payment history.

### `charge(float $amount, string $currency): PaymentBuilder`

Starts building a one-time payment. The fluent builder lets you configure the destination currency, description, and more before executing.

```php
use SerenityTechnologies\CashierNowPayments\Models\Payment;

$payment = $user
    ->charge(49.99, 'USD')
    ->payCurrency('BTC')
    ->description('Pro Plan - Monthly')
    ->create();
```

The builder automatically associates the payment with the billable's customer record.

### `payments(): HasMany`

Returns a `HasMany` relationship to locally stored `Payment` records, scoped through the customer.

```php
$payments = $user->payments()->where('status', 'finished')->get();
```

### `remotePayments(array $filters = []): PaymentListResponse`

Fetches payment history directly from the NOWPayments API. Results are automatically scoped to this customer's order ID.

```php
$response = $user->remotePayments([
    'limit' => 20,
    'page' => 1,
    'payment_status' => 'finished',
    'date_from' => '2025-01-01',
    'date_to' => '2025-12-31',
]);

foreach ($response->data as $payment) {
    echo $payment->payment_id . ' - ' . $payment->payment_status;
}
```

### `estimateCrypto(float $fiatAmount, string $fiatCurrency, string $cryptoCurrency): EstimateResponse`

Gets an estimate of how much cryptocurrency a fiat amount will yield.

```php
$estimate = $user->estimateCrypto(100.00, 'USD', 'BTC');

echo "You will receive approximately {$estimate->estimated_amount} {$estimate->currency_to}";
```

### `minimumPaymentAmount(string $fromCurrency, string $toCurrency): MinAmountResponse`

Returns the minimum payment amount required for a given currency pair.

```php
$minAmount = $user->minimumPaymentAmount('USD', 'BTC');

echo "Minimum: {$minAmount->min_amount} {$minAmount->currency_from}";
```

### `hasIncompletePayment(): bool`

Checks whether the customer has any payments that are not in a terminal state (`finished`, `failed`, etc.).

```php
if ($user->hasIncompletePayment()) {
    return redirect()->route('payments.pending');
}
```

---

## 3. ManagesInvoices

**File:** `src/Concerns/ManagesInvoices.php`

Manages invoice-based billing, where an invoice is generated first and then paid by the customer.

### `invoice(float $amount, string $currency): InvoiceBuilder`

Begins building a new invoice.

```php
$invoice = $user
    ->invoice(250.00, 'USD')
    ->description('Consulting Services - Invoice #1042')
    ->order_id('INV-1042')
    ->successUrl(route('invoices.success'))
    ->cancelUrl(route('invoices.cancel'))
    ->create();
```

### `invoices(): HasMany`

Returns a `HasMany` relationship to locally stored `Invoice` records.

```php
$invoices = $user->invoices()->orderByDesc('created_at')->get();
```

### `payInvoice(Invoice $invoice, string $payCurrency, ?string $payoutAddress = null): Payment`

Creates a payment for an existing invoice. This invokes the NOWPayments API to generate a payment linked to the invoice.

```php
use SerenityTechnologies\CashierNowPayments\Models\Invoice;

$invoice = Invoice::findOrFail(1);

$payment = $user->payInvoice(
    $invoice,
    'USDTTRC20',
    'TN2YxJ3kQvMqR5fG7wP8bC4dE6hA9sL1mX'
);
```

---

## 4. ManagesSubscriptions

**File:** `src/Concerns/ManagesSubscriptions.php`

Provides recurring billing through NOWPayments subscription plans.

### `newSubscription(string $type, int $planId): SubscriptionBuilder`

Starts building a new subscription. The `$type` is an internal identifier (e.g., `default`, `premium`) that lets a single user hold multiple subscriptions.

```php
$subscription = $user
    ->newSubscription('premium', 42)
    ->trialDays(7)
    ->create();
```

### `newPlan(string $planId): PlanBuilder`

Starts building a new subscription plan definition. Use this to create or configure plans programmatically.

```php
$plan = $user
    ->newPlan('my_plan_001')
    ->name('Premium Plan')
    ->interval('month')
    ->price(29.99, 'USD')
    ->create();
```

### `subscription(string $type = 'default'): ?Subscription`

Retrieves a specific subscription by its type.

```php
$subscription = $user->subscription('premium');

if ($subscription) {
    echo $subscription->status;
}
```

### `subscriptions(): HasMany`

Returns all subscriptions for the billable model.

```php
$subscriptions = $user->subscriptions()->get();
```

### `remoteSubscriptions(array $filters = []): SubscriptionListResponse`

Fetches subscriptions directly from the NOWPayments API.

```php
$response = $user->remoteSubscriptions([
    'limit' => 50,
]);
```

### `onTrial(string $type = 'default', ?string $planId = null): bool`

Checks if the user is currently within a trial period.

```php
if ($user->onTrial('premium')) {
    echo "Trial ends at: " . $user->subscription('premium')->trial_ends_at;
}

// Check trial for a specific plan
if ($user->onTrial('premium', 'plan_123')) {
    // ...
}
```

### `subscribed(string $type = 'default', ?string $planId = null): bool`

Checks if the user has an active, non-trial subscription.

```php
if (! $user->subscribed('premium')) {
    return redirect()->route('pricing');
}
```

---

## 5. ManagesPayouts

**File:** `src/Concerns/ManagesPayouts.php`

Enables sending crypto payouts from your NOWPayments balance to external wallets.

### `payout(): PayoutBuilder`

Starts building a new payout.

```php
$payout = $user
    ->payout()
    ->amount(50.00)
    ->currency('USDTTRC20')
    ->address('TN2YxJ3kQvMqR5fG7wP8bC4dE6hA9sL1mX')
    ->create();
```

### `payouts(): HasMany`

Returns a `HasMany` relationship to locally stored `Payout` records.

```php
$payouts = $user->payouts()->where('status', 'sent')->get();
```

### `remotePayouts(array $filters = []): PayoutListResponse`

Fetches payout history from the NOWPayments API. When no explicit customer filter is provided, results are filtered locally against the billable's own payout records to prevent data leakage in multi-tenant setups.

```php
$response = $user->remotePayouts([
    'limit' => 20,
]);
```

### `validatePayoutAddress(string $address, string $currency, ?string $extraId = null): bool`

Validates whether a wallet address is correct for the given currency.

```php
$isValid = $user->validatePayoutAddress(
    'TN2YxJ3kQvMqR5fG7wP8bC4dE6hA9sL1mX',
    'USDTTRC20'
);

if (! $isValid) {
    throw new InvalidArgumentException('Invalid wallet address');
}
```

### `minimumWithdrawalAmount(string $coin): MinWithdrawalAmountResponse`

Returns the minimum withdrawal amount for a given coin. This is a static method.

```php
use App\Models\User;

$minAmount = User::minimumWithdrawalAmount('BTC');
echo "Minimum withdrawal: {$minAmount->min_amount}";
```

### `payoutFeeEstimate(): FeeEstimateResponse`

Returns an estimate of the payout fee. Static method.

```php
use App\Models\User;

$feeEstimate = User::payoutFeeEstimate();
```

---

## 6. ManagesBalance

**File:** `src/Concerns/ManagesBalance.php`

### `balance(): BalanceResponse`

Retrieves the NOWPayments account balance.

```php
$balance = $user->balance();

foreach ($balance as $currency => $amount) {
    echo "{$currency}: {$amount}";
}
```

This returns the merchant-level balance from NOWPayments, not a per-customer balance.

---

## 7. ManagesCurrencies

**File:** `src/Concerns/ManagesCurrencies.php`

Provides methods for querying available cryptocurrencies. Results are cached for one hour.

### `availableCurrencies(bool $fixedRate = false): Collection`

Returns a collection of available currency symbols. Optionally include fixed-rate currencies.

```php
use App\Models\User;

// Standard floating-rate currencies
$currencies = User::availableCurrencies();
// ['btc', 'eth', 'usdttrc20', ...]

// Fixed-rate currencies
$fixedCurrencies = User::availableCurrencies(fixedRate: true);
```

### `fullCurrencies(): FullCurrencyResponse`

Returns full currency details including network information, names, and icons.

```php
$response = User::fullCurrencies();

foreach ($response->currencies as $currency) {
    echo $currency->ticker . ' - ' . $currency->name;
}
```

### `merchantCoins(): CurrencyResponse`

Returns the coins that are enabled for your specific merchant account.

```php
$response = User::merchantCoins();
```

---

## 8. ManagesConversions

**File:** `src/Concerns/ManagesConversions.php`

Handles on-platform crypto-to-crypto conversions.

### `convert(float $amount, string $fromCurrency, string $toCurrency): ConversionResponse`

Executes a crypto conversion.

```php
$response = $user->convert(
    amount: 0.01,
    fromCurrency: 'BTC',
    toCurrency: 'ETH'
);

echo "Converted {$response->from_amount} {$response->from_currency} to {$response->to_amount} {$response->to_currency}";
```

### `remoteConversions(array $filters = []): ConversionListResponse`

Fetches conversion history from the NOWPayments API.

```php
$response = $user->remoteConversions([
    'limit' => 50,
    'date_from' => '2025-01-01',
]);
```

---

## 9. ManagesFiatPayouts

**File:** `src/Concerns/ManagesFiatPayouts.php`

Supports fiat payout flows, where crypto is converted to fiat and sent through a payment provider.

### `fiatProviders(): FiatProvidersResponse`

Returns the list of available fiat providers.

```php
use App\Models\User;

$response = User::fiatProviders();

foreach ($response->providers as $provider) {
    echo $provider->name;
}
```

### `supportedFiatCurrencies(): FiatCurrenciesResponse`

Returns all supported fiat currencies.

```php
$response = User::supportedFiatCurrencies();
```

### `supportedCryptoForFiat(string $provider, string $fiatCurrency): FiatCryptoCurrenciesResponse`

Returns which cryptocurrencies can be used for a given provider and fiat currency combination.

```php
$response = User::supportedCryptoForFiat('provider_name', 'USD');
```

### `fiatPaymentMethods(string $provider, string $fiatCurrency): FiatPaymentMethodsResponse`

Returns available payment methods for a fiat provider.

```php
$response = User::fiatPaymentMethods('provider_name', 'USD');
```

---

## 10. ManagesPlans

**File:** `src/Concerns/ManagesPlans.php`

Static methods for listing and updating subscription plans.

### `listPlans(array $filters = []): PlanListResponse`

Lists subscription plans from the NOWPayments API.

```php
use App\Models\User;

$response = User::listPlans([
    'limit' => 100,
    'is_active' => true,
]);

foreach ($response->data as $plan) {
    echo $plan->plan_id . ' - ' . $plan->plan_name;
}
```

### `updatePlan(string $planId, array $data): PlanResponse`

Updates an existing subscription plan.

```php
use App\Models\User;

$response = User::updatePlan('my_plan_001', [
    'plan_name' => 'Premium Plan v2',
    'plan_price' => 39.99,
    'plan_interval' => 'month',
]);
```

---

## 11. ProvidesCheckoutHelpers

**File:** `src/Concerns/ProvidesCheckoutHelpers.php`

Generates frontend-ready HTML for crypto checkout buttons and URLs.

### `checkoutButton(float $amount, string $currency, array $options = []): HtmlString`

Generates an anchor tag styled as a checkout button, pre-populated with payment parameters.

```php
// In a Blade view
{{ $user->checkoutButton(49.99, 'USD') }}

{{-- Produces: --}}
{{-- <a href="/cashier-nowpayments/checkout?amount=49.99&currency=USD" --}}
{{--    class="cashier-nowpayments-checkout-btn" --}}
{{--    data-cashier-nowpayments --}}
{{--    data-cashier-nowpayments='{"amount":49.99,"currency":"USD","success_url":"http://localhost","cancel_url":"http://localhost"}'> --}}
{{--     Pay with Crypto --}}
{{-- </a> --}}
```

Customize the button text, CSS class, and redirect URLs:

```php
{{ $user->checkoutButton(99.00, 'USD', [
    'text' => 'Subscribe Now',
    'class' => 'btn btn-primary crypto-checkout',
    'success_url' => route('checkout.success'),
    'cancel_url' => route('checkout.cancel'),
    'order_description' => 'Annual Premium Subscription',
]) }}
```

### `checkoutUrl(float $amount, string $currency, array $options = []): string`

Generates a full checkout URL with query parameters for direct redirects.

```php
$url = $user->checkoutUrl(25.00, 'USD');
// http://yourapp.test/cashier-nowpayments/checkout?amount=25&currency=USD

// With additional options
$url = $user->checkoutUrl(25.00, 'USD', [
    'success_url' => route('payment.done'),
    'cancel_url' => route('payment.cancel'),
    'order_description' => 'Order #1234',
]);
```

Useful for programmatic redirects in controllers:

```php
return redirect()->away($user->checkoutUrl(25.00, 'USD', [
    'success_url' => route('checkout.complete'),
]));
```

---

## Quick Reference

| Concern | Purpose | Key Methods |
|---|---|---|
| `ManagesCustomer` | Customer relationship and identity | `customer()`, `createOrGetCustomer()`, `markAsCustomer()` |
| `ManagesPayments` | One-time payments and estimates | `charge()`, `payments()`, `remotePayments()`, `estimateCrypto()` |
| `ManagesInvoices` | Invoice creation and payment | `invoice()`, `invoices()`, `payInvoice()` |
| `ManagesSubscriptions` | Recurring billing | `newSubscription()`, `subscription()`, `subscribed()`, `onTrial()` |
| `ManagesPayouts` | Crypto payouts | `payout()`, `payouts()`, `remotePayouts()`, `validatePayoutAddress()` |
| `ManagesBalance` | Account balance | `balance()` |
| `ManagesCurrencies` | Currency discovery | `availableCurrencies()`, `fullCurrencies()`, `merchantCoins()` |
| `ManagesConversions` | Crypto-to-crypto conversion | `convert()`, `remoteConversions()` |
| `ManagesFiatPayouts` | Fiat provider queries | `fiatProviders()`, `supportedFiatCurrencies()` |
| `ManagesPlans` | Plan listing and updates | `listPlans()`, `updatePlan()` |
| `ProvidesCheckoutHelpers` | Frontend checkout HTML | `checkoutButton()`, `checkoutUrl()` |

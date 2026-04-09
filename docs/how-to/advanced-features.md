# Advanced Features

This guide covers advanced functionality available through the `Billable` trait. Each feature is provided by a dedicated concern that is composed into the trait. Add the `Billable` trait to your model and all capabilities become available immediately.

```php
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

---

## 1. Currency Management

**Trait:** `ManagesCurrencies`  
**Methods:** Static on your billable model.

### Available Currencies (Cached)

Get a collection of currency codes supported by NOWPayments. Results are cached for 1 hour to avoid redundant API calls.

```php
// Standard (floating-rate) currencies
$currencies = User::availableCurrencies();
// => Illuminate\Support\Collection ['btc', 'eth', 'usdttrc20', ...]

// Fixed-rate currencies (rate locked for 20 minutes after payment creation)
$fixedCurrencies = User::availableCurrencies(fixedRate: true);
```

**Cache key strategy:**

| Mode | Cache Key |
|------|-----------|
| Standard | `nowpayments.currencies.standard` |
| Fixed-rate | `nowpayments.currencies.fixed` |

Both keys expire after 1 hour. If you need fresh data before the cache expires, clear the relevant key manually:

```php
Cache::forget('nowpayments.currencies.standard');
Cache::forget('nowpayments.currencies.fixed');
```

### Full Currency Details

Retrieve comprehensive currency information including display name, network, and fee details:

```php
$fullCurrencies = User::fullCurrencies();
// => SerenityTechnologies\NowPayments\DTOs\Response\FullCurrencyResponse

foreach ($fullCurrencies->currencies as $currency) {
    echo $currency->currency;       // 'btc'
    echo $currency->name;           // 'Bitcoin'
    echo $currency->network;        // 'btc'
    echo $currency->enabled;        // true
}
```

### Merchant Enabled Coins

Get only the coins that are specifically enabled for your merchant account:

```php
$coins = User::merchantCoins();
// => SerenityTechnologies\NowPayments\DTOs\Response\CurrencyResponse

foreach ($coins->currencies as $coin) {
    echo $coin->currency;   // 'usdttrc20'
    echo $coin->enabled;    // true
}
```

---

## 2. Crypto Estimation

**Trait:** `ManagesPayments`  
**Methods:** Instance methods on the billable model.

### Estimate Crypto Amount

Before redirecting a user to checkout, show them exactly how much crypto they need to send for a given fiat amount:

```php
$estimate = $user->estimateCrypto(
    fiatAmount: 49.99,
    fiatCurrency: 'USD',
    cryptoCurrency: 'btc',
);
// => SerenityTechnologies\NowPayments\DTOs\Response\EstimateResponse

echo $estimate->estimated_amount;  // e.g. '0.00073521'
echo $estimate->currency_from;     // 'USD'
echo $estimate->currency_to;       // 'btc'
```

**Typical checkout flow:**

```php
// In a controller
public function estimate(Request $request)
{
    $validated = $request->validate([
        'amount' => 'required|numeric|min:0.01',
        'currency' => 'required|string',       // e.g. 'USD'
        'pay_currency' => 'required|string',   // e.g. 'btc'
    ]);

    $estimate = $request->user()->estimateCrypto(
        $validated['amount'],
        $validated['currency'],
        $validated['pay_currency'],
    );

    return response()->json([
        'estimated_crypto' => $estimate->estimated_amount,
        'crypto_currency' => $estimate->currency_to,
    ]);
}
```

### Minimum Payment Amount

Check the minimum payment amount allowed for a specific currency pair:

```php
$minAmount = $user->minimumPaymentAmount(
    fromCurrency: 'USD',
    toCurrency: 'btc',
);
// => SerenityTechnologies\NowPayments\DTOs\Response\MinAmountResponse

echo $minAmount->min_amount;  // e.g. '1.00'
echo $minAmount->currency;    // 'USD'
```

Use this to validate input before creating a payment:

```php
$min = $user->minimumPaymentAmount('USD', 'btc');

if ($amount < $min->min_amount) {
    return back()->withErrors([
        'amount' => "Minimum payment is {$min->min_amount} {$min->currency}.",
    ]);
}
```

---

## 3. Crypto Conversions

**Trait:** `ManagesConversions`  
**Methods:** Instance methods on the billable model.

### Convert Crypto to Crypto

Convert one cryptocurrency to another while the funds remain in NOWPayments custody:

```php
$conversion = $user->convert(
    amount: 0.01,
    fromCurrency: 'btc',
    toCurrency: 'eth',
);
// => SerenityTechnologies\NowPayments\DTOs\Response\ConversionResponse

echo $conversion->id;              // Conversion ID
echo $conversion->amount_from;     // 0.01
echo $conversion->amount_to;       // e.g. '0.165432'
echo $conversion->status;          // 'finished'
```

**Use case -- auto-convert received payments to a preferred currency:**

```php
// After receiving a payment in BTC, convert to USDT
$payment = $user->payments()->latest()->first();

if ($payment->pay_currency === 'btc' && $payment->isSuccessful()) {
    $user->convert(
        amount: (float) $payment->actually_paid,
        fromCurrency: 'btc',
        toCurrency: 'usdttrc20',
    );
}
```

### Conversion History

Retrieve the conversion history with optional filtering:

```php
$history = $user->remoteConversions();
// => SerenityTechnologies\NowPayments\DTOs\Response\ConversionListResponse

foreach ($history->data as $conversion) {
    echo $conversion->from_currency;  // 'btc'
    echo $conversion->to_currency;    // 'eth'
    echo $conversion->amount_from;    // '0.01'
    echo $conversion->amount_to;      // '0.165432'
    echo $conversion->status;         // 'finished'
}
```

Filters are passed as an associative array. The underlying API supports pagination and date filtering:

```php
$history = $user->remoteConversions([
    'date_from' => '2025-01-01T00:00:00Z',
    'date_to' => '2025-03-31T23:59:59Z',
    'limit' => 50,
]);
```

---

## 4. Balance Checking

**Trait:** `ManagesBalance`  
**Methods:** Instance method on the billable model.

### Get Account Balance

Retrieve the current balance of your NOWPayments custody account, broken down by currency:

```php
$balance = $user->balance();
// => SerenityTechnologies\NowPayments\DTOs\Response\BalanceResponse

// Access per-currency balances
foreach ($balance->balances as $currency => $amount) {
    echo "{$currency}: {$amount}";
}
// Example output:
//   btc: 0.05231000
//   eth: 1.23456789
//   usdttrc20: 500.00
```

**Use case -- dashboard widget:**

```php
class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $balance = $request->user()->balance();

        return view('dashboard', [
            'balances' => $balance->balances,
            'totalCurrencies' => count($balance->balances),
        ]);
    }
}
```

---

## 5. Fiat Payouts

**Trait:** `ManagesFiatPayouts`  
**Methods:** Static on your billable model.

These methods let you build a fiat payout flow by discovering available providers, supported currencies, and payment methods.

### List Fiat Payout Providers

```php
$providers = User::fiatProviders();
// => SerenityTechnologies\NowPayments\DTOs\Response\FiatProvidersResponse

foreach ($providers->providers as $provider) {
    echo $provider->provider_id;    // e.g. 'banxa'
    echo $provider->provider_name;  // e.g. 'Banxa'
}
```

### Supported Fiat Currencies

```php
$currencies = User::supportedFiatCurrencies();
// => SerenityTechnologies\NowPayments\DTOs\Response\FiatCurrenciesResponse

foreach ($currencies->currencies as $fiat) {
    echo $fiat->currency;   // 'USD'
    echo $fiat->name;       // 'US Dollar'
}
```

### Supported Crypto for a Specific Fiat Currency

Determine which cryptocurrencies can be converted to a specific fiat currency through a given provider:

```php
$availableCrypto = User::supportedCryptoForFiat(
    provider: 'banxa',
    fiatCurrency: 'EUR',
);
// => SerenityTechnologies\NowPayments\DTOs\Response\FiatCryptoCurrenciesResponse

foreach ($availableCrypto->currencies as $crypto) {
    echo $crypto->currency;   // 'btc'
    echo $crypto->name;       // 'Bitcoin'
}
```

### Payment Methods for a Fiat Currency

Get the available payment methods (bank transfer, card, etc.) for a fiat currency through a provider:

```php
$methods = User::fiatPaymentMethods(
    provider: 'banxa',
    fiatCurrency: 'EUR',
);
// => SerenityTechnologies\NowPayments\DTOs\Response\FiatPaymentMethodsResponse

foreach ($methods->payment_methods as $method) {
    echo $method->id;           // 'sepa'
    echo $method->name;         // 'SEPA Bank Transfer'
    echo $method->description;  // 'SEPA transfer, 1-3 business days'
}
```

**Use case -- building a payout selector:**

```php
// In a controller
public function create()
{
    return view('payouts.create', [
        'providers' => User::fiatProviders()->providers,
        'fiatCurrencies' => User::supportedFiatCurrencies()->currencies,
    ]);
}

public function availableOptions(Request $request)
{
    $validated = $request->validate([
        'provider' => 'required|string',
        'fiat_currency' => 'required|string',
    ]);

    return response()->json([
        'crypto' => User::supportedCryptoForFiat(
            $validated['provider'],
            $validated['fiat_currency'],
        )->currencies,
        'methods' => User::fiatPaymentMethods(
            $validated['provider'],
            $validated['fiat_currency'],
        )->payment_methods,
    ]);
}
```

---

## 6. Payment Address Validation

**Trait:** `ManagesPayouts`  
**Methods:** Mix of instance and static methods.

### Validate a Payout Address

Verify that a wallet address is correctly formatted for a given cryptocurrency before initiating a payout:

```php
$isValid = $user->validatePayoutAddress(
    address: '0x742d35Cc6634C0532925a3b844Bc9e7595f2bD18',
    currency: 'eth',
);
// => bool (true)
```

For currencies that require an additional identifier (such as XRP with a destination tag), pass the `$extraId` parameter:

```php
$isValid = $user->validatePayoutAddress(
    address: 'rEb8TK3gBgk5auZkwc6sHnwrGVJH8DuaLh',
    currency: 'xrp',
    extraId: '12345678',
);
```

**Use case -- form validation:**

```php
public function storePayout(Request $request)
{
    $validated = $request->validate([
        'address' => 'required|string',
        'currency' => 'required|string',
        'extra_id' => 'nullable|string',
        'amount' => 'required|numeric|min:0',
    ]);

    // Validate address before creating payout
    $addressValid = $request->user()->validatePayoutAddress(
        $validated['address'],
        $validated['currency'],
        $validated['extra_id'] ?? null,
    );

    if (!$addressValid) {
        return back()->withErrors([
            'address' => "Invalid {$validated['currency']} address.",
        ]);
    }

    // Proceed with payout creation
    $payout = $request->user()
        ->payout()
        ->toAddress($validated['address'], $validated['currency'], $validated['extra_id'] ?? null)
        ->amount($validated['amount'])
        ->create();

    return redirect()->route('payouts.show', $payout);
}
```

### Minimum Withdrawal Amount

```php
$minWithdrawal = User::minimumWithdrawalAmount(coin: 'btc');
// => SerenityTechnologies\NowPayments\DTOs\Response\MinWithdrawalAmountResponse

echo $minWithdrawal->currency;    // 'btc'
echo $minWithdrawal->min_amount;  // e.g. '0.001'
```

### Payout Fee Estimate

Get an estimate of the fee that will be charged for a payout:

```php
$feeEstimate = User::payoutFeeEstimate();
// => SerenityTechnologies\NowPayments\DTOs\Response\FeeEstimateResponse

echo $feeEstimate->fee;           // e.g. '0.0001'
echo $feeEstimate->fee_currency;  // 'btc'
```

---

## 7. Remote Data Queries

All remote query methods interact with the NOWPayments API directly. They accept an optional `$filters` array for filtering and pagination.

### Remote Payments

```php
$payments = $user->remotePayments([
    'limit' => 20,
    'page' => 1,
    'date_from' => '2025-01-01T00:00:00Z',
    'date_to' => '2025-12-31T23:59:59Z',
    'payment_status' => 'finished',   // Filter by status
    'pay_currency' => 'btc',          // Filter by payment currency
    'price_currency' => 'USD',        // Filter by price currency
    'sort_by' => 'created_at',
    'order_by' => 'desc',
]);
// => SerenityTechnologies\NowPayments\DTOs\Response\PaymentListResponse
```

**Automatic scoping:** When `order_id` is not explicitly provided in filters, the query is automatically scoped to the customer's NOWPayments customer ID, ensuring multi-tenant safety.

### Remote Subscriptions

```php
$subscriptions = $user->remoteSubscriptions([
    'status' => 'active',
    'plan_id' => 'plan_abc123',
    'limit' => 50,
]);
// => SerenityTechnologies\NowPayments\DTOs\Response\SubscriptionListResponse
```

### Remote Payouts

```php
$payouts = $user->remotePayouts([
    'limit' => 20,
    'page' => 1,
]);
// => SerenityTechnologies\NowPayments\DTOs\Response\PayoutListResponse
```

**Local filtering:** Since the NOWPayments API does not support filtering payouts by customer/order ID, the package retrieves all payouts and then filters them locally against the billable model's stored payout IDs. This prevents data leakage in multi-tenant setups.

### Remote Conversions

```php
$conversions = $user->remoteConversions([
    'limit' => 25,
    'date_from' => '2025-06-01T00:00:00Z',
]);
// => SerenityTechnologies\NowPayments\DTOs\Response\ConversionListResponse
```

---

## 8. Payment Refund Flow

**Model:** `SerenityTechnologies\CashierNowPayments\Models\Payment`  
**Method:** `refund(?string $reason = null)`

### Refunding a Payment

The `refund()` method marks a payment as refunded in your local database and dispatches the `PaymentRefunded` event.

```php
$payment = $user->payments()->findOrFail($paymentId);

try {
    $payment->refund('Customer requested refund - order cancelled');
} catch (\InvalidArgumentException $e) {
    // Payment was not in 'finished' status, or already refunded
    return back()->withErrors(['refund' => $e->getMessage()]);
}
```

**What happens internally:**

1. Validates the payment is in `finished` status -- throws `\InvalidArgumentException` otherwise.
2. Validates the payment has not already been refunded (`refunded_at` is null).
3. Updates the local record:
   - Sets `status` to `'refunded'`.
   - Sets `refunded_at` to the current timestamp.
   - Appends `refund_reason` and `refund_initiated_at` to the `metadata` column.
4. Dispatches the `PaymentRefunded` event with the payment and reason.

### Important Note on Actual Refunds

NOWPayments does not provide a direct API endpoint for initiating refunds programmatically. The `refund()` method only updates your local database record. To process the actual refund, you must:

- **Option 1:** Initiate the refund manually through the NOWPayments dashboard.
- **Option 2:** Contact NOWPayments support with the payment details.

After the refund is processed on their end, call `refund()` locally to keep your records in sync. Alternatively, if you use webhooks, the status update will arrive via the webhook and `Payment::syncStatus()` will update the local record automatically.

### Listening to the Refund Event

```php
use SerenityTechnologies\CashierNowPayments\Events\PaymentRefunded;

class SendRefundConfirmation
{
    public function handle(PaymentRefunded $event): void
    {
        $payment = $event->payment;
        $reason = $event->reason;

        $payment->billable->notify(new RefundProcessedNotification($reason));
    }
}
```

---

## 9. Middleware and Auth

**Middleware:** `SerenityTechnologies\CashierNowPayments\Http\Middleware\EnsurePaymentBelongsToUser`  
**Alias:** `nowpayments.payment.auth`

### Overview

The `nowpayments.payment.auth` middleware guards the payment status endpoints (`/payment/status/{purchaseId}` and `/payment/local/{paymentId}`). It ensures that only authenticated users can check the status of their own payments, preventing unauthorized enumeration of payment details.

### Configuration

Control the middleware behavior via `config/cashier-nowpayments.php`:

```php
'payment_status' => [
    'auth' => [
        'enabled' => env('CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH', true),
        'guard' => env('CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD', 'web'),
    ],
    'cache_seconds' => env('CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS', 10),
],
```

| Config Key | Description | Default |
|---|---|---|
| `payment_status.auth.enabled` | Enable/disable ownership verification on status endpoints | `true` |
| `payment_status.auth.guard` | Auth guard to use (`web`, `api`, `sanctum`, etc.) | `web` |
| `payment_status.cache_seconds` | Cache duration (seconds) for remote status polling | `10` |

To disable auth on payment status endpoints (not recommended for production):

```env
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH=false
```

### Ownership Verification

The `PaymentStatusController` performs ownership checks at two levels:

**Remote status endpoint (`/payment/status/{purchaseId}`):**

```php
// Verifies the authenticated user has a local Payment record
// matching the given purchase ID
$ownsPayment = $paymentModel::where('billable_type', $user->getMorphClass())
    ->where('billable_id', $user->getKey())
    ->where(function ($query) use ($purchaseId) {
        $query->where('nowpayments_purchase_id', $purchaseId)
            ->orWhere('nowpayments_payment_id', $purchaseId);
    })
    ->exists();
```

**Local status endpoint (`/payment/local/{paymentId}`):**

Uses the `verifyPaymentOwnership()` method which checks:

1. The payment's `billable` polymorphic relationship matches the authenticated user (key + morph class).
2. Falls back to checking through the `Customer` model if the billable relationship is not set.

### Using `verifyPaymentOwnership` in Your Own Controllers

If you build custom payment status endpoints, use the same verification pattern:

```php
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use Illuminate\Http\Request;

class MyPaymentController extends Controller
{
    public function status(string $paymentId, Request $request)
    {
        $payment = Payment::findOrFail($paymentId);

        if (!$this->verifyPaymentOwnership($payment, $request)) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found or access denied.',
            ], 403);
        }

        return response()->json([
            'status' => $payment->status,
            'amount' => $payment->amount,
        ]);
    }

    protected function verifyPaymentOwnership(Payment $payment, Request $request): bool
    {
        $authConfig = config('cashier-nowpayments.payment_status.auth', []);
        $guard = $authConfig['guard'] ?? null;
        $user = $guard !== null ? $request->user($guard) : $request->user();

        if ($user === null) {
            return false;
        }

        if ($payment->billable !== null) {
            return $payment->billable->getKey() === $user->getKey()
                && $payment->billable->getMorphClass() === $user->getMorphClass();
        }

        $customerModel = config('cashier-nowpayments.model.customer');
        $customer = $customerModel::where('billable_type', $user->getMorphClass())
            ->where('billable_id', $user->getKey())
            ->first();

        if ($customer === null) {
            return false;
        }

        return (string) $payment->customer_id === (string) $customer->id;
    }
}
```

---

## 10. Custom Models

You can replace any package model with your own implementation to add fields, relationships, or business logic.

### Step 1: Configure Custom Models

In `config/cashier-nowpayments.php`, update the `model` array:

```php
'model' => [
    'customer' => \App\Models\Cashier\Customer::class,
    'subscription' => \App\Models\Cashier\Subscription::class,
    'subscription_item' => \App\Models\Cashier\SubscriptionItem::class,
    'payment' => \App\Models\Cashier\Payment::class,
    'invoice' => \App\Models\Cashier\Invoice::class,
    'payout' => \App\Models\Cashier\Payout::class,
    'payout_withdrawal' => \App\Models\Cashier\PayoutWithdrawal::class,
    'credit' => \App\Models\Cashier\Credit::class,
    'plan' => \App\Models\Cashier\Plan::class,
],
```

### Step 2: Extend the Base Model

Create your custom model by extending the package's base model:

**Custom Customer model:**

```php
// app/Models/Cashier/Customer.php
namespace App\Models\Cashier;

use SerenityTechnologies\CashierNowPayments\Models\Customer as BaseCustomer;

class Customer extends BaseCustomer
{
    /**
     * Additional relationships.
     */
    public function referrals()
    {
        return $this->hasMany(\App\Models\Referral::class);
    }

    /**
     * Custom scope for high-value customers.
     */
    public function scopeHighValue($query, float $threshold = 1000)
    {
        return $query->whereHas('payments', function ($q) use ($threshold) {
            $q->where('status', 'finished')
              ->where('amount', '>=', $threshold);
        });
    }
}
```

**Custom Payment model with business logic:**

```php
// app/Models/Cashier/Payment.php
namespace App\Models\Cashier;

use SerenityTechnologies\CashierNowPayments\Events\PaymentRefunded;
use SerenityTechnologies\CashierNowPayments\Models\Payment as BasePayment;

class Payment extends BasePayment
{
    /**
     * Additional casts.
     */
    protected $casts = [
        'fee' => 'array',
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
        'tax_amount' => 'decimal:2',  // Custom field
    ];

    /**
     * Determine if this payment is eligible for a refund
     * based on business rules (e.g., within 30 days).
     */
    public function isRefundEligible(): bool
    {
        if (!$this->isSuccessful()) {
            return false;
        }

        return $this->paid_at?->diffInDays(now()) <= 30;
    }

    /**
     * Override refund to enforce business rules.
     */
    public function refund(?string $reason = null): self
    {
        if (!$this->isRefundEligible()) {
            throw new \InvalidArgumentException(
                'Payment is not eligible for refund (expired 30-day window or not successful).'
            );
        }

        return parent::refund($reason);
    }

    /**
     * Calculate tax for this payment.
     */
    public function calculateTax(float $rate = 0.10): void
    {
        $this->update([
            'tax_amount' => bcmul((string) $this->amount, (string) $rate, 2),
        ]);
    }
}
```

### Step 3: Update Migrations

If your custom model adds database columns, you must add those columns to the corresponding migration. Publish the package migrations and modify them:

```bash
php artisan vendor:publish --tag="cashier-nowpayments-migrations"
```

Then edit the published migration files to include your additional columns.

### Model Resolution

The package resolves models through `config('cashier-nowpayments.model.*')` at runtime. All internal relationships and queries use these configuration values, so your custom models are used transparently throughout the package -- including in the `Billable` trait, builders, events, and webhook handlers.

---

## Quick Reference

| Feature | Trait | Key Methods | Static? |
|---|---|---|---|
| Currency Management | `ManagesCurrencies` | `availableCurrencies()`, `fullCurrencies()`, `merchantCoins()` | Yes |
| Crypto Estimation | `ManagesPayments` | `estimateCrypto()`, `minimumPaymentAmount()` | No |
| Crypto Conversions | `ManagesConversions` | `convert()`, `remoteConversions()` | No |
| Balance Checking | `ManagesBalance` | `balance()` | No |
| Fiat Payouts | `ManagesFiatPayouts` | `fiatProviders()`, `supportedFiatCurrencies()`, `supportedCryptoForFiat()`, `fiatPaymentMethods()` | Yes |
| Address Validation | `ManagesPayouts` | `validatePayoutAddress()`, `minimumWithdrawalAmount()`, `payoutFeeEstimate()` | Mixed |
| Remote Queries | Various | `remotePayments()`, `remoteSubscriptions()`, `remotePayouts()` | No |
| Payment Refund | `Payment` model | `refund($reason)` | No |
| Auth Middleware | `EnsurePaymentBelongsToUser` | `verifyPaymentOwnership()` | -- |
| Custom Models | Configuration | `config('cashier-nowpayments.model.*')` | -- |

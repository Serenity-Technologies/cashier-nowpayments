# CheckoutService Documentation

## Overview

The `CheckoutService` provides a unified, service-oriented API for handling all checkout scenarios in Cashier NOWPayments. It's designed following Laravel Cashier Stripe's service pattern and implements the complete NOWPayments API checkout flow.

## Architecture

```
CheckoutService
    ├── CheckoutSession      # Session management
    ├── EstimateResult       # Currency conversion estimates
    ├── ValidationResult     # Amount validation results
    ├── PaymentResult        # Direct payment creation results
    ├── InvoiceResponse        # Hosted invoice creation results
    ├── CheckoutException    # Custom exception with factory methods
    └── Facade: Checkout     # Static access convenience
```

## Basic Usage

### Standard E-Commerce Flow (Direct Payment)

This flow gives you full control over the checkout UI and displays payment details directly to the customer.

```php
use SerenityTechnologies\CashierNowPayments\Facades\Checkout;

// Step 1: Verify API is available
if (!Checkout::isApiAvailable()) {
    throw new Exception('Payment system is currently unavailable.');
}

// Step 2: Get available currencies
$currencies = Checkout::getAvailableCurrencies();
// Returns: ['btc', 'eth', 'usdttrc20', 'usdterc20', ...]

// Step 3: Customer selects payment currency (e.g., 'btc')
$payCurrency = 'btc';

// Step 4: Validate amount meets minimum requirements
$validation = Checkout::validateAmount(49.99, 'usd', 'btc');

if (!$validation->isValid()) {
    throw new Exception($validation->getFirstError());
    // "Amount is below minimum payment requirement"
}

// Step 5: Get estimate for display
$estimate = Checkout::getEstimate(49.99, 'usd', 'btc');
echo $estimate->getFormattedEstimatedAmount();
// "0.00123456 BTC"

// Step 6: Create payment
$paymentResult = Checkout::createPayment(49.99, 'usd', 'btc', [
    'description' => 'Premium Plan Subscription',
    'order_id' => 'ORDER-12345',
    'fixed_rate' => true,           // Lock rate for 20 minutes
    'fee_paid_by_user' => false,    // Merchant pays network fee
    'metadata' => ['plan' => 'premium'],
]);

// Step 7: Display payment details to customer
echo "Send {$paymentResult->getPayAmount()} BTC to:";
echo $paymentResult->getPayAddress();
echo "QR Code URI: {$paymentResult->getQrCodeUri()}";
echo "Expires in: {$paymentResult->getMinutesUntilExpiration()} minutes";

// Step 8: Complete checkout (persist to database)
$customer = $user->createOrGetCustomer();
$payment = Checkout::completeCheckout($paymentResult, $customer, $user);

// Payment is now stored and PaymentCreated event is dispatched
```

### Alternative Flow (Hosted Invoice)

This flow redirects customers to a NOWPayments-hosted invoice page.

```php
use SerenityTechnologies\CashierNowPayments\Facades\Checkout;

// Create hosted invoice
$invoiceResult = Checkout::createInvoice(49.99, 'usd', [
    'description' => 'Premium Plan Subscription',
    'order_id' => 'ORDER-12345',
    'success_url' => route('payment.success'),
    'cancel_url' => route('payment.cancel'),
    'fixed_rate' => true,
]);

// Redirect customer to invoice page
return redirect($invoiceResult->invoice_url);

// After payment, customer is redirected to success_url
// Webhook will notify your application of payment status
```

### Session-Based Checkout

For multi-step checkout flows with state persistence:

```php
use SerenityTechnologies\CashierNowPayments\Facades\Checkout;

// Create checkout session
$session = Checkout::createSession(49.99, 'usd', [
    'description' => 'Order #12345',
    'success_url' => route('checkout.success'),
    'cancel_url' => route('checkout.cancel'),
    'metadata' => ['cart_id' => 456],
]);

// Store session ID in user's session
session(['checkout_session_id' => $session->getId()]);

// Later, retrieve session
$session = Checkout::getSession(session('checkout_session_id'));

if ($session === null || $session->isExpired()) {
    return redirect()->route('checkout.start');
}

// Get estimate for selected currency
$estimate = Checkout::getEstimate(
    $session->getAmount(),
    $session->getCurrency(),
    $session->getPayCurrency() ?? 'btc'
);

// Create payment using session data
$paymentResult = Checkout::createPayment(
    $session->getAmount(),
    $session->getCurrency(),
    $session->getPayCurrency() ?? 'btc',
    [
        'description' => $session->getDescription(),
        'order_id' => $session->getOrderId(),
        'success_url' => $session->getSuccessUrl(),
        'cancel_url' => $session->getCancelUrl(),
    ]
);
```

## API Reference

### CheckoutService Methods

#### `createSession(float $amount, string $currency, array $options = []): CheckoutSession`

Create a new checkout session that can be cached and retrieved later.

**Options:**
- `description` (string|null) - Payment description
- `order_id` (string|null) - Custom order ID (auto-generated if not provided)
- `success_url` (string) - Redirect URL after successful payment
- `cancel_url` (string) - Redirect URL after cancellation
- `pay_currency` (string|null) - Pre-selected payment currency
- `fixed_rate` (bool) - Lock exchange rate for 20 minutes
- `fee_paid_by_user` (bool) - Customer pays network fee
- `metadata` (array) - Additional metadata

**Returns:** `CheckoutSession` instance

---

#### `getSession(string $sessionId): ?CheckoutSession`

Retrieve an existing checkout session from cache.

**Returns:** `CheckoutSession` or `null` if expired/not found

---

#### `isApiAvailable(): bool`

Check if NOWPayments API is operational.

**Returns:** `true` if API status is "OK"

---

#### `getAvailableCurrencies(bool $fixedRate = false): array`

Get list of available cryptocurrencies for payment.

**Parameters:**
- `$fixedRate` - Whether to get currencies available for fixed-rate payments

**Returns:** Array of currency codes (e.g., `['btc', 'eth', 'usdttrc20']`)

---

#### `getMinimumPaymentAmount(string $fromCurrency, string $toCurrency): float`

Get minimum payment amount for a currency pair.

**Returns:** Minimum amount in fiat currency (e.g., `1.00`)

---

#### `getEstimate(float $amount, string $fromCurrency, string $toCurrency, bool $forceRefresh = false): EstimateResult`

Get estimated crypto amount for a fiat payment.

**Caching:** Results cached for 2 minutes (rates fluctuate frequently)

**Returns:** `EstimateResult` with estimated amount and fee

---

#### `validateAmount(float $amount, string $fromCurrency, string $toCurrency): ValidationResult`

Validate that the amount meets minimum payment requirements.

**Returns:** `ValidationResult` with validation status and errors

---

#### `createPayment(float $amount, string $currency, string $payCurrency, array $options = []): PaymentResult`

Create a direct payment on NOWPayments.

**Options:**
- All session options plus:
- `ipn_callback_url` (string) - Override default webhook URL
- `payout_address` (string|null) - Auto-payout destination address
- `payout_currency` (string|null) - Auto-payout currency
- `payout_extra_id` (string|null) - Memo/tag for payout

**Returns:** `PaymentResult` with payment details and QR code

---

#### `createInvoice(float $amount, string $currency, array $options = []): InvoiceResponse`

Create a hosted invoice on NOWPayments.

**Options:**
- All session options plus:
- `partially_paid_url` (string|null) - Redirect for partial payments

**Returns:** `InvoiceResponse` with invoice URL for redirect

---

#### `payInvoice(Invoice $invoice, string $payCurrency, array $options = []): PaymentResult`

Create a crypto payment for an existing invoice using NOWPayments' `createInvoicePayment` API.

**Flow:**
1. Create invoice (via `createInvoice()` or `InvoiceBuilder`)
2. Customer selects cryptocurrency to pay with
3. Call `payInvoice()` to generate deposit address + QR code
4. Display payment details to customer
5. Monitor payment status via webhooks or polling

**Options:**
- `payout_address` (string|null) - Address for refunds
- `metadata` (array) - Additional metadata

**Returns:** `PaymentResult` with deposit address, QR code, and payment details

**Throws:** `CheckoutException` if invoice is not active or amount is below minimum

**Example:**
```php
$invoice = Invoice::findOrFail($invoiceId);

$payment = Checkout::payInvoice($invoice, 'btc', [
    'payout_address' => 'bc1q...',
    'metadata' => ['custom_key' => 'value'],
]);

echo $payment->getPayAddress();  // BTC deposit address
echo $payment->getPayAmount();   // Amount in BTC
echo $payment->getQrCodeUri();   // crypto:bc1q...?amount=0.00123
```

---

#### `completeCheckout(PaymentResult $paymentResult, Customer $customer, ?Model $billable = null): Payment`

Persist payment to database and fire `PaymentCreated` event.

**Returns:** `Payment` Eloquent model

---

#### `generateQrCodeUri(string $address, float $amount): string`

Generate a QR code URI in `crypto:` format.

**Returns:** URI like `crypto:bc1qxy2...?amount=0.00123456`

---

### CheckoutSession Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getId()` | `string` | Unique session identifier |
| `getAmount()` | `float` | Payment amount in fiat |
| `getCurrency()` | `string` | Fiat currency code |
| `getDescription()` | `string\|null` | Payment description |
| `getOrderId()` | `string\|null` | Order reference ID |
| `getSuccessUrl()` | `string` | Success redirect URL |
| `getCancelUrl()` | `string` | Cancel redirect URL |
| `getPayCurrency()` | `string\|null` | Selected crypto currency |
| `hasFixedRate()` | `bool` | Whether fixed rate is enabled |
| `isFeePaidByUser()` | `bool` | Whether customer pays network fee |
| `getMetadata()` | `array` | Additional metadata |
| `getCreatedAt()` | `string` | Session creation time (ISO 8601) |
| `getExpiresAt()` | `string` | Session expiration time (ISO 8601) |
| `isExpired()` | `bool` | Whether session has expired |
| `toArray()` | `array` | All session data |

---

### EstimateResult Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getEstimatedAmount()` | `float` | Estimated crypto amount |
| `getFee()` | `float\|null` | Estimated network fee |
| `getFromCurrency()` | `string` | Source fiat currency |
| `getToCurrency()` | `string` | Target crypto currency |
| `getAmount()` | `float` | Original fiat amount |
| `getFormattedEstimatedAmount()` | `string` | Formatted with currency (e.g., "0.00123456 BTC") |
| `toArray()` | `array` | All estimate data |

---

### PaymentResult Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `getPaymentId()` | `string` | NOWPayments payment ID |
| `getPurchaseId()` | `string` | NOWPayments purchase ID |
| `getPayAddress()` | `string` | Crypto deposit address |
| `getPayAmount()` | `float` | Crypto amount to pay |
| `getPayCurrency()` | `string` | Crypto currency code |
| `getPriceAmount()` | `float` | Fiat price amount |
| `getPriceCurrency()` | `string` | Fiat currency code |
| `getOrderId()` | `string` | Order reference ID |
| `getDescription()` | `string\|null` | Payment description |
| `getQrCodeUri()` | `string` | QR code URI |
| `getExpirationTime()` | `string` | Expiration time (ISO 8601) |
| `getMetadata()` | `array` | Additional metadata |
| `getMinutesUntilExpiration()` | `int` | Minutes until payment expires |
| `isExpired()` | `bool` | Whether payment has expired |
| `toArray()` | `array` | All payment data |

---

### InvoiceReponse Methods

| Method        | Return Type | Description |
|---------------|-------------|-------------|
| `invoice_id`  | `string` | NOWPayments invoice ID |
| `invoice_url` | `string` | Hosted invoice URL |
| `order_id`    | `string` | Order reference ID |
| `description` | `string\|null` | Invoice description |
| `amount`      | `float` | Invoice amount |
| `currency`    | `string` | Currency code |
| `success_url` | `string` | Success redirect URL |
| `cancel_url`  | `string` | Cancel redirect URL |
| `expires_at`  | `string\|null` | Expiration time (ISO 8601) |
| `is_expired`  | `bool` | Whether invoice has expired |
| `toArray()`   | `array` | All invoice data |

---

### ValidationResult Methods

| Method | Return Type | Description |
|--------|-------------|-------------|
| `isValid()` | `bool` | Whether amount is valid |
| `getAmount()` | `float` | Original amount |
| `getEstimatedAmount()` | `float` | Estimated crypto amount |
| `getMinimumAmount()` | `float` | Minimum required amount |
| `getCurrency()` | `string` | Fiat currency |
| `getPayCurrency()` | `string` | Crypto currency |
| `getErrors()` | `array` | All validation errors |
| `getFirstError()` | `string\|null` | First validation error |

---

## Advanced Usage

### Integration with Billable Trait

```php
class User extends Authenticatable
{
    use \SerenityTechnologies\CashierNowPayments\Billable;
}

// Access checkout service from billable model
$user = auth()->user();

// Method 1: Via checkout() helper
$session = $user->checkout()->createSession(49.99, 'usd');

// Method 2: Via facade
$session = \SerenityTechnologies\CashierNowPayments\Facades\Checkout::createSession(49.99, 'usd');

// Method 3: Direct instantiation
$service = app(\SerenityTechnologies\CashierNowPayments\Services\CheckoutService::class);
$session = $service->createSession(49.99, 'usd');
```

### Controller Example

```php
namespace App\Http\Controllers;

use Illuminate\Http\Request;use SerenityTechnologies\CashierNowPayments\Exceptions\CheckoutException;use SerenityTechnologies\CashierNowPayments\Facades\Checkout;

class PaymentController extends Controller
{
    public function create(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string',
            'pay_currency' => 'required|string',
        ]);

        try {
            $paymentResult = Checkout::createPayment(
                $validated['amount'],
                $validated['currency'],
                $validated['pay_currency'],
                [
                    'description' => 'Order #' . $request->order_id,
                    'order_id' => 'ORDER-' . $request->order_id,
                ]
            );

            // Complete checkout
            $customer = $request->user()->createOrGetCustomer();
            $payment = Checkout::completeCheckout(
                $paymentResult,
                $customer,
                $request->user()
            );

            return response()->json([
                'success' => true,
                'payment' => $paymentResult->toArray(),
            ]);
        } catch (CheckoutException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        }
    }

    public function invoice(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string',
        ]);

        try {
            $invoiceResult = Checkout::createInvoice(
                $validated['amount'],
                $validated['currency'],
                [
                    'success_url' => route('payment.success'),
                    'cancel_url' => route('payment.cancel'),
                ]
            );

            return redirect($invoiceResult->invoice_url);
        } catch (CheckoutException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Create a crypto payment for an existing invoice.
     */
    public function payInvoice(string $invoiceId, Request $request)
    {
        $validated = $request->validate([
            'pay_currency' => 'required|string',
        ]);

        try {
            $invoice = \SerenityTechnologies\CashierNowPayments\Models\Invoice::findOrFail($invoiceId);

            $paymentResult = Checkout::payInvoice($invoice, $validated['pay_currency']);

            return response()->json([
                'success' => true,
                'payment' => $paymentResult->toArray(),
            ]);
        } catch (CheckoutException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getCode() ?: 500);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found.',
            ], 404);
        }
    }
}
```

### Exception Handling

```php
use SerenityTechnologies\CashierNowPayments\Exceptions\CheckoutException;

try {
    $result = Checkout::createPayment(49.99, 'usd', 'btc');
} catch (CheckoutException $e) {
    // Specific exception types
    if ($e->getCode() === 422) {
        // Validation error (e.g., below minimum)
    } elseif ($e->getCode() === 410) {
        // Session expired
    } elseif ($e->getCode() === 400) {
        // Currency unavailable
    } else {
        // API error
    }

    // Or use factory methods for custom exceptions
    throw CheckoutException::apiError('Payment failed', $e);
    throw CheckoutException::validationError('Invalid amount');
    throw CheckoutException::sessionExpired();
    throw CheckoutException::belowMinimum(49.99, 'usd', 1.00);
    throw CheckoutException::currencyUnavailable('xyz');
}
```

### Complete Checkout Flow with Error Handling

```php
public function checkout(Request $request)
{
    // Step 1: Verify API availability
    if (!Checkout::isApiAvailable()) {
        return response()->json([
            'success' => false,
            'message' => 'Payment system is currently unavailable. Please try again later.',
        ], 503);
    }

    // Step 2: Validate request
    $validated = $request->validate([
        'amount' => 'required|numeric|min:0.01',
        'currency' => 'required|string|in:usd,eur,gbp',
        'pay_currency' => 'required|string',
        'description' => 'nullable|string|max:500',
    ]);

    // Step 3: Check currency availability
    $availableCurrencies = Checkout::getAvailableCurrencies();
    if (!in_array($validated['pay_currency'], $availableCurrencies)) {
        throw CheckoutException::currencyUnavailable($validated['pay_currency']);
    }

    try {
        // Step 4: Validate amount
        $validation = Checkout::validateAmount(
            $validated['amount'],
            $validated['currency'],
            $validated['pay_currency']
        );

        if (!$validation->isValid()) {
            return response()->json([
                'success' => false,
                'message' => $validation->getFirstError(),
                'minimum' => $validation->getMinimumAmount(),
            ], 422);
        }

        // Step 5: Create payment
        $paymentResult = Checkout::createPayment(
            $validated['amount'],
            $validated['currency'],
            $validated['pay_currency'],
            [
                'description' => $validated['description'],
                'order_id' => 'ORDER-' . \Illuminate\Support\Str::ulid(),
                'fixed_rate' => config('cashier-nowpayments.fixed_rate'),
                'fee_paid_by_user' => config('cashier-nowpayments.fee_paid_by_user'),
            ]
        );

        // Step 6: Complete checkout (persist to database)
        $customer = $request->user()->createOrGetCustomer();
        $payment = Checkout::completeCheckout(
            $paymentResult,
            $customer,
            $request->user()
        );

        // Step 7: Return payment details
        return response()->json([
            'success' => true,
            'payment' => $paymentResult->toArray(),
            'local_payment_id' => $payment->id,
        ]);

    } catch (CheckoutException $e) {
        report($e);

        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
        ], $e->getCode() ?: 500);
    }
}
```

## Caching Strategy

The CheckoutService implements intelligent caching to reduce API calls:

| Data | Cache TTL | Rationale |
|------|-----------|-----------|
| Available Currencies | 1 hour | Currencies rarely change |
| Minimum Payment Amounts | 30 minutes | Minimums change infrequently |
| Estimates | 2 minutes | Exchange rates fluctuate frequently |
| Checkout Sessions | 30 minutes | Session lifetime |

To force fresh API calls:

```php
// Force fresh estimate (bypass cache)
$estimate = Checkout::getEstimate(49.99, 'usd', 'btc', forceRefresh: true);
```

## Configuration

The CheckoutService respects these configuration values:

```env
# Default webhook URL
CASHIER_NOWPAYMENTS_WEBHOOK_PATH=/nowpayments/webhook

# Fixed rate (lock exchange rate for 20 min)
CASHIER_NOWPAYMENTS_FIXED_RATE=false

# Fee paid by user (instead of merchant)
CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER=false

# Default app URL for success/cancel redirects
APP_URL=https://yourapp.com
```

## Events

When `completeCheckout()` is called, the `PaymentCreated` event is dispatched:

```php
// Listen in EventServiceProvider
protected $listen = [
    \SerenityTechnologies\CashierNowPayments\Events\PaymentCreated::class => [
        SendPaymentConfirmationEmail::class,
        LogPaymentForAnalytics::class,
    ],
];
```

## Testing

```php
// Mock the CheckoutService in tests
$this->mock(CheckoutService::class, function ($mock) {
    $mock->shouldReceive('createPayment')
        ->once()
        ->andReturn(new PaymentResult(
            paymentId: '12345',
            purchaseId: '67890',
            payAddress: 'bc1qxy2...',
            payAmount: 0.00123456,
            payCurrency: 'btc',
            priceAmount: 49.99,
            priceCurrency: 'usd',
            orderId: 'ORDER-123',
            description: 'Test Payment',
            qrCodeUri: 'crypto:bc1qxy2...?amount=0.00123456',
            expirationTime: now()->addMinutes(15)->toIso8601String(),
        ));
});

// Or use the facade
Checkout::shouldReceive('createPayment')
    ->andReturn(/* ... */);
```

## Migration Guide from Controllers

If you're currently using `CheckoutController` directly, you can migrate to the service:

**Before (Controller):**
```php
// In controller
$response = $this->checkoutController->createPayment($request);
```

**After (Service):**
```php
// In controller
$paymentResult = Checkout::createPayment(
    $request->amount,
    $request->currency,
    $request->pay_currency,
    $request->only(['description', 'order_id', 'metadata'])
);
```

The service provides cleaner, more testable code and can be used anywhere in your application (controllers, jobs, commands, etc.).

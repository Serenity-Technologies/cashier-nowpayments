# Embedded Checkout Widget Documentation

## Overview

The embedded checkout feature uses NOWPayments' official payment widget displayed in an iframe overlay. This provides a polished, native payment experience without building custom UI for crypto selection, QR codes, and payment tracking.

## Quick Start

### Basic Usage

```php
// In your controller
public function checkout()
{
    return redirect()->route('cashier-nowpayments.checkout.embedded', [
        'amount' => 49.99,
        'currency' => 'usd',
        'description' => 'Premium Plan',
        'success_url' => route('payment.success'),
        'cancel_url' => route('payment.cancel'),
    ]);
}
```

### Via Billable Trait

```php
// Generate embedded checkout URL
$url = $user->embeddedCheckoutUrl(49.99, 'usd', [
    'description' => 'Order #12345',
    'order_id' => 'ORD-123',
    'success_url' => route('payment.success'),
    'cancel_url' => route('cart'),
]);

return redirect($url);
```

### As a Link/Button

```blade
<a href="{{ $user->embeddedCheckoutUrl(49.99, 'usd', [
    'description' => 'Premium Plan',
    'success_url' => route('payment.success'),
]) }}">
    Pay with Crypto
</a>
```

## How It Works

### Flow Diagram

```
User clicks "Pay with Crypto"
        │
        ▼
GET /cashier-nowpayments/checkout/embedded
        │
        ├─── Creates invoice on NOWPayments API
        │    └── POST /v1/invoice
        │
        ├─── Stores invoice locally in database
        │
        └─── Renders checkout-embedded.blade.php
             │
             └─── Displays iframe widget
                  └─── src="https://nowpayments.io/embeds/payment-widget?iid={invoice_id}"
                       │
                       ├─── User selects crypto currency
                       ├─── User sends payment
                       ├─── NOWPayments processes payment
                       └─── User redirected to success_url
```

### Widget Features

The NOWPayments embedded widget provides:

✅ **Currency Selection** - Users can choose from available cryptocurrencies
✅ **Real-time Exchange Rates** - Live conversion rates displayed
✅ **QR Code Display** - Scannable QR code for mobile wallets
✅ **Payment Address** - Copyable deposit address
✅ **Payment Status** - Real-time payment status updates
✅ **Countdown Timer** - Shows time remaining for payment
✅ **Multi-language Support** - Automatic language detection
✅ **Mobile Responsive** - Works on all screen sizes
✅ **Success/Cancel Redirects** - Automatic redirect after payment

## Widget Widget Parameters

### Required Parameters

| Parameter | Type | Example | Description |
|-----------|------|---------|-------------|
| `amount` | float | `49.99` | Payment amount in fiat currency |
| `currency` | string | `'usd'` | Fiat currency code (usd, eur, gbp, etc.) |

### Optional Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `description` | string | `null` | Payment description shown in widget |
| `order_id` | string | Auto-generated | Your internal order reference |
| `success_url` | string | `config('app.url')` | Redirect URL after successful payment |
| `cancel_url` | string | `config('app.url')` | Redirect URL after cancellation |
| `metadata` | array | `[]` | Additional metadata stored with invoice |

## Implementation Details

### Route

```php
GET /cashier-nowpayments/checkout/embedded
```

**Route Name:** `cashier-nowpayments.checkout.embedded`

**Controller:** `CheckoutController@showEmbedded`

**Middleware:** `web` (configurable via `cashier-nowpayments.routes.middleware`)

### Controller Logic

The `showEmbedded` method:

1. **Validates** request parameters (amount, currency, URLs)
2. **Creates invoice** on NOWPayments API via `NowPayments::createInvoice()`
3. **Stores invoice** locally in database (for both authenticated and guest users)
4. **Builds widget URL** in format: `https://nowpayments.io/embeds/payment-widget?iid={invoice_id}`
5. **Renders view** `checkout-embedded.blade.php` with widget URL
6. **Falls back** to regular checkout if widget creation fails

### Error Handling

If the embedded widget fails to load:

- **10-second timeout** - Widget must load within 10 seconds
- **Loading spinner** - Shows while widget is loading
- **Error state** - Displays error message with retry button
- **Automatic fallback** - If invoice creation fails, redirects to regular checkout overlay

### Widget Widget States

#### 1. Loading State

```
┌─────────────────────────────┐
│  Cryptocurrency Payment     │
│  Premium Plan               │
│                             │
│      ⏳ (spinner)           │
│  Loading payment widget...  │
│                             │
│  Powered by NOWPayments     │
└─────────────────────────────┘
```

#### 2. Widget Loaded

```
┌─────────────────────────────┐
│  Cryptocurrency Payment     │
│  Premium Plan               │
│                             │
│ ┌─────────────────────────┐ │
│ │  NOWPayments Widget     │ │
│ │                         │ │
│ │  Select Currency:       │ │
│ │  [BTC] [ETH] [USDT]    │ │
│ │                         │ │
│ │  Amount: 0.00123 BTC    │ │
│ │                         │ │
│ │  [QR Code]              │ │
│ │                         │ │
│ │  Address: bc1qxy2...   │ │
│ │  [Copy]                 │ │
│ │                         │ │
│ │  ⏱️ 14:32 remaining     │ │
│ │                         │ │
│ │  Status: Waiting...     │ │
│ └─────────────────────────┘ │
│                             │
│  Powered by NOWPayments     │
└─────────────────────────────┘
```

#### 3. Error State

```
┌─────────────────────────────┐
│  Cryptocurrency Payment     │
│  Premium Plan               │
│                             │
│      ⚠️                     │
│  Unable to Load             │
│  Payment Widget             │
│                             │
│  The payment widget could   │
│  not be loaded. Please      │
│  check your internet        │
│  connection and try again.  │
│                             │
│  [Try Again]                │
│                             │
│  Powered by NOWPayments     │
└─────────────────────────────┘
```

## Customization

### Publishing the View

```bash
php artisan vendor:publish --tag=cashier-nowpayments-views
```

Edit: `resources/views/vendor/cashier-nowpayments/checkout-embedded.blade.php`

### Customizing Styles

The widget overlay uses inline CSS for easy customization:

```blade
<!-- Change overlay background -->
.nowpayments-overlay {
    background: rgba(0, 0, 0, 0.85);  /* Darker overlay */
}

<!-- Change widget container -->
.nowpayments-widget-container {
    max-width: 500px;  /* Wider widget */
}

<!-- Change header colors -->
.nowpayments-widget-header {
    background: linear-gradient(135deg, #your-color-1, #your-color-2);
}
```

### Widget Dimensions

The widget iframe is set to NOWPayments recommended dimensions:

```html
<iframe
    width="410"
    height="696"
    frameborder="0"
    scrolling="no"
    style="overflow-y: hidden;"
>
```

You can adjust these in `checkout-embedded.blade.php`:

```html
<iframe
    width="450"      <!-- Wider -->
    height="750"     <!-- Taller -->
    ...
>
```

### Custom Title and Description

```blade
<!-- In checkout-embedded.blade.php -->
<div class="nowpayments-widget-title">
    {{ $checkoutData['custom_title'] ?? __('Cryptocurrency Payment') }}
</div>
```

## Integration Examples

### E-Commerce Checkout

```php
public function checkout(Request $request, Cart $cart)
{
    $total = $cart->total();
    
    return redirect()->route('cashier-nowpayments.checkout.embedded', [
        'amount' => $total,
        'currency' => 'usd',
        'description' => 'Order #' . $cart->id,
        'order_id' => 'CART-' . $cart->id,
        'success_url' => route('checkout.success', $cart->id),
        'cancel_url' => route('cart'),
        'metadata' => [
            'cart_id' => $cart->id,
            'items' => $cart->items->pluck('id')->toArray(),
        ],
    ]);
}
```

### Subscription Payment

```php
public function subscribe(Request $request, Plan $plan)
{
    return redirect()->route('cashier-nowpayments.checkout.embedded', [
        'amount' => $plan->price,
        'currency' => 'usd',
        'description' => $plan->name . ' - ' . $plan->interval,
        'order_id' => 'SUB-' . $plan->id . '-' . auth()->id(),
        'success_url' => route('subscription.success'),
        'cancel_url' => route('plans.show', $plan),
        'metadata' => [
            'plan_id' => $plan->id,
            'user_id' => auth()->id(),
        ],
    ]);
}
```

### Invoice Payment

```php
public function payInvoice(Request $request, Invoice $invoice)
{
    return redirect()->route('cashier-nowpayments.checkout.embedded', [
        'amount' => $invoice->amount_due,
        'currency' => $invoice->currency,
        'description' => 'Invoice #' . $invoice->number,
        'order_id' => 'INV-' . $invoice->id,
        'success_url' => route('invoices.show', $invoice),
        'cancel_url' => route('invoices.show', $invoice),
        'metadata' => [
            'invoice_id' => $invoice->id,
        ],
    ]);
}
```

### Modal Integration (JavaScript)

Open embedded checkout in a modal dialog:

```javascript
function openEmbeddedCheckout(amount, currency, options = {}) {
    const params = new URLSearchParams({
        amount: amount,
        currency: currency,
        description: options.description || '',
        success_url: options.success_url || window.location.origin + '/payment/success',
        cancel_url: options.cancel_url || window.location.origin + '/payment/cancel',
    });

    // Create modal
    const modal = document.createElement('div');
    modal.style.cssText = `
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.75);
        backdrop-filter: blur(8px);
        z-index: 999999;
        display: flex;
        align-items: center;
        justify-content: center;
    `;

    const container = document.createElement('div');
    container.style.cssText = `
        width: 450px;
        height: 750px;
        background: white;
        border-radius: 16px;
        overflow: hidden;
        position: relative;
    `;

    const iframe = document.createElement('iframe');
    iframe.src = `/cashier-nowpayments/checkout/embedded?${params.toString()}`;
    iframe.style.cssText = `
        width: 100%;
        height: 100%;
        border: none;
    `;

    const closeBtn = document.createElement('button');
    closeBtn.innerHTML = '×';
    closeBtn.style.cssText = `
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(0, 0, 0, 0.5);
        color: white;
        border: none;
        border-radius: 50%;
        width: 32px;
        height: 32px;
        font-size: 24px;
        cursor: pointer;
        z-index: 10;
    `;
    closeBtn.onclick = () => modal.remove();

    container.appendChild(iframe);
    container.appendChild(closeBtn);
    modal.appendChild(container);
    document.body.appendChild(modal);

    // Close on background click
    modal.onclick = (e) => {
        if (e.target === modal) {
            modal.remove();
        }
    };
}

// Usage
openEmbeddedCheckout(49.99, 'usd', {
    description: 'Premium Plan',
    success_url: 'https://yoursite.com/success',
    cancel_url: 'https://yoursite.com/cancel',
});
```

## Events and Webhooks

### Invoice Created

When the embedded checkout loads, an invoice is created on NOWPayments:

```php
// Event dispatched
InvoiceCreated::dispatch($billable, $customer, $response);
```

### Payment Completed

When payment is completed via the widget:

```php
// Webhook handler processes payment
WebhookController::handleInvoice($data);

// Event dispatched
InvoicePaid::dispatch($invoice->billable, $customer, $invoice);
```

### postMessage Events

The embedded widget sends postMessage events to parent window:

```javascript
window.addEventListener('message', function(event) {
    // Widget loaded
    if (event.data.type === 'cashier-checkout-loaded') {
        console.log('Widget loaded successfully');
    }

    // Widget cancelled (user closed overlay)
    if (event.data.type === 'cashier-checkout-cancel') {
        console.log('Checkout cancelled');
    }
});
```

## Comparison with Regular Checkout

| Feature | Regular Checkout | Embedded Checkout |
|---------|-----------------|-------------------|
| **UI** | Custom built overlay | NOWPayments official widget |
| **Crypto Selection** | Custom UI | Widget handles selection |
| **QR Codes** | Client-side rendering | Widget renders QR codes |
| **Payment Flow** | Manual status polling | Widget handles payment flow |
| **Redirects** | Manual redirect after polling | Automatic redirect by widget |
| **Customization** | Full control over UI | Limited to widget options |
| **Maintenance** | Maintain custom UI | NOWPayments maintains widget |
| **Fallback** | N/A | Falls back to regular checkout |
| **Best For** | Custom branding needs | Quick integration, reliability |

## Configuration

### Widget URL Format

```
https://nowpayments.io/embeds/payment-widget?iid={invoice_id}
```

The `invoice_id` is obtained from the NOWPayments API response when creating an invoice.

### Timeout Settings

```php
// In checkout-embedded.blade.php
loadTimeout = setTimeout(function() {
    if (!iframeLoaded) {
        showError();
    }
}, 10000);  // 10 seconds
```

### Fallback Behavior

If invoice creation fails, the controller automatically falls back to the regular checkout overlay:

```php
catch (\Exception $e) {
    report($e);
    
    // Fallback to regular checkout
    return view('cashier-nowpayments::checkout', compact('checkoutData'));
}
```

## Troubleshooting

### Widget Not Loading

**Possible causes:**
1. **Network issues** - Check internet connection
2. **Invoice creation failed** - Check NOWPayments API key
3. **CORS restrictions** - Widget domain must be accessible
4. **Browser blocking** - Check browser console for errors

**Solutions:**
- Verify `NOWPAYMENTS_API_KEY` is set correctly
- Check Laravel logs for invoice creation errors
- Ensure browser allows third-party iframes
- Test with different browser

### Widget Shows Error

**Check:**
1. Invoice was created successfully
2. Invoice ID is valid
3. NOWPayments service is operational

**Debug:**
```php
// Check if invoice was created
\Log::info('Invoice created', ['invoice_id' => $invoice->id]);

// Check NOWPayments API status
$status = NowPayments::getStatus();
\Log::info('API Status', ['status' => $status]);
```

### Payment Not Completing

**Check:**
1. Webhook endpoint is accessible
2. IPN secret is configured correctly
3. Webhook logs show successful processing

**Debug:**
```php
// Check webhook logs
WebhookLog::where('processed', false)->get();

// Check invoice status
$invoice = Invoice::where('nowpayments_invoice_id', $invoiceId)->first();
\Log::info('Invoice status', ['status' => $invoice->status]);
```

## Best Practices

### 1. Always Provide Success_URL and CANCEL_URL

```php
'embeddedCheckoutUrl' => $user->embeddedCheckoutUrl(49.99, 'usd', [
    'success_url' => route('payment.success'),  // Required!
    'cancel_url' => route('payment.cancel'),     // Required!
]),
```

### 2. Use Unique Order IDs

```php
'order_id' => 'ORDER-' . Str::ulid(),  // Guaranteed unique
```

### 3. Store Metadata for Reconciliation

```php
'metadata' => [
    'user_id' => auth()->id(),
    'cart_id' => $cart->id,
    'plan_id' => $plan->id,
],
```

### 4. Handle Webhook Events

```php
// In EventServiceProvider
protected $listen = [
    InvoicePaid::class => [
        FulfillOrder::class,
        SendConfirmationEmail::class,
    ],
];
```

### 5. Test with Small Amounts

```php
// Test with $0.01
'amount' => 0.01,
'currency' => 'usd',
```

## Security Considerations

### Invoice Persistence

The embedded checkout stores invoices locally for both authenticated and guest users:

```php
// Authenticated users - linked to billable model
$user->invoice($amount, $currency)->generate();

// Guest users - linked to session-based customer
$customer = $this->getOrCreateGuestCustomer($request);
$localInvoice->fill([...])->save();
```

### Webhook Verification

All webhook notifications are verified with HMAC-SHA512:

```php
// In WebhookController
$signature = $request->header('x-nowpayments-sig');
$computedSignature = hash_hmac('sha512', $payload, $ipnSecret);
$isValid = hash_equals($computedSignature, $signature);
```

### Rate Limiting

Embedded checkout route is not rate-limited (invoice creation is idempotent), but you can add rate limiting if needed:

```php
Route::get('/checkout/embedded', [CheckoutController::class, 'showEmbedded'])
    ->middleware(['throttle:10,1'])
    ->name('checkout.embedded');
```

## Future Enhancements

### Planned Features

1. **Widget Event Listeners** - Listen for widget events (payment started, completed, failed)
2. **Custom Widget Themes** - Pass theme parameters to widget
3. **Multi-Invoice Support** - Display multiple invoices in single widget
4. **Payment Retry** - Retry failed payments from widget
5. **Analytics Integration** - Track widget load and conversion rates

### Contributing

To suggest improvements to the embedded checkout:

1. Fork the repository
2. Create feature branch (`git checkout -b feature/embedded-checkout-improvement`)
3. Commit changes (`git commit -m 'Add feature'`)
4. Push to branch (`git push origin feature/embedded-checkout-improvement`)
5. Open Pull Request

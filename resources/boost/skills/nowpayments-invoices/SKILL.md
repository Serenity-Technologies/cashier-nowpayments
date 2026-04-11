---
name: nowpayments-invoices
description: Create and manage hosted cryptocurrency invoices using the Laravel Cashier NOWPayments package, including invoice creation, embedded payment widget, guest invoice persistence, paying existing invoices, and invoice webhook handling.
---

# NOWPayments Invoices

## When to use this skill

Use this skill when:
- Creating hosted invoice pages on NOWPayments for users to pay
- Using the embedded payment widget for invoice payments
- Accepting payments from guests via hosted checkout
- Creating payments against existing invoices
- Handling invoice payment webhooks and status updates

## Billable Model Setup

```php
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

## Creating Invoices

### Option 1: Embedded Invoice Widget (Recommended)

Use NOWPayments' official payment widget for a polished invoice payment experience.

```php
// Quick redirect with embedded widget
return redirect()->route('cashier-nowpayments.checkout.embedded', [
    'amount' => 49.99,
    'currency' => 'usd',
    'description' => 'Invoice #INV-123',
    'success_url' => route('invoices.paid'),
    'cancel_url' => route('invoices.show', $invoice),
]);

// Via Billable trait
$url = $user->embeddedCheckoutUrl(49.99, 'usd', [
    'description' => 'Invoice #INV-123',
    'order_id' => 'INV-123',
    'success_url' => route('invoices.paid'),
    'cancel_url' => route('invoices.show', $invoice),
]);

return redirect($url);
```

**Widget handles:**
- Currency selection
- Real-time exchange rates
- QR code generation
- Payment tracking
- Auto-redirect to success_url

### Option 2: Fluent Builder (Authenticated User)

```php
$invoice = $user->invoice(49.99, 'usd')
    ->withDescription('Premium Plan - Monthly')
    ->withOrderId('INV-123')
    ->withSuccessUrl(route('payment.success'))
    ->withCancelUrl(route('cart'))
    ->withFixedRate()
    ->generate();

// Access invoice details
$invoice->invoice_url;  // Hosted invoice page URL
$invoice->amount;       // 49.99
$invoice->currency;     // 'usd'

// Redirect user to hosted invoice page
return redirect($invoice->invoice_url);

// Or use the convenience method
return $invoice->redirect();
```

### InvoiceBuilder Methods

| Method | Purpose |
|--------|---------|
| `withDescription($text)` | Invoice description |
| `withOrderId($id)` | Internal order reference |
| `withSuccessUrl($url)` | Redirect URL after successful payment |
| `withCancelUrl($url)` | Redirect URL after cancellation |
| `withFixedRate()` | Lock exchange rate for 20 minutes |
| `withMetadata($array)` | Additional key-value metadata |
| `generate()` | Create on API + persist locally (returns `Invoice` model) |
| `create()` | Create on API only (returns DTO, no persist) |

### Guest Invoice Creation

Guest users can also receive invoices. The invoice is linked to a session-based customer record.

```php
// In controller
public function createInvoice(Request $request)
{
    $validated = $request->validate([
        'amount' => 'required|numeric|min:0.01',
        'currency' => 'required|string',
    ]);

    // Creates guest customer automatically
    $invoice = Invoice::create([
        'amount' => $validated['amount'],
        'currency' => $validated['currency'],
        'email' => $request->email, // Optional
    ]);

    // Redirect to hosted invoice
    return redirect($invoice->invoice_url);
}
```

## Paying Existing Invoices

Create a payment against an existing invoice:

```php
$payment = $user->payInvoice($invoice, 'btc');

// Access payment details
$payment->pay_address;   // BTC deposit address
$payment->pay_amount;    // Amount in BTC
$payment->pay_currency;  // 'btc'
```

Or via the Invoice model:

```php
$payment = $invoice->pay('btc');
```

## Invoice Model

### Key Fields

| Field | Type | Description |
|-------|------|-------------|
| `nowpayments_invoice_id` | string | NOWPayments invoice ID |
| `status` | string | Invoice status (active, finished, failed, expired) |
| `currency` | string | Fiat currency code |
| `amount` | decimal | Invoice amount |
| `amount_paid` | decimal | Amount paid so far |
| `invoice_url` | string | Hosted invoice URL |
| `success_url` | string | Redirect on success |
| `cancel_url` | string | Redirect on cancel |
| `paid_at` | datetime | When invoice was paid |
| `expires_at` | datetime | When invoice expires |
| `metadata` | JSON | Additional data |

### Scopes

```php
Invoice::paid()->get();
Invoice::pending()->get();
Invoice::failed()->get();
Invoice::expired()->get();
```

### Methods

```php
$invoice->isPaid();      // status === 'finished' or 'paid'
$invoice->isActive();    // status === 'active'
$invoice->isExpired();   // expired or expires_at is past
$invoice->redirect();    // Redirect to invoice URL
$invoice->pay('btc');    // Create payment for invoice
```

## Invoice Payment via Embedded Widget

For a better UX, use the embedded widget to pay invoices:

```php
public function payInvoice(Invoice $invoice)
{
    return redirect()->route('cashier-nowpayments.checkout.embedded', [
        'amount' => $invoice->amount,
        'currency' => $invoice->currency,
        'description' => 'Invoice #' . $invoice->id,
        'order_id' => 'INV-' . $invoice->id,
        'success_url' => route('invoices.show', $invoice),
        'cancel_url' => route('invoices.show', $invoice),
        'metadata' => [
            'invoice_id' => $invoice->id,
        ],
    ]);
}
```

## Routes

| Method | URI | Middleware | Purpose |
|--------|-----|------------|---------|
| `GET` | `/checkout/embedded` | `web` | Embedded payment widget |
| `POST` | `/checkout/invoice` | `web`, `throttle:20,1` | Create invoice |
| `GET` | `/payment/status/{id}` | `web`, `throttle:30,1`, `auth` | Check payment status |
| `GET` | `/payment/local/{id}` | `web`, `throttle:30,1`, `auth` | Check local status |

## Events

- `InvoiceCreated` — Dispatched when invoice created on API
- `InvoicePaid` — Dispatched on webhook when invoice status is `finished`
- `InvoicePaymentFailed` — Dispatched on webhook when invoice status is `failed` or `expired`

## Webhook Handling

Invoice payments are processed via webhooks:

```php
// In WebhookController
protected function handleInvoice(array $data): void
{
    $invoice = Invoice::where('nowpayments_invoice_id', $data['invoice_id'])->first();
    
    if ($invoice) {
        $invoice->update([
            'status' => $data['payment_status'],
            'amount_paid' => $data['actually_paid'] ?? 0,
        ]);
        
        if ($data['payment_status'] === 'finished') {
            $invoice->update(['paid_at' => now()]);
            InvoicePaid::dispatch($invoice->billable, $customer, $invoice);
        } elseif (in_array($data['payment_status'], ['failed', 'expired'])) {
            InvoicePaymentFailed::dispatch($invoice->billable, $customer, $invoice);
        }
    }
}
```

## Configuration

```env
# Invoice defaults
CASHIER_NOWPAYMENTS_FIXED_RATE=false             # Lock exchange rate
CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER=false       # Payer covers fee

# Webhook
CASHIER_NOWPAYMENTS_WEBHOOK_PATH=/nowpayments/webhook
CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE=300        # 5 minutes
```

## Embedded Widget vs Hosted Invoice

| Feature | Embedded Widget | Hosted Invoice |
|---------|----------------|----------------|
| **Location** | Your site (iframe) | NOWPayments site |
| **Branding** | Your overlay | NOWPayments branding |
| **Control** | Full control over overlay | Limited control |
| **Redirect** | Optional | Required |
| **User Experience** | Seamless (stays on your site) | Leaves your site |
| **Best For** | Integrated experience | Quick setup |

### When to Use Embedded Widget

✅ Want seamless user experience
✅ Don't want users leaving your site
✅ Need custom overlay branding
✅ Want loading/error states

### When to Use Hosted Invoice

✅ Quick setup needed
✅ Don't want to maintain any UI
✅ Trust NOWPayments branding
✅ Simple redirect flow is fine

## Example: Complete Invoice Flow

```php
// 1. Create invoice
$invoice = $user->invoice(49.99, 'usd')
    ->withDescription('Premium Plan')
    ->withOrderId('INV-123')
    ->withSuccessUrl(route('payment.success'))
    ->withCancelUrl(route('cart'))
    ->generate();

// 2. Store in session for tracking
session(['invoice_id' => $invoice->id]);

// 3. Redirect to embedded widget
return redirect()->route('cashier-nowpayments.checkout.embedded', [
    'amount' => $invoice->amount,
    'currency' => $invoice->currency,
    'description' => $invoice->order_description,
    'order_id' => $invoice->order_id,
    'success_url' => $invoice->success_url,
    'cancel_url' => $invoice->cancel_url,
]);

// 4. Webhook updates invoice status automatically
// 5. InvoicePaid event dispatched
// 6. Listener grants access to premium features
```

## Troubleshooting

### Invoice Not Found

**Check:**
- NOWPayments API key is valid
- Invoice amount is above minimum
- Currency is supported

### Payment Not Credited

**Check:**
- Webhook endpoint is accessible
- IPN secret matches configuration
- Webhook logs show successful processing

```php
// Debug webhook logs
WebhookLog::where('processed', false)->get();
```

### Widget Not Loading

**Check:**
- Invoice was created successfully
- Invoice ID is valid
- NOWPayments service is operational
- Browser allows third-party iframes

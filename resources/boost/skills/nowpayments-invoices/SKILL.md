---
name: nowpayments-invoices
description: Create and manage hosted cryptocurrency invoices using the Laravel Cashier NOWPayments package, including invoice creation, guest invoice persistence, paying existing invoices, and invoice webhook handling.
---

# NOWPayments Invoices

## When to use this skill

Use this skill when:
- Creating hosted invoice pages on NOWPayments for users to pay
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

### Fluent Builder (Authenticated User)

```php
$invoice = $user->invoice(49.99, 'usd')
    ->withDescription('Monthly membership')
    ->withOrderId('INV-123')
    ->withSuccessUrl('https://yoursite.com/success')
    ->withCancelUrl('https://yoursite.com/cancel')
    ->withFixedRate()           // Lock rate for 20 min
    ->withMetadata(['plan' => 'premium'])
    ->generate();               // API + persist locally

return redirect($invoice->invoice_url);
```

### InvoiceBuilder Methods

| Method | Purpose |
|--------|---------|
| `withDescription($text)` | Invoice description |
| `withOrderId($id)` | Internal order reference |
| `withSuccessUrl($url)` | Redirect after successful payment (required) |
| `withCancelUrl($url)` | Redirect after cancellation (required) |
| `withFixedRate()` | Lock exchange rate for 20 minutes |
| `withMetadata($array)` | Additional metadata |
| `create()` | Create on API only (returns DTO, dispatches `InvoiceCreated`) |
| `generate()` | Create on API + persist locally (returns `Invoice` model) |

### Via Checkout Controller (Guest Support)

```php
// POST /cashier-nowpayments/checkout/invoice
// Creates invoice for BOTH authenticated and guest users
// Guest invoices are persisted with a guest Customer record
```

## Invoice Model

### Key Fields

| Field | Type | Description |
|-------|------|-------------|
| `nowpayments_invoice_id` | string | NOWPayments invoice ID |
| `status` | string | Invoice status |
| `currency` | string | Invoice currency |
| `amount` | decimal | Invoice amount |
| `amount_paid` | decimal | Amount paid so far |
| `order_id` | string | Internal order reference |
| `invoice_url` | string | NOWPayments hosted page URL |
| `success_url` | string | Success redirect URL |
| `cancel_url` | string | Cancel redirect URL |
| `paid_at` | datetime | When payment completed |
| `expires_at` | datetime | Invoice expiration |

### Methods

```php
$invoice->isPaid();    // Checks 'paid' AND 'finished' statuses
$invoice->isActive();  // status === 'active'
$invoice->isExpired(); // expires_at is past OR status === 'expired'
$invoice->redirect();  // Redirect to invoice_url
```

### Relationships

```php
$invoice->customer;   // BelongsTo Customer
$invoice->billable;   // MorphTo (User, etc.)
$invoice->payments;   // HasMany Payment (joined on order_id)
```

## Paying an Existing Invoice

```php
$payment = $user->payInvoice($invoice, 'btc', 'optional_payout_address');

// Creates a payment on the invoice via NOWPayments API
// Stores the payment locally linked to the invoice's customer
```

## Invoice Webhooks

When NOWPayments sends invoice status updates:

```php
// WebhookController::handleInvoice():
// 1. Finds Invoice by nowpayments_invoice_id
// 2. If not found: logs warning and returns
//    (handles dashboard-created invoices gracefully)
// 3. Updates status and amount_paid
// 4. Sets paid_at and dispatches InvoicePaid when 'finished'
// 5. Dispatches InvoicePaymentFailed when 'failed'/'expired'
```

## Guest Invoice Flow

```
User visits checkout → POST /checkout/invoice
    → NOWPayments creates invoice + returns invoice_url
    → persistInvoice() creates guest Customer
    → Invoice stored locally with customer_id
    → User redirected to NOWPayments hosted page
    → User pays → webhook fires → Invoice status updated
```

Guest invoices are reconciled via the session-based customer record created during checkout.

## Events

| Event | When |
|-------|------|
| `InvoiceCreated` | When `create()` or `generate()` is called |
| `InvoicePaid` | When webhook reports 'finished' status |
| `InvoicePaymentFailed` | When webhook reports 'failed'/'expired' |

## Invoices vs Direct Payments

| Aspect | Direct Payment | Invoice |
|--------|---------------|---------|
| UX | Embedded checkout overlay | Hosted NOWPayments page |
| Redirects | No | Yes (success/cancel URLs) |
| Guest support | Yes | Yes |
| Use case | Programmatic/embedded | One-off, better UX |

## Configuration

```env
CASHIER_NOWPAYMENTS_PAYMENT_METHOD=invoice   # Default: 'invoice' instead of 'payment'
CASHIER_NOWPAYMENTS_FIXED_RATE=false         # Lock exchange rate for invoices
```

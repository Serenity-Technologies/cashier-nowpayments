---
name: nowpayments-webhooks
description: Configure and handle NOWPayments IPN webhooks for payments, invoices, subscriptions, and payouts, including HMAC signature verification, timestamp validation, customer reconciliation, and local testing.
---

# NOWPayments Webhooks (IPN)

## When to use this skill

Use this skill when:
- Configuring IPN webhooks in the NOWPayments dashboard
- Handling payment, subscription, invoice, or payout status updates
- Reconciling webhook payloads with local database records
- Testing webhooks locally with ngrok
- Implementing custom webhook logic or event listeners

## Webhook Architecture

The webhook endpoint is automatically registered at:

```
POST /nowpayments/webhook
```

It uses the `api` middleware group (no CSRF protection) and delegates to `WebhookController`.

## Setup

### 1. Configure IPN Secret

```env
NOWPAYMENTS_IPN_SECRET=your_secret_key
```

Generate this key in the NOWPayments dashboard under **Settings > API Keys**.

### 2. Register Webhook URL

In the [NOWPayments Dashboard](https://account.nowpayments.io/settings), set:

```
Notification URL: https://your-app.com/nowpayments/webhook
```

For local development with ngrok:

```bash
ngrok http 8000
# Use: https://xxxx.ngrok-free.app/nowpayments/webhook
```

## Security

The webhook performs dual-layer verification:

### HMAC Signature Verification

```php
protected function verifySignature(Request $request): bool
{
    $signature = $request->header('x-nowpayments-sig');
    $payload = $request->getContent();
    $ipnSecret = config('cashier-nowpayments.ipn_secret');

    $computed = hash_hmac('sha512', $payload, trim($ipnSecret));
    return hash_equals($computed, $signature);
}
```

Returns `403` on mismatch.

### Timestamp Validation

```php
protected function validateTimestamp(array $data): bool
{
    $tolerance = config('cashier-nowpayments.webhook.tolerance', 300);
    // Rejects webhooks older than tolerance seconds
}
```

Default tolerance: 300 seconds (5 minutes).

## Webhook Processing

The `WebhookController` routes incoming payloads by detected fields:

### Detection Logic

```php
processWebhookData($data):
  if currency + address, no payment_id/subscription_id → handlePayout()
  if payment_id present → handlePayment()
  if subscription_id or plan_id present → handleSubscription()
  if invoice_id present → handleInvoice()
  if parent_payment_id present → handleReDeposit()
```

### Payment Webhooks (`handlePayment`)

```php
// Flow:
// 1. Find existing Payment by nowpayments_payment_id
// 2. If not found:
//    - Create/reconcile Customer via getOrCreateCustomerFromWebhook()
//    - Create Payment record with all payload fields
// 3. If found:
//    - Diff-based update (only changed fields)
// 4. Set paid_at when status is 'finished'
// 5. Dispatch PaymentReceived or PaymentFailed event
```

### Customer Reconciliation

The `getOrCreateCustomerFromWebhook()` method cascades through:

1. **Email lookup** — Find existing customer by email
2. **Order ID cache lookup** — Check `checkout.billable.{orderId}` cache (set during checkout)
3. **Metadata search** — Query `metadata->order_id`
4. **Fallback creation** — Create new customer with `nowpayments_customer_id = np_payment_{payment_id}`

### Subscription Webhooks (`handleSubscription`)

```php
// Flow:
// 1. Find Subscription by nowpayments_subscription_id
// 2. If found, update status
// 3. Fire events based on status transitions:
//    SubscriptionUpdated (always on change)
//    SubscriptionCancelled (cancelled/expired)
//    SubscriptionExpired (expired)
//    SubscriptionRenewed (changed to 'paid')
```

> Only processes subscriptions that exist locally. Dashboard-created subscriptions are skipped.

### Invoice Webhooks (`handleInvoice`)

```php
// Flow:
// 1. Find Invoice by nowpayments_invoice_id
// 2. If not found: log warning and return
// 3. Update status and amount_paid
// 4. Set paid_at and dispatch InvoicePaid when 'finished'
// 5. Dispatch InvoicePaymentFailed when 'failed'/'expired'
```

### Payout Webhooks (`handlePayout`)

```php
// Flow:
// 1. Find Payout by nowpayments_payout_id or batch_withdrawal_id
// 2. If found: update status, hash, error, processed_at
// 3. Dispatch PayoutStatusUpdated event
```

## Events Dispatched from Webhooks

| Event | Trigger |
|-------|---------|
| `PaymentReceived` | Payment status is `finished` |
| `PaymentFailed` | Payment status is `failed` or `expired` |
| `SubscriptionUpdated` | Subscription status changed |
| `SubscriptionCancelled` | Subscription cancelled or expired |
| `SubscriptionExpired` | Subscription expired |
| `SubscriptionRenewed` | Subscription renewed (status → paid) |
| `InvoicePaid` | Invoice payment finished |
| `InvoicePaymentFailed` | Invoice payment failed/expired |
| `PayoutStatusUpdated` | Payout status changed |

## Testing Webhooks

### Using Postman

Import `NOWPayments API.postman_collection.json` and use the webhook examples to send test payloads.

### Manual Test with curl

```bash
curl -X POST http://localhost:8000/nowpayments/webhook \
  -H "Content-Type: application/json" \
  -H "x-nowpayments-sig: $(echo -n '{"payment_id":"123","payment_status":"finished","price_amount":"10","price_currency":"usd","pay_amount":"0.001","pay_currency":"btc","actually_paid":"0.001","purchase_id":"abc"}' | openssl dgst -sha512 -hmac 'your_ipn_secret' | awk '{print $2}')" \
  -d '{
    "payment_id": "123",
    "payment_status": "finished",
    "price_amount": "10",
    "price_currency": "usd",
    "pay_amount": "0.001",
    "pay_currency": "btc",
    "actually_paid": "0.001",
    "purchase_id": "abc"
  }'
```

## Configuration

```env
CASHIER_NOWPAYMENTS_WEBHOOK_PATH=/nowpayments/webhook
CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE=300
```

# Laravel Cashier NOWPayments

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP Version](https://img.shields.io/badge/php-%5E8.2-blue)](https://www.php.net/)
[![Laravel Version](https://img.shields.io/badge/laravel-10%7C11%7C12%7C13-ff2d20)](https://laravel.com/)

> A Laravel Cashier-style package for accepting cryptocurrency payments and managing recurring subscriptions via the [NOWPayments](https://nowpayments.io) API.

---

## Features

- **One-Time Payments** — Direct crypto payments with a built-in checkout overlay UI
- **Hosted Invoices** — Generate NOWPayments hosted invoice pages with success/cancel redirects
- **Recurring Subscriptions** — Create plans, subscribe users, swap plans with automatic proration credits
- **Crypto Payouts** — Send single or batch payouts to external wallet addresses
- **Credit System** — Plan swap credits tracked with FIFO consumption and pessimistic locking
- **Guest Checkout** — Unauthenticated users can pay without an account
- **Webhook Handling** — HMAC + timestamp verified IPN callbacks for payments, invoices, subscriptions, and payouts
- **Idempotency** — Duplicate payment protection via SHA-256 keyed cache
- **JS Modal** — `CashierCheckout.open()` modal iframe for SPA integrations
- **Event-Driven** — 17 dispatchable events across 5 domains
- **Configurable Auth** — Payment status endpoints gated behind configurable auth guards

---

## Requirements

| Dependency | Version |
|------------|---------|
| PHP | 8.2+ |
| Laravel | 10.x, 11.x, 12.x, or 13.x |
| PHP Extensions | `ext-bcmath`, `ext-intl` |
| NOWPayments Account | [Merchant account](https://nowpayments.io) with API key |

---

## Installation

```bash
composer require serenity_technologies/cashier-nowpayments
```

### Publish Assets

```bash
# Config
php artisan vendor:publish --tag=cashier-nowpayments-config

# Views (checkout overlay)
php artisan vendor:publish --tag=cashier-nowpayments-views

# JavaScript assets
php artisan vendor:publish --tag=cashier-nowpayments-assets

```

### Run Migrations

```bash
# Recommended: uses the install command which applies table prefixes
php artisan cashier-nowpayments:install

# Then run the migrations
php artisan migrate
```

This creates 9 tables: `customers`, `payments`, `invoices`, `subscriptions`, `subscription_items`, `payouts`, `payout_withdrawals`, `credits`, and `plans`.

### Environment Variables

Add to your `.env`:

```env
# API Credentials
NOWPAYMENTS_API_KEY=your_api_key
NOWPAYMENTS_IPN_SECRET=your_ipn_secret

# Defaults
CASHIER_NOWPAYMENTS_CURRENCY=usd

# Webhook
CASHIER_NOWPAYMENTS_WEBHOOK_PATH=/nowpayments/webhook
CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE=300

# Routes
CASHIER_NOWPAYMENTS_ROUTE_PREFIX=cashier-nowpayments
CASHIER_NOWPAYMENTS_ROUTE_MIDDLEWARE=web

# Payment Behavior
CASHIER_NOWPAYMENTS_PAYMENT_METHOD=payment
CASHIER_NOWPAYMENTS_FIXED_RATE=false
CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER=false

# Payment Status Auth
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH=true
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD=web

# Caching
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=10
CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT=900
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=15

# Notifications
CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_RECEIVED=true
CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_FAILED=true
CASHIER_NOWPAYMENTS_NOTIFY_SUBSCRIPTION_ACTIVATED=true
```

---

## Quick Start

### 1. Prepare Your Billable Model

Add the `Billable` trait to your `User` model (or any billable entity):

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;
}
```

### 2. Create a One-Time Payment

```php
// Fluent builder — creates on NOWPayments + persists locally
$payment = $user->newPayment(49.99, 'usd')
    ->withPayCurrency('btc')
    ->withDescription('Premium ebook')
    ->withOrderId('ORDER-123')
    ->charge();

echo $payment->pay_address;   // BTC deposit address
echo $payment->pay_amount;    // Amount in BTC
```

### 3. Create a Hosted Invoice

```php
$invoice = $user->invoice(49.99, 'usd')
    ->withDescription('Monthly membership')
    ->withSuccessUrl('https://yoursite.com/success')
    ->withCancelUrl('https://yoursite.com/cancel')
    ->generate();

return redirect($invoice->invoice_url);
```

### 4. Create a Subscription Plan & Subscribe

```php
// Create the plan on NOWPayments + persist locally
$plan = $user->newPlan('premium-monthly')
    ->withName('Premium Monthly')
    ->withAmount(29.99)
    ->withCurrency('usd')
    ->withIntervalDays(30)
    ->create();

// Subscribe the user
$subscription = $user->newSubscription('default', $plan->id)
    ->withTrialDays(7)
    ->create();
```

### 5. Send a Payout

```php
// Single withdrawal
$payout = $user->payout()
    ->to('0xAbC...', 'eth', 1.5)
    ->withDescription('Affiliate commission')
    ->send();

// Batch payout
$payout = $user->payout()
    ->to('0xAbC...', 'eth', 1.0)
    ->to('0xDeF...', 'eth', 0.5)
    ->scheduledFor(now()->addHours(24))
    ->send();
```

### 6. Use the Checkout Overlay

**Blade helper:**

```blade
{!! $user->checkoutButton(49.99, 'usd', [
    'text' => 'Pay with Crypto',
    'description' => 'Order #12345',
    'success_url' => route('payment.success'),
    'cancel_url' => route('cart'),
]) !!}
```

**JS modal (SPA):**

```javascript
import { CashierCheckout } from './cashier-checkout';

CashierCheckout.open({
    amount: 49.99,
    currency: 'usd',
    description: 'Premium Plan',
    success_url: 'https://yoursite.com/success',
    cancel_url: 'https://yoursite.com/cancel',
}).then(result => {
    console.log('Payment:', result.purchase_id);
}).catch(err => {
    console.log('Cancelled');
});
```

### 7. Configure Webhooks

Register your webhook URL in the [NOWPayments Dashboard](https://account.nowpayments.io/settings):

```
https://your-app.com/nowpayments/webhook
```

The package handles HMAC SHA-512 signature verification and timestamp validation automatically.

---

## Architecture

### The Billable Trait

The single `Billable` trait aggregates **11 concern traits** that form the complete API surface:

| Concern | Key Methods |
|---------|-------------|
| `ManagesCustomer` | `customer()`, `createOrGetCustomer()` |
| `ManagesPayments` | `charge()`, `payments()`, `remotePayments()`, `estimateCrypto()` |
| `ManagesInvoices` | `invoice()`, `invoices()`, `payInvoice()` |
| `ManagesSubscriptions` | `newSubscription()`, `newPlan()`, `subscription()`, `subscribed()` |
| `ManagesPayouts` | `payout()`, `payouts()`, `remotePayouts()`, `validatePayoutAddress()` |
| `ManagesBalance` | `balance()` |
| `ManagesCurrencies` | `availableCurrencies()`, `fullCurrencies()`, `merchantCoins()` |
| `ManagesConversions` | `convert()`, `remoteConversions()` |
| `ManagesFiatPayouts` | `fiatProviders()`, `supportedFiatCurrencies()`, `fiatPaymentMethods()` |
| `ManagesPlans` | `listPlans()`, `updatePlan()` |
| `ProvidesCheckoutHelpers` | `checkoutButton()`, `checkoutUrl()` |

### Database Schema

```
Billable (User)
    └── Customer (MorphOne)
            ├── Payment[] (HasMany)
            ├── Invoice[] (HasMany)
            ├── Subscription[] (HasMany)
            │       ├── SubscriptionItem[] (HasMany)
            │       └── Credit[] (HasMany)
            ├── Payout[] (HasMany)
            │       └── PayoutWithdrawal[] (HasMany)
            ├── Credit[] (HasMany)
            └── Plan (referenced by Subscription)
```

### Routes

All routes are registered under the configured prefix (default: `cashier-nowpayments`):

| Method | URI | Middleware | Purpose |
|--------|-----|------------|---------|
| `GET` | `/checkout` | `web` | Checkout overlay view |
| `POST` | `/checkout/payment` | `web`, `throttle:30,1` | Create payment (AJAX) |
| `POST` | `/checkout/invoice` | `web`, `throttle:20,1` | Create invoice + redirect |
| `POST` | `/checkout/subscription` | `web`, `throttle:10,1` | Create subscription + redirect |
| `GET` | `/checkout/currencies` | `web` | List supported currencies (cached 1h) |
| `POST` | `/checkout/estimate` | `web`, `throttle:60,1` | Get crypto estimate |
| `GET` | `/payment/status/{id}` | `web`, `throttle:30,1`, `auth` | Poll remote payment status |
| `GET` | `/payment/local/{id}` | `web`, `throttle:30,1`, `auth` | Check local payment status |
| `POST` | `/nowpayments/webhook` | `api` | IPN webhook (no CSRF) |

---

## Documentation

Comprehensive how-to guides are available in [`docs/how-to/`](docs/how-to/):

| Guide | Covers |
|-------|--------|
| [Installation & Setup](docs/how-to/installation-and-setup.md) | Requirements, install, config, Billable trait, queue, webhooks |
| [One-Time Payments](docs/how-to/one-time-payments.md) | PaymentBuilder, checkout overlay, JS modal, guest checkout |
| [Invoice Payments](docs/how-to/invoice-payments.md) | InvoiceBuilder, hosted invoices, guest invoices |
| [Subscriptions & Plans](docs/how-to/subscriptions-and-plans.md) | PlanBuilder, subscribe, swap with proration, cancel |
| [Payouts](docs/how-to/payouts.md) | Batch payouts, scheduled payouts, address validation |
| [Credit System](docs/how-to/credit-system.md) | Swap credits, FIFO consumption, expiration |
| [Webhooks](docs/how-to/webhooks.md) | IPN config, HMAC verification, webhook handlers, testing |
| [Events & Notifications](docs/how-to/events-and-notifications.md) | 17 events, listeners, Laravel notifications |
| [Advanced Features](docs/how-to/advanced-features.md) | Currencies, conversions, fiat payouts, refunds, custom models |
| [Testing & Troubleshooting](docs/how-to/testing-and-troubleshooting.md) | Unit/feature testing, common issues, security checklist |

---

## Events

The package dispatches 17 events across 5 domains:

**Payment:** `PaymentCreated`, `PaymentReceived`, `PaymentFailed`, `PaymentStatusSynced`, `PaymentRefunded`

**Invoice:** `InvoiceCreated`, `InvoicePaid`, `InvoicePaymentFailed`

**Subscription:** `SubscriptionCreated`, `SubscriptionUpdated`, `SubscriptionCancelled`, `SubscriptionExpired`, `SubscriptionRenewed`

**Payout:** `PayoutCreated`, `PayoutStatusUpdated`

**Credit:** `CreditExpired`

Listen to events in your `EventServiceProvider`:

```php
protected $listen = [
    \SerenityTechnologies\CashierNowPayments\Events\PaymentReceived::class => [
        SendPaymentConfirmationEmail::class,
    ],
];
```

---

## Customizing Models

Override any model via config:

```php
// config/cashier-nowpayments.php
'model' => [
    'customer' => \App\Models\Customer::class,
    'payment' => \App\Models\Payment::class,
    // ...
],
```

Your custom models must extend the package's base models.

---

## Queue Configuration

Events and notifications are dispatched synchronously by default. For production, configure a queue driver:

```env
QUEUE_CONNECTION=database
```

Then run a queue worker:

```bash
php artisan queue:work --tries=3
```

---

## Security

- Webhook HMAC signatures verified with SHA-512 + constant-time comparison (`hash_equals`)
- Timestamp tolerance prevents replay attacks (default: 300s)
- Payment status endpoints gated behind configurable auth guards with ownership verification
- Checkout endpoints rate-limited
- Payment creation uses idempotency keys (SHA-256, 5-min cache)

---

## Testing

```bash
composer test
```

Uses [Orchestra Testbench](https://github.com/orchestral/testbench). Mock the NOWPayments API facade with `NowPayments::shouldReceive()`.

---

## License

The MIT License (MIT). See [LICENSE](LICENSE) for details.

---

## Author

**Kwadwo Kyeremeh** — [kyerematics@gmail.com](mailto:kyerematics@gmail.com)

Built on top of [serenity_technologies/nowpayments](https://github.com/serenity-technologies/nowpayments) — the NOWPayments PHP SDK.

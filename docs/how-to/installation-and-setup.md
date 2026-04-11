# Installation & Initial Setup

This guide walks you through installing and configuring the Laravel Cashier NOWPayments package from scratch. By the end, your application will be ready to accept cryptocurrency payments through NOWPayments.

---

## Requirements

Before installing the package, ensure your environment meets the following prerequisites:

| Requirement | Version / Detail |
|---|---|
| **PHP** | 8.2 or higher |
| **Laravel** | 10.x, 11.x, 12.x, or 13.x |
| **PHP Extensions** | `ext-bcmath`, `ext-intl` |
| **Database** | Any Laravel-supported database (MySQL, PostgreSQL, SQLite) |
| **NOWPayments Account** | A merchant account with an API key ([Sign up at NOWPayments](https://nowpayments.io)) |

### Installing PHP Extensions

If the required extensions are not already installed:

```bash
# ext-bcmath (arbitrary precision math)
sudo apt-get install php-bcmath        # Debian/Ubuntu
sudo yum install php-bcmath            # RHEL/CentOS

# ext-intl (internationalization)
sudo apt-get install php-intl          # Debian/Ubuntu
sudo yum install php-intl              # RHEL/CentOS

# Restart PHP-FPM after installing extensions
sudo systemctl restart php8.2-fpm
```

Verify the extensions are loaded:

```bash
php -m | grep -E "bcmath|intl"
# Should output:
# bcmath
# intl
```

---

## Step 1: Install via Composer

Add the package to your Laravel project using Composer:

```bash
composer require serenity_technologies/cashier-nowpayments
```

This installs the package along with its dependencies, including the underlying `serenity_technologies/nowpayments` SDK.

Laravel's package auto-discovery will automatically register the service provider. If your application has package discovery disabled, manually register the provider in `config/app.php`:

```php
'providers' => [
    // ...
    SerenityTechnologies\CashierNowPayments\CashierNowPaymentsServiceProvider::class,
],
```

You may also register the facade alias:

```php
'aliases' => [
    // ...
    'CashierNowPayments' => SerenityTechnologies\CashierNowPayments\Facades\CashierNowPayments::class,
],
```

---

## Step 2: Publish Assets

The package ships with a configuration file, views, JavaScript assets, and database migration stubs. Publish them using Laravel's `vendor:publish` artisan command.

### Publish Configuration

```bash
php artisan vendor:publish --tag=cashier-nowpayments-config
```

This creates `config/cashier-nowpayments.php` with the full default configuration.

### Publish Views

```bash
php artisan vendor:publish --tag=cashier-nowpayments-views
```

This copies the checkout overlay views to `resources/views/vendor/cashier-nowpayments/`. You can customize these views to match your application's design.

### Publish JavaScript Assets

```bash
php artisan vendor:publish --tag=cashier-nowpayments-assets
```

This copies the frontend JavaScript assets to `resources/js/vendor/cashier-nowpayments/`. These handle the checkout overlay modal, payment creation, and status polling.

### Publish Migrations (Optional)

```bash
php artisan vendor:publish --tag=cashier-nowpayments-migrations
```

This publishes raw migration stubs to `database/migrations/`. **This is rarely needed** -- the recommended way to install migrations is via the dedicated command in Step 3.

### Publish Everything at Once

```bash
php artisan vendor:publish --provider="SerenityTechnologies\CashierNowPayments\CashierNowPaymentsServiceProvider"
```

---

## Step 3: Run Migrations

The package requires several database tables to store customers, payments, invoices, subscriptions, payouts, plans, and related data. Use the dedicated install command:

```bash
php artisan cashier-nowpayments:install
```

This command copies the migration stubs into your `database/migrations/` directory with the current timestamp prefix. It respects the `CASHIER_NOWPAYMENTS_TABLE_PREFIX` setting, so table names will be prefixed accordingly.

You will see output like:

```
Installing Cashier NOWPayments migrations...
  2025_04_09_000001_create_cashier_nowpayments_customer_table.php ............ CREATED
  2025_04_09_000001_create_cashier_nowpayments_subscription_table.php ....... CREATED
  2025_04_09_000001_create_cashier_nowpayments_subscription_item_table.php .. CREATED
  2025_04_09_000001_create_cashier_nowpayments_payment_table.php .......... CREATED
  2025_04_09_000001_create_cashier_nowpayments_invoice_table.php .......... CREATED
  2025_04_09_000001_create_cashier_nowpayments_payout_table.php ........... CREATED
  2025_04_09_000001_create_cashier_nowpayments_credits_table.php ......... CREATED
  2025_04_09_000001_create_cashier_nowpayments_plans_table.php ........... CREATED
  2025_04_09_000001_create_cashier_nowpayments_payout_withdrawals_table.php . CREATED

Migration installation complete! (9 created, 0 skipped)
Run `php artisan migrate` to execute the migrations.
```

Then run the migrations:

```bash
php artisan migrate
```

This creates the following tables (prefix defaults to `cashier_nowpayments_`):

| Table | Purpose |
|---|---|
| `cashier_nowpayments_customer` | Links billable models to NOWPayments customer IDs |
| `cashier_nowpayments_payment` | Local records of one-time payments |
| `cashier_nowpayments_invoice` | Local records of invoice-based payments |
| `cashier_nowpayments_subscription` | Subscription records with trial/period tracking |
| `cashier_nowpayments_subscription_item` | Individual items within a subscription |
| `cashier_nowpayments_payout` | Outgoing crypto payout records |
| `cashier_nowpayments_payout_withdrawals` | Payout withdrawal details |
| `cashier_nowpayments_credits` | Credit/expiry tracking |
| `cashier_nowpayments_plans` | Local plan definitions synced from NOWPayments |

### Reinstalling Migrations

If you need to re-run the install command (for example after changing the table prefix), use the `--force` flag to overwrite existing migration files:

```bash
php artisan cashier-nowpayments:install --force
```

---

## Step 4: Environment Configuration

Add the following variables to your `.env` file. They are grouped by category for clarity.

### API Credentials

These are required. Obtain them from your [NOWPayments account settings](https://account.nowpayments.io/settings).

```env
# Your NOWPayments API key (required)
NOWPAYMENTS_API_KEY=your-api-key-here

# IPN secret key used to verify webhook signatures (required for production)
NOWPAYMENTS_IPN_SECRET=your-ipn-secret-here
```

> **Where to find your API key:** Log in to your NOWPayments account, go to **Settings > API Keys**, and generate a new key if you do not already have one.
>
> **IPN Secret:** This is the secret used to sign incoming webhook payloads. Set it in the NOWPayments dashboard under **Settings > IPN (Instant Payment Notification)**. The same secret must be configured here so the package can verify webhook signatures.

### Currency & Table Prefix

```env
# Default currency for payments and subscriptions.
# Should match your NOWPayments payout wallet currency.
CASHIER_NOWPAYMENTS_CURRENCY=usd

# Prefix for all Cashier NOWPayments database tables.
# Change this to avoid conflicts if you run multiple installations.
CASHIER_NOWPAYMENTS_TABLE_PREFIX=cashier_nowpayments_
```

### Webhook Configuration

```env
# The URL path that NOWPayments will POST payment events to.
# This is combined with your APP_URL to form the full IPN callback URL.
CASHIER_NOWPAYMENTS_WEBHOOK_PATH=/nowpayments/webhook

# Tolerance in seconds for webhook timestamp verification.
# Webhooks with timestamps older than this will be rejected.
CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE=300
```

### Route Configuration

The package registers routes for the checkout overlay, payment creation, and status polling.

```env
# URL prefix for all cashier routes (checkout, payment status, etc.)
CASHIER_NOWPAYMENTS_ROUTE_PREFIX=cashier-nowpayments

# Name prefix for named routes
CASHIER_NOWPAYMENTS_ROUTE_NAME=cashier-nowpayments.

# Middleware stack applied to cashier routes.
# Comma-separated. Defaults to 'web' only.
CASHIER_NOWPAYMENTS_ROUTE_MIDDLEWARE=web
```

To protect checkout routes with authentication, add your auth middleware:

```env
CASHIER_NOWPAYMENTS_ROUTE_MIDDLEWARE=web,auth
```

### Payment Behavior

```env
# Default payment method: 'payment' for direct payments or 'invoice' for hosted invoice pages.
CASHIER_NOWPAYMENTS_PAYMENT_METHOD=payment

# When true, the crypto exchange rate is locked for 20 minutes after payment creation.
CASHIER_NOWPAYMENTS_FIXED_RATE=false

# When true, the network fee is charged to the user instead of the merchant.
CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER=false
```

### Payment Status & Caching

```env
# Whether payment status endpoints require authentication.
# When true, only authenticated users can check their own payment status.
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH=true

# The guard used for payment status authentication.
# Options: 'web', 'api', 'sanctum', or any custom guard.
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD=web

# Cache duration (seconds) for remote payment status polling.
# Prevents excessive API calls when the frontend polls every few seconds.
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=10

# How long (in seconds) a pending payment remains valid before the
# frontend displays a timeout. NOWPayments holds addresses for ~15-20 minutes.
CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT=900

# Minimum seconds between NOWPayments API sync calls for a single
# pending payment. Prevents excessive API usage from multiple polling clients.
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=15
```

### Notifications

Control which notifications are dispatched to the billable model when events occur.

```env
# Notify when a payment is received/confirmed
CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_RECEIVED=true

# Notify when a payment fails
CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_FAILED=true

# Notify when a subscription is activated
CASHIER_NOWPAYMENTS_NOTIFY_SUBSCRIPTION_ACTIVATED=true
```

### Complete `.env` Block

Here is a complete block you can copy and paste into your `.env` file:

```env
# ========================================
# NOWPayments / Cashier NOWPayments
# ========================================
NOWPAYMENTS_API_KEY=your-api-key-here
NOWPAYMENTS_IPN_SECRET=your-ipn-secret-here
CASHIER_NOWPAYMENTS_CURRENCY=usd
CASHIER_NOWPAYMENTS_TABLE_PREFIX=cashier_nowpayments_
CASHIER_NOWPAYMENTS_WEBHOOK_PATH=/nowpayments/webhook
CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE=300
CASHIER_NOWPAYMENTS_ROUTE_PREFIX=cashier-nowpayments
CASHIER_NOWPAYMENTS_ROUTE_NAME=cashier-nowpayments.
CASHIER_NOWPAYMENTS_ROUTE_MIDDLEWARE=web
CASHIER_NOWPAYMENTS_PAYMENT_METHOD=payment
CASHIER_NOWPAYMENTS_FIXED_RATE=false
CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER=false
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH=true
CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD=web
CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS=10
CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT=900
CASHIER_NOWPAYMENTS_SYNC_COOLDOWN=15
CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_RECEIVED=true
CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_FAILED=true
CASHIER_NOWPAYMENTS_NOTIFY_SUBSCRIPTION_ACTIVATED=true
```

---

## Step 4.5: Download Currency Images (Optional)

The package includes a command to download all 218 currency logos from NOWPayments CDN for the enhanced currency selector:

```bash
php artisan cashier-nowpayments:download-currency-images
```

**Output:**
```
 INFO  Found 242 currencies. Downloading images...
 242/242 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 INFO  Downloaded: 218.
 INFO  Failed: 24.
```

Force re-download with `--force` flag. Images are saved to `resources/views/vendor/cashier-nowpayments/assets/coins/` and served from `/vendor/cashier-nowpayments/coins/{code}.svg`.

---

## Step 5: Set Up the Billable Trait

Add the `Billable` trait to any Eloquent model that should be able to make payments -- typically your `User` model.

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use SerenityTechnologies\CashierNowPayments\Billable;

class User extends Authenticatable
{
    use Billable;

    // ... your existing attributes and methods
}
```

That is all you need. The `Billable` trait is a single trait that aggregates **11 concern traits** behind the scenes:

| Concern Trait | Responsibility |
|---|---|
| `ManagesCustomer` | Customer relationship with NOWPayments; creates and links customer records |
| `ManagesPayments` | One-time crypto payments, estimates, minimum amounts |
| `ManagesInvoices` | Invoice-based billing and invoice payment flows |
| `ManagesSubscriptions` | Recurring subscription billing, trials, plan management |
| `ManagesPayouts` | Outgoing crypto payouts to external wallets |
| `ManagesBalance` | Querying your NOWPayments account balance |
| `ManagesCurrencies` | Discovering available crypto and fiat currencies |
| `ManagesConversions` | Crypto-to-crypto conversion on the NOWPayments platform |
| `ManagesFiatPayouts` | Fiat provider queries and fiat payout support |
| `ManagesPlans` | Listing and updating subscription plans via the API |
| `ProvidesCheckoutHelpers` | Generating checkout URLs and button HTML for the frontend |

You never need to use these traits individually -- `Billable` pulls them all in automatically.

### Customizing the Billable Model

If your model uses non-standard column names for email or name, override the helper methods:

```php
class User extends Authenticatable
{
    use Billable;

    protected function getBillableEmail(): ?string
    {
        return $this->email_address;
    }

    protected function getBillableName(): ?string
    {
        return $this->full_name;
    }
}
```

### Polymorphic Relationships

The `Billable` trait uses polymorphic relationships. This means **any** Eloquent model can be billable -- not just `User`. For example, you could make an `Organization` model billable:

```php
use Illuminate\Database\Eloquent\Model;
use SerenityTechnologies\CashierNowPayments\Billable;

class Organization extends Model
{
    use Billable;
}
```

The `Customer` table stores both `billable_id` and `billable_type` to track which model owns each customer record.

---

## Step 6: Queue Configuration

The package dispatches events for payment lifecycle changes, subscription updates, and payouts. If you want notification handlers and event listeners to process asynchronously (recommended for production), configure a queue worker.

### Set Up the Queue Driver

In your `.env` file, set the queue connection:

```env
QUEUE_CONNECTION=database
```

For the database queue driver, create the jobs table and run the migration:

```bash
php artisan queue:table
php artisan migrate
```

Alternatively, use Redis for higher-throughput scenarios:

```env
QUEUE_CONNECTION=redis
```

### Start the Queue Worker

Run a queue worker to process queued jobs (notifications, event listeners, etc.):

```bash
php artisan queue:work --tries=3
```

For production, use a process manager such as [Supervisor](https://laravel.com/docs/queues#supervisor-configuration) to keep workers running:

```ini
[program:laravel-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /path/to/your/project/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasuser=false
killasgroup=true
user=www-data
numprocs=8
redirect_stderr=true
stdout_logfile=/path/to/your/project/storage/logs/worker.log
```

### Dispatching Events to Queues

By default, events in Laravel are dispatched synchronously unless the listener implements `ShouldQueue`. If you want the package's built-in notifications to be queued, ensure your notification listeners implement `ShouldQueue` or configure the notification channels to use the `queue` driver:

```env
MAIL_MAILER=smtp
NOTIFICATIONS_QUEUE_CONNECTION=database
```

---

## Step 7: Register the Webhook URL in NOWPayments

For the package to receive real-time payment status updates, you must register your webhook URL in the NOWPayments dashboard.

### Determine Your Webhook URL

The webhook URL is constructed from your application URL and the configured webhook path:

```
{APP_URL}{CASHIER_NOWPAYMENTS_WEBHOOK_PATH}
```

With default settings and `APP_URL=https://yourapp.com`:

```
https://yourapp.com/nowpayments/webhook
```

You can also generate the URL programmatically if the `GeneratesWebhookUrl` trait is available in your codebase.

### Configure IPN in the NOWPayments Dashboard

1. Log in to your [NOWPayments account](https://account.nowpayments.io).
2. Navigate to **Settings > IPN (Instant Payment Notification)**.
3. In the **IPN URL** field, enter your full webhook URL (e.g., `https://yourapp.com/nowpayments/webhook`).
4. Set an **IPN Secret Key** -- this is the same value you configured as `NOWPAYMENTS_IPN_SECRET` in your `.env` file.
5. Select which payment events should trigger IPN callbacks. At minimum, enable:
   - `payment` -- one-time payment status changes
   - `invoice` -- invoice status changes
   - `subscription` -- subscription lifecycle events
6. Save your settings.

### Verifying the Webhook

After registering the URL, you can verify the webhook is receiving events by checking your Laravel logs or using the webhook test endpoint in the NOWPayments dashboard (if available).

The package verifies incoming webhook signatures using the IPN secret. If signatures do not match, the webhook will be rejected with an error response.

### Important Notes

- The webhook route is registered with the `api` middleware group by default (not `web`). It does not use sessions or CSRF protection.
- Make sure your webhook endpoint is publicly accessible -- NOWPayments must be able to reach it.
- If you are developing locally, use a tunneling tool like [ngrok](https://ngrok.com/) to expose your local server:

```bash
ngrok http 8000
```

Then register the ngrok URL (`https://abc123.ngrok-free.app/nowpayments/webhook`) in the NOWPayments dashboard.

---

## What's Next?

With installation complete, you can now:

- [Create your first payment](./creating-payments.md)
- [Set up subscription billing](./subscriptions.md)
- [Build a checkout flow](./checkout.md)
- [Handle webhooks and events](./webhooks.md)

For a detailed reference on all methods available on the `Billable` trait, see the [Billable Trait documentation](./billable-trait.md).

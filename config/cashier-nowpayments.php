<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be applied to all Cashier NOWPayments database tables.
    | Customize this to avoid conflicts with other installations.
    |
    */

    'prefix' => env('CASHIER_NOWPAYMENTS_TABLE_PREFIX', 'cashier_nowpayments_'),

    /*
    |--------------------------------------------------------------------------
    | Default Currency
    |--------------------------------------------------------------------------
    |
    | The default currency for payments and subscriptions. This should match
    | your NOWPayments payout wallet currency.
    |
    */

    'currency' => env('CASHIER_NOWPAYMENTS_CURRENCY', 'usd'),

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    |
    | Your NOWPayments API key and IPN secret key for authentication and
    | webhook signature verification. The IPN secret is used to verify
    | that incoming webhooks are genuinely from NOWPayments.
    |
    */

    'api_key' => env('NOWPAYMENTS_API_KEY'),

    'ipn_secret' => env('NOWPAYMENTS_IPN_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    |
    | You can override the default models with your own custom models that
    | extend the Cashier NOWPayments models.
    |
    */

    'model' => [
        'customer' => \SerenityTechnologies\CashierNowPayments\Models\Customer::class,
        'subscription' => \SerenityTechnologies\CashierNowPayments\Models\Subscription::class,
        'subscription_item' => \SerenityTechnologies\CashierNowPayments\Models\SubscriptionItem::class,
        'payment' => \SerenityTechnologies\CashierNowPayments\Models\Payment::class,
        'invoice' => \SerenityTechnologies\CashierNowPayments\Models\Invoice::class,
        'payout' => \SerenityTechnologies\CashierNowPayments\Models\Payout::class,
        'payout_withdrawal' => \SerenityTechnologies\CashierNowPayments\Models\PayoutWithdrawal::class,
        'credit' => \SerenityTechnologies\CashierNowPayments\Models\Credit::class,
        'plan' => \SerenityTechnologies\CashierNowPayments\Models\Plan::class,
        'webhook_log' => \SerenityTechnologies\CashierNowPayments\Models\WebhookLog::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the webhook path and timestamp tolerance for IPN callbacks.
    |
    */

    'webhook' => [
        'path' => env('CASHIER_NOWPAYMENTS_WEBHOOK_PATH', '/nowpayments/webhook'),
        'tolerance' => env('CASHIER_NOWPAYMENTS_WEBHOOK_TOLERANCE', 300),

        /*
        |------------------------------------------------------------------
        | Webhook Logging
        |------------------------------------------------------------------
        |
        | When enabled, all incoming webhooks are persisted to the database
        | for audit and debugging. Includes signature validation status,
        | payload, IP address, and processing outcome.
        */
        'log_enabled' => env('CASHIER_NOWPAYMENTS_WEBHOOK_LOG', true),

        /*
        |------------------------------------------------------------------
        | Log Retention
        |------------------------------------------------------------------
        |
        | Number of days to retain webhook logs. Set to 0 to keep indefinitely.
        | Use `php artisan cashier-nowpayments:prune-webhook-logs` to clean up.
        */
        'log_retention_days' => env('CASHIER_NOWPAYMENTS_WEBHOOK_LOG_RETENTION', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the route prefix, name prefix, and middleware stack for
    | the checkout and payment routes.
    |
    */

    'routes' => [
        'prefix' => env('CASHIER_NOWPAYMENTS_ROUTE_PREFIX', 'cashier-nowpayments'),
        'name' => env('CASHIER_NOWPAYMENTS_ROUTE_NAME', 'cashier-nowpayments.'),
        'middleware' => explode(',', env('CASHIER_NOWPAYMENTS_ROUTE_MIDDLEWARE', 'web')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Method
    |--------------------------------------------------------------------------
    |
    | The default payment method: 'payment' for direct payments or 'invoice'
    | for hosted invoice pages.
    |
    */

    'payment_method' => env('CASHIER_NOWPAYMENTS_PAYMENT_METHOD', 'payment'),

    /*
    |--------------------------------------------------------------------------
    | Fixed Rate
    |--------------------------------------------------------------------------
    |
    | When enabled, the exchange rate is frozen for 20 minutes after
    | payment creation.
    |
    */

    'fixed_rate' => env('CASHIER_NOWPAYMENTS_FIXED_RATE', false),

    /*
    |--------------------------------------------------------------------------
    | Fee Paid By User
    |--------------------------------------------------------------------------
    |
    | When true, the network fee is paid by the user instead of the merchant.
    |
    */

    'fee_paid_by_user' => env('CASHIER_NOWPAYMENTS_FEE_PAID_BY_USER', false),

    /*
    |--------------------------------------------------------------------------
    | Notifications
    |--------------------------------------------------------------------------
    |
    | Control which notifications are enabled for the billable model.
    |
    */

    'notifications' => [
        'payment_received' => env('CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_RECEIVED', true),
        'payment_failed' => env('CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_FAILED', true),
        'subscription_activated' => env('CASHIER_NOWPAYMENTS_NOTIFY_SUBSCRIPTION_ACTIVATED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Credentials (Optional)
    |--------------------------------------------------------------------------
    |
    | Email and password for dashboard authentication.
    | Required for certain endpoints like payouts and conversions.
    | JWT tokens obtained with these credentials expire in 5 minutes.
    |
    */

    'dashboard_email' => env('NOWPAYMENTS_DASHBOARD_EMAIL', ''),
    'dashboard_password' => env('NOWPAYMENTS_DASHBOARD_PASSWORD', ''),

    /*
    |--------------------------------------------------------------------------
    | Payment Status Authorization
    |--------------------------------------------------------------------------
    |
    | Control whether payment status endpoints require authentication and
    | ownership verification. When enabled, only authenticated users can
    | check the status of their own payments.
    |
    | Supported guards: 'web', 'api', 'sanctum', or any custom guard name.
    |
    */

    'payment_status' => [
        'auth' => [
            'enabled' => env('CASHIER_NOWPAYMENTS_PAYMENT_STATUS_AUTH', true),
            'guard' => env('CASHIER_NOWPAYMENTS_PAYMENT_STATUS_GUARD', 'web'),
        ],

        /*
        |------------------------------------------------------------------
        | Status Polling Cache
        |------------------------------------------------------------------
        |
        | Cache duration (seconds) for remote payment status polling.
        | Prevents excessive API calls to NOWPayments when the frontend
        | polls every few seconds. The local status endpoint uses a
        | separate sync cooldown (see sync_cooldown_seconds).
        */
        'cache_seconds' => env('CASHIER_NOWPAYMENTS_STATUS_CACHE_SECONDS', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Checkout Configuration
    |--------------------------------------------------------------------------
    */

    'checkout' => [
        /*
        |------------------------------------------------------------------
        | Payment Timeout
        |------------------------------------------------------------------
        |
        | How long (in seconds) a pending payment remains valid before the
        | frontend displays a timeout. This is communicated to the browser
        | so the countdown timer matches the server's expectation.
        | NOWPayments typically holds payment addresses for 15-20 minutes.
        */
        'payment_timeout_seconds' => env('CASHIER_NOWPAYMENTS_PAYMENT_TIMEOUT', 900),

        /*
        |------------------------------------------------------------------
        | Status Sync Cooldown
        |------------------------------------------------------------------
        |
        | Minimum seconds between NOWPayments API sync calls for a single
        | pending payment. Prevents excessive API usage when multiple
        | clients poll the local status endpoint simultaneously.
        */
        'sync_cooldown_seconds' => env('CASHIER_NOWPAYMENTS_SYNC_COOLDOWN', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | Proration Configuration
    |--------------------------------------------------------------------------
    |
    | Control how plan swaps handle billing differences and credits.
    |
    */

    'proration' => [
        /*
        |------------------------------------------------------------------
        | Proration Mode
        |------------------------------------------------------------------
        |
        | Controls how plan swaps handle billing differences:
        |
        | 'credit' - Issue prorated credit for unused time (default)
        | 'immediate' - Charge/credit the difference immediately
        | 'end_of_period' - No proration, charge full amount at renewal
        | 'none' - No proration, no credits issued
        |
        */
        'mode' => env('CASHIER_NOWPAYMENTS_PRORATION_MODE', 'credit'),

        /*
        |------------------------------------------------------------------
        | Default Billing Interval
        |------------------------------------------------------------------
        |
        | Fallback interval in days when the plan's interval_days is not
        | available. Used for proration calculations when the plan
        | relationship is not loaded.
        |
        */
        'default_interval_days' => env('CASHIER_NOWPAYMENTS_PRORATION_INTERVAL', 30),

        /*
        |------------------------------------------------------------------
        | Credit Expiration
        |------------------------------------------------------------------
        |
        | Whether prorated credits should expire at the end of the current
        | billing cycle. Set to false for credits that never expire.
        |
        */
        'credits_expire' => env('CASHIER_NOWPAYMENTS_CREDITS_EXPIRE', true),
    ],
];

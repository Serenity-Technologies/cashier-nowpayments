# Events & Notifications

The Laravel Cashier NOWPayments package dispatches events and sends notifications throughout the payment lifecycle. This guide covers every event, how to listen to them, and how to configure notifications for your users.

---

## Table of Contents

1. [Events Overview](#1-events-overview)
2. [Base Event Class](#2-base-event-class)
3. [Listening to Events](#3-listening-to-events)
4. [Notification Classes](#4-notification-classes)
5. [Configuring Notifications](#5-configuring-notifications)
6. [Event Payloads](#6-event-payloads)
7. [Common Patterns](#7-common-patterns)

---

## 1. Events Overview

The package dispatches events across five domains: payments, invoices, subscriptions, payouts, and credits. All events live in the `SerenityTechnologies\CashierNowPayments\Events` namespace.

### Payment Events

| Event | When it fires |
|---|---|
| `PaymentCreated` | A payment is initiated via the NOWPayments API. |
| `PaymentReceived` | A payment has been confirmed and received. |
| `PaymentFailed` | A payment has failed or been rejected. |
| `PaymentStatusSynced` | A payment's status has been synced with the NOWPayments API. |
| `PaymentRefunded` | A payment has been refunded. |

### Invoice Events

| Event | When it fires |
|---|---|
| `InvoiceCreated` | An invoice has been created via the NOWPayments API. |
| `InvoicePaid` | An invoice payment has been confirmed. |
| `InvoicePaymentFailed` | An invoice payment has failed. |

### Subscription Events

| Event | When it fires |
|---|---|
| `SubscriptionCreated` | A new subscription has been created. |
| `SubscriptionUpdated` | An existing subscription has been modified. |
| `SubscriptionCancelled` | A subscription has been cancelled by the user or system. |
| `SubscriptionExpired` | A subscription has reached its end date and expired. |
| `SubscriptionRenewed` | A subscription has been automatically renewed. |

### Payout Events

| Event | When it fires |
|---|---|
| `PayoutCreated` | A payout (mass or single payout) has been created. |
| `PayoutStatusUpdated` | A payout's status has been updated via webhook or sync. |

### Credit Events

| Event | When it fires |
|---|---|
| `CreditExpired` | One or more credits have expired. |

---

## 2. Base Event Class

All events extend `CashierNowPaymentsEvent`, which provides a consistent structure:

```php
namespace SerenityTechnologies\CashierNowPayments\Events;

abstract class CashierNowPaymentsEvent
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly object $model,
        public readonly array $nowpaymentsPayload = [],
    ) {}
}
```

### Key properties

- **`$model`** — The primary object associated with the event. This can be an Eloquent model (`Payment`, `Subscription`, `Invoice`, `Payout`) or, in some cases, a NOWPayments API DTO (`PaymentResponse`, `InvoiceResponse`, `SubscriptionResponse`, `PayoutResponse`). For `CreditExpired`, `$model` is an `Illuminate\Support\Collection` of credit models.
- **`$nowpaymentsPayload`** — The raw payload received from the NOWPayments webhook or API response. Useful when you need access to fields not mapped onto the Eloquent model.

### Dispatchable trait

Every event uses Laravel's `Dispatchable` trait, so you can dispatch them manually if needed:

```php
use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;

PaymentReceived::dispatch($payment, $rawPayload);
```

---

## 3. Listening to Events

There are three ways to listen to events dispatched by the package.

### Method 1: Via `EventServiceProvider`

Register listeners in your `app/Providers/EventServiceProvider.php`:

```php
namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;
use SerenityTechnologies\CashierNowPayments\Events\PaymentFailed;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCreated;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCancelled;
use App\Listeners\SendPaymentConfirmationEmail;
use App\Listeners\LogFailedPayment;
use App\Listeners\ActivateUserSubscription;
use App\Listeners\NotifyAdminOfCancellation;

class EventServiceProvider extends ServiceProvider
{
    protected $listen = [
        PaymentReceived::class => [
            SendPaymentConfirmationEmail::class,
        ],
        PaymentFailed::class => [
            LogFailedPayment::class,
        ],
        SubscriptionCreated::class => [
            ActivateUserSubscription::class,
        ],
        SubscriptionCancelled::class => [
            NotifyAdminOfCancellation::class,
        ],
    ];

    public function boot(): void
    {
        //
    }
}
```

Then create the listener classes using Artisan:

```bash
php artisan make:listener SendPaymentConfirmationEmail --event=PaymentReceived
```

Each listener receives the event instance via its `handle()` method:

```php
namespace App\Listeners;

use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;
use Illuminate\Support\Facades\Mail;

class SendPaymentConfirmationEmail
{
    public function handle(PaymentReceived $event): void
    {
        $payment = $event->payment;

        Mail::to($payment->billable->email)->send(
            new \App\Mail\PaymentConfirmation($payment)
        );
    }
}
```

### Method 2: Via Closures in `boot()`

For quick, inline handling, register closure listeners in a service provider:

```php
namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use SerenityTechnologies\CashierNowPayments\Events\PaymentStatusSynced;
use SerenityTechnologies\CashierNowPayments\Events\CreditExpired;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(PaymentStatusSynced::class, function (PaymentStatusSynced $event) {
            logger()->info('Payment status synced', [
                'payment_id' => $event->payment->id,
                'status' => $event->payment->status,
            ]);
        });

        Event::listen(CreditExpired::class, function (CreditExpired $event) {
            logger()->warning('Credits expired', [
                'count' => $event->count,
                'total_amount' => $event->totalAmount,
            ]);
        });
    }
}
```

### Method 3: Via Subscriber Classes

Event subscribers let you group related listeners into a single class:

```php
namespace App\Listeners;

use SerenityTechnologies\CashierNowPayments\Events\PaymentCreated;
use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;
use SerenityTechnologies\CashierNowPayments\Events\PaymentFailed;

class PaymentEventSubscriber
{
    public function handlePaymentCreated(PaymentCreated $event): void
    {
        // Log the payment attempt
        logger()->info('Payment created', [
            'billable_id' => $event->billable->id,
            'payment_id' => $event->paymentResponse->payment_id,
        ]);
    }

    public function handlePaymentReceived(PaymentReceived $event): void
    {
        // Fulfill the order
        $event->payment->billable->grantAccess($event->payment);
    }

    public function handlePaymentFailed(PaymentFailed $event): void
    {
        // Notify support
        logger()->error('Payment failed', [
            'payment_id' => $event->payment->id,
            'status' => $event->payment->status,
        ]);
    }

    public function subscribe($events): array
    {
        return [
            PaymentCreated::class  => 'handlePaymentCreated',
            PaymentReceived::class => 'handlePaymentReceived',
            PaymentFailed::class   => 'handlePaymentFailed',
        ];
    }
}
```

Register the subscriber in `EventServiceProvider`:

```php
protected $subscribe = [
    \App\Listeners\PaymentEventSubscriber::class,
    \App\Listeners\SubscriptionEventSubscriber::class,
];
```

---

## 4. Notification Classes

The package includes three Laravel Notification classes that are automatically sent to the billable model when certain events fire.

### `PaymentReceivedNotification`

Sent when the `PaymentReceived` event fires. The email includes the payment amount, currency, payment ID, and status.

```php
// src/Notifications/PaymentReceivedNotification.php
```

**Mail content:**
- Subject: "Payment Received"
- Amount and currency
- NOWPayments payment ID
- Status
- Link to payment details page

### `PaymentFailedNotification`

Sent when the `PaymentFailed` event fires. The email includes failure details and a retry link.

```php
// src/Notifications/PaymentFailedNotification.php
```

**Mail content:**
- Subject: "Payment Failed"
- Amount and currency
- NOWPayments payment ID
- Status
- Link to retry payment

### `SubscriptionActivatedNotification`

Sent when a subscription becomes active. The email includes plan details.

```php
// src/Notifications/SubscriptionActivatedNotification.php
```

**Mail content:**
- Subject: "Subscription Activated"
- Plan ID
- Subscription type
- Total price and currency
- Quantity
- Link to manage subscription

### How notifications are dispatched

Notifications are sent to the billable model (typically your `User` model) via the `Notifiable` trait. The billable model must use `Illuminate\Notifications\Notifiable`:

```php
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SerenityTechnologies\CashierNowPayments\Concerns\BillableNowPayments;

class User extends Authenticatable
{
    use Notifiable, BillableNowPayments;
}
```

---

## 5. Configuring Notifications

### Environment Variables

Control which notifications are enabled via your `.env` file:

```env
# Enable/disable payment received notifications (default: true)
CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_RECEIVED=true

# Enable/disable payment failed notifications (default: true)
CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_FAILED=true

# Enable/disable subscription activated notifications (default: true)
CASHIER_NOWPAYMENTS_NOTIFY_SUBSCRIPTION_ACTIVATED=true
```

These map to the `notifications` section of `config/cashier-nowpayments.php`:

```php
'notifications' => [
    'payment_received' => env('CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_RECEIVED', true),
    'payment_failed' => env('CASHIER_NOWPAYMENTS_NOTIFY_PAYMENT_FAILED', true),
    'subscription_activated' => env('CASHIER_NOWPAYMENTS_NOTIFY_SUBSCRIPTION_ACTIVATED', true),
],
```

Setting any of these to `false` disables the corresponding notification entirely — the `via()` method on the notification class will return an empty array.

### Adding Additional Channels

To send notifications through additional channels (database, Slack, SMS, etc.), you have two options:

**Option A: Override the notification in your billable model**

```php
use App\Notifications\CustomPaymentReceivedNotification;
use SerenityTechnologies\CashierNowPayments\Notifications\PaymentReceivedNotification;

class User extends Authenticatable
{
    use Notifiable, BillableNowPayments;

    public function routeNotificationForSlack($notification): string
    {
        return config('services.slack.webhook_url');
    }

    // Route specific notifications through custom channels
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'user-notifications.' . $this->id;
    }
}
```

**Option B: Replace the notification entirely via event listeners**

```php
use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;
use Illuminate\Support\Facades\Notification;
use App\Notifications\CustomPaymentReceivedNotification;

Event::listen(PaymentReceived::class, function (PaymentReceived $event) {
    Notification::send($event->payment->billable, new CustomPaymentReceivedNotification($event->payment));
});
```

Then your custom notification can use any channels you want:

```php
namespace App\Notifications;

use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use SerenityTechnologies\CashierNowPayments\Models\Payment;

class CustomPaymentReceivedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly Payment $payment) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'slack'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Payment Confirmed')
            ->line('We received your payment of ' . $this->payment->amount . ' ' . strtoupper($this->payment->currency));
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return (new SlackMessage)
            ->success()
            ->content('New payment received: ' . $this->payment->amount . ' ' . strtoupper($this->payment->currency));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'payment_id' => $this->payment->id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency,
        ];
    }
}
```

---

## 6. Event Payloads

### Payment Events

| Event | `$model` type | Additional properties |
|---|---|---|
| `PaymentCreated` | `PaymentResponse` (DTO) | `$billable`, `$customer`, `$paymentResponse` |
| `PaymentReceived` | `Payment` (Eloquent) | `$payment` |
| `PaymentFailed` | `Payment` (Eloquent) | `$payment` |
| `PaymentStatusSynced` | `Payment` (Eloquent) | `$payment`, `$apiResponse` (PaymentResponse DTO) |
| `PaymentRefunded` | `Payment` (Eloquent) | `$payment`, `$refundAmount` (?float) |

**`PaymentCreated`** fires before the payment is persisted, so `$model` is the raw `PaymentResponse` DTO from the API. All other payment events fire after persistence and carry the `Payment` Eloquent model.

```php
// Example: Accessing raw NOWPayments data from PaymentReceived
Event::listen(PaymentReceived::class, function (PaymentReceived $event) {
    // Eloquent model
    $paymentId = $event->payment->id;
    $status = $event->payment->status;

    // Raw webhook payload
    $rawPayload = $event->nowpaymentsPayload;
    $orderId = $rawPayload['order_id'] ?? null;
});
```

### Invoice Events

| Event | `$model` type | Additional properties |
|---|---|---|
| `InvoiceCreated` | `InvoiceResponse` (DTO) | `$billable`, `$customer`, `$invoiceResponse` |
| `InvoicePaid` | `Invoice` (Eloquent) | `$invoice` |
| `InvoicePaymentFailed` | `Invoice` (Eloquent) | `$invoice` |

### Subscription Events

| Event | `$model` type | Additional properties |
|---|---|---|
| `SubscriptionCreated` | `Subscription` (Eloquent) | `$billable`, `$customer`, `$subscription`, `$subscriptionResponse` |
| `SubscriptionUpdated` | `Subscription` (Eloquent) | `$subscription` |
| `SubscriptionCancelled` | `Subscription` (Eloquent) | `$subscription` |
| `SubscriptionExpired` | `Subscription` (Eloquent) | `$subscription` |
| `SubscriptionRenewed` | `Subscription` (Eloquent) | `$subscription` |

```php
// Example: Accessing subscription details
Event::listen(SubscriptionCreated::class, function (SubscriptionCreated $event) {
    $subscription = $event->subscription;
    $planId = $subscription->nowpayments_plan_id;
    $type = $subscription->type;
    $totalPrice = $subscription->total_price;
    $currency = $subscription->currency;
    $quantity = $subscription->quantity;

    // The billable model is also available
    $user = $event->billable;
});
```

### Payout Events

| Event | `$model` type | Additional properties |
|---|---|---|
| `PayoutCreated` | `PayoutResponse` (DTO) | `$billable`, `$customer`, `$payoutResponse` |
| `PayoutStatusUpdated` | `Payout` (Eloquent) | `$payout` |

### Credit Events

| Event | `$model` type | Additional properties |
|---|---|---|
| `CreditExpired` | `Collection` of Credit models | `$credits`, `$count`, `$totalAmount` |

```php
// Example: Handling credit expiration
Event::listen(CreditExpired::class, function (CreditExpired $event) {
    // Collection of expired credit models
    foreach ($event->credits as $credit) {
        logger()->info('Credit expired', [
            'credit_id' => $credit->id,
            'amount' => $credit->amount,
        ]);
    }

    // Summary data
    $count = $event->count;         // int
    $totalAmount = $event->totalAmount; // string
});
```

---

## 7. Common Patterns

### Send Email on Payment Received

```php
// app/Listeners/SendPaymentReceipt.php
namespace App\Listeners;

use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;
use Illuminate\Support\Facades\Mail;
use App\Mail\PaymentReceipt;

class SendPaymentReceipt
{
    public function handle(PaymentReceived $event): void
    {
        Mail::to($event->payment->billable->email)
            ->send(new PaymentReceipt($event->payment));
    }
}
```

### Update Order Status on Payment Finished

```php
// app/Listeners/UpdateOrderStatus.php
namespace App\Listeners;

use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;
use App\Models\Order;

class UpdateOrderStatus
{
    public function handle(PaymentReceived $event): void
    {
        $order = Order::where('payment_uuid', $event->payment->payment_uuid)->first();

        if ($order) {
            $order->update(['status' => 'paid']);
            $order->fulfill();
        }
    }
}

// Also handle failures:
use SerenityTechnologies\CashierNowPayments\Events\PaymentFailed;

class MarkOrderFailed
{
    public function handle(PaymentFailed $event): void
    {
        $order = Order::where('payment_uuid', $event->payment->payment_uuid)->first();

        if ($order) {
            $order->update(['status' => 'payment_failed']);
        }
    }
}
```

### Log Failed Payments for Monitoring

```php
// In a service provider boot() method
use SerenityTechnologies\CashierNowPayments\Events\PaymentFailed;
use Illuminate\Support\Facades\Event;

Event::listen(PaymentFailed::class, function (PaymentFailed $event) {
    logger()->channel('payments')
        ->error('Payment failed', [
            'payment_id' => $event->payment->id,
            'nowpayments_id' => $event->payment->nowpayments_payment_id,
            'amount' => $event->payment->amount,
            'currency' => $event->payment->currency,
            'status' => $event->payment->status,
            'user_id' => $event->payment->billable->id ?? null,
            'raw_payload' => $event->nowpaymentsPayload,
        ]);
});
```

### Notify Admin on Subscription Cancellation

```php
// app/Listeners/NotifyAdminOfSubscriptionCancellation.php
namespace App\Listeners;

use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCancelled;
use Illuminate\Support\Facades\Notification;
use App\Notifications\Admin\SubscriptionCancelledAlert;

class NotifyAdminOfSubscriptionCancellation
{
    public function handle(SubscriptionCancelled $event): void
    {
        $admins = \App\Models\User::where('is_admin', true)->get();

        Notification::send($admins, new SubscriptionCancelledAlert(
            $event->subscription,
            $event->subscription->billable
        ));
    }
}
```

### Dispatch Job on Credit Expiration

```php
// app/Listeners/ProcessExpiredCredits.php
namespace App\Listeners;

use SerenityTechnologies\CashierNowPayments\Events\CreditExpired;
use App\Jobs\ProcessExpiredCreditsJob;

class ProcessExpiredCredits
{
    public function handle(CreditExpired $event): void
    {
        ProcessExpiredCreditsJob::dispatch(
            $event->credits,
            $event->count,
            $event->totalAmount
        );
    }
}
```

```php
// app/Jobs/ProcessExpiredCreditsJob.php
namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class ProcessExpiredCreditsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Collection $credits,
        public readonly int $count,
        public readonly string $totalAmount,
    ) {}

    public function handle(): void
    {
        foreach ($this->credits as $credit) {
            // Process each expired credit
            $credit->user()->notify(new CreditExpiredNotice($credit));
        }
    }
}
```

### Slack Notification on Large Payouts

```php
// In a service provider boot() method
use SerenityTechnologies\CashierNowPayments\Events\PayoutCreated;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Support\Facades\Notification;

Event::listen(PayoutCreated::class, function (PayoutCreated $event) {
    $payoutAmount = $event->payoutResponse->amount ?? 0;
    $threshold = 1000; // USD

    if ($payoutAmount >= $threshold) {
        Notification::route('slack', config('services.slack.payments_webhook'))
            ->notify(new class($event->payoutResponse, $payoutAmount) extends \Illuminate\Notifications\Notification {
                public function __construct(
                    private readonly object $payoutResponse,
                    private readonly float $amount
                ) {}

                public function via(object $notifiable): array
                {
                    return ['slack'];
                }

                public function toSlack(object $notifiable): SlackMessage
                {
                    return (new SlackMessage)
                        ->error()
                        ->content('Large payout detected')
                        ->attachment(function ($attachment) {
                            $attachment
                                ->title('Payout #' . ($this->payoutResponse->id ?? 'N/A'))
                                ->fields([
                                    'Amount' => '$' . number_format($this->amount, 2),
                                    'Status' => $this->payoutResponse->payout_status ?? 'unknown',
                                ]);
                        });
                }
            });
    }
});
```

---

## Quick Reference: Import Paths

All event and notification classes use the following namespaces:

```php
// Events
use SerenityTechnologies\CashierNowPayments\Events\CashierNowPaymentsEvent;
use SerenityTechnologies\CashierNowPayments\Events\PaymentCreated;
use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;
use SerenityTechnologies\CashierNowPayments\Events\PaymentFailed;
use SerenityTechnologies\CashierNowPayments\Events\PaymentStatusSynced;
use SerenityTechnologies\CashierNowPayments\Events\PaymentRefunded;
use SerenityTechnologies\CashierNowPayments\Events\InvoiceCreated;
use SerenityTechnologies\CashierNowPayments\Events\InvoicePaid;
use SerenityTechnologies\CashierNowPayments\Events\InvoicePaymentFailed;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCreated;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionUpdated;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCancelled;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionExpired;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionRenewed;
use SerenityTechnologies\CashierNowPayments\Events\PayoutCreated;
use SerenityTechnologies\CashierNowPayments\Events\PayoutStatusUpdated;
use SerenityTechnologies\CashierNowPayments\Events\CreditExpired;

// Notifications
use SerenityTechnologies\CashierNowPayments\Notifications\PaymentReceivedNotification;
use SerenityTechnologies\CashierNowPayments\Notifications\PaymentFailedNotification;
use SerenityTechnologies\CashierNowPayments\Notifications\SubscriptionActivatedNotification;
```

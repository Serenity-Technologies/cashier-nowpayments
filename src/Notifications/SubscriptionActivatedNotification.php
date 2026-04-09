<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SerenityTechnologies\CashierNowPayments\Models\Subscription;

class SubscriptionActivatedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly Subscription $subscription,
    ) {
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        $channels = [];

        if (config('cashier-nowpayments.notifications.subscription_activated', true)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('Subscription Activated')
            ->greeting('Hello!')
            ->line('Your subscription has been activated.')
            ->line('Plan ID: ' . $this->subscription->nowpayments_plan_id)
            ->line('Type: ' . $this->subscription->type)
            ->line('Amount: ' . $this->subscription->total_price . ' ' . strtoupper($this->subscription->currency))
            ->line('Quantity: ' . $this->subscription->quantity)
            ->action('Manage Subscription', url('/subscriptions/' . $this->subscription->id))
            ->line('Thank you for subscribing!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'subscription_id' => $this->subscription->id,
            'nowpayments_subscription_id' => $this->subscription->nowpayments_subscription_id,
            'plan_id' => $this->subscription->nowpayments_plan_id,
            'type' => $this->subscription->type,
            'total_price' => $this->subscription->total_price,
            'currency' => $this->subscription->currency,
            'quantity' => $this->subscription->quantity,
            'status' => $this->subscription->status,
        ];
    }
}

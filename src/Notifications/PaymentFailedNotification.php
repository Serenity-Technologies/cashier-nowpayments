<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SerenityTechnologies\CashierNowPayments\Models\Payment;

class PaymentFailedNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public readonly Payment $payment,
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

        if (config('cashier-nowpayments.notifications.payment_failed', true)) {
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
            ->subject('Payment Failed')
            ->greeting('Hello!')
            ->line('Your payment could not be processed.')
            ->line('Amount: ' . $this->payment->amount . ' ' . strtoupper($this->payment->currency))
            ->line('Payment ID: ' . $this->payment->nowpayments_payment_id)
            ->line('Status: ' . $this->payment->status)
            ->action('Retry Payment', url('/payments/' . $this->payment->id))
            ->line('Please contact support if you need assistance.');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'payment_id' => $this->payment->id,
            'nowpayments_payment_id' => $this->payment->nowpayments_payment_id,
            'amount' => $this->payment->amount,
            'currency' => $this->payment->currency,
            'status' => $this->payment->status,
        ];
    }
}

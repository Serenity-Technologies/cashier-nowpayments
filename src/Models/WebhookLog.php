<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class WebhookLog extends Model
{
    use HasFactory, HasUlids;

    /**
     * Get the table name for the model.
     */
    public function getTable(): string
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return "{$prefix}webhook_logs";
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
        'signature_valid' => 'boolean',
        'processed' => 'boolean',
    ];

    /**
     * Scope a query to only include successful webhook deliveries.
     */
    public function scopeSuccessful($query): void
    {
        $query->where('processed', true)
            ->whereNull('processing_error');
    }

    /**
     * Scope a query to only include failed webhook deliveries.
     */
    public function scopeFailed($query): void
    {
        $query->where('processed', false)
            ->orWhereNotNull('processing_error');
    }

    /**
     * Scope a query to only include a specific event type.
     */
    public function scopeEventType($query, string $type): void
    {
        $query->where('event_type', $type);
    }

    /**
     * Scope a query to only include webhooks for a specific payment.
     */
    public function scopeForPayment($query, string $paymentId): void
    {
        $query->where('payment_id', $paymentId);
    }

    /**
     * Scope a query to only include webhooks for a specific invoice.
     */
    public function scopeForInvoice($query, string $invoiceId): void
    {
        $query->where('invoice_id', $invoiceId);
    }

    /**
     * Scope a query to only include webhooks for a specific subscription.
     */
    public function scopeForSubscription($query, string $subscriptionId): void
    {
        $query->where('subscription_id', $subscriptionId);
    }

    /**
     * Determine if the webhook was processed successfully.
     */
    public function isSuccessful(): bool
    {
        return $this->processed && $this->processing_error === null;
    }

    /**
     * Determine if the webhook signature was valid.
     */
    public function hasValidSignature(): bool
    {
        return $this->signature_valid;
    }

    /**
     * Get the event type label.
     */
    public function getEventTypeLabel(): string
    {
        return match ($this->event_type) {
            'payment' => 'Payment',
            'invoice' => 'Invoice',
            'subscription' => 'Subscription',
            'payout' => 'Payout',
            'redeposit' => 'Re-deposit',
            default => 'Unknown',
        };
    }

    /**
     * Mark the webhook log as processed successfully.
     */
    public function markAsProcessed(): self
    {
        $this->update([
            'processed' => true,
            'processing_error' => null,
        ]);

        return $this;
    }

    /**
     * Mark the webhook log as failed with an error message.
     */
    public function markAsFailed(string $error): self
    {
        $this->update([
            'processed' => false,
            'processing_error' => $error,
        ]);

        return $this;
    }
}

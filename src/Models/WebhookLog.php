<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Represents a logged webhook event from NOWPayments IPN callbacks.
 *
 * Each webhook delivery is recorded with its full payload, signature
 * verification status, and processing result for auditing and
 * debugging purposes.
 *
 * @property string $id The ULID primary key
 * @property string|null $payload_id The unique payload identifier from NOWPayments
 * @property string $event_type The webhook event type (e.g., payment, invoice, subscription, payout, redeposit)
 * @property string|null $payment_id The associated NOWPayments payment ID
 * @property string|null $invoice_id The associated NOWPayments invoice ID
 * @property string|null $subscription_id The associated NOWPayments subscription ID
 * @property string|null $payout_id The associated NOWPayments payout ID
 * @property string|null $payment_status The payment status from the webhook payload
 * @property string|null $signature The webhook signature
 * @property bool|null $signature_valid Whether the signature verification passed
 * @property bool|null $processed Whether the webhook was successfully processed
 * @property string|null $processing_error Error message if processing failed
 * @property array|null $payload The full webhook payload
 * @property string|null $ip_address The IP address of the webhook sender
 * @property string|null $user_agent The user agent of the webhook sender
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<self>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereId(string $id)
 * @method static \Illuminate\Database\Eloquent\Builder<self> wherePayloadId(string $payloadId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereEventType(string $eventType)
 * @method static \Illuminate\Database\Eloquent\Builder<self> wherePaymentId(string $paymentId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereInvoiceId(string $invoiceId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereSubscriptionId(string $subscriptionId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> successful()
 * @method static \Illuminate\Database\Eloquent\Builder<self> failed()
 * @method static \Illuminate\Database\Eloquent\Builder<self> eventType(string $type)
 * @method static \Illuminate\Database\Eloquent\Builder<self> forPayment(string $paymentId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> forInvoice(string $invoiceId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> forSubscription(string $subscriptionId)
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class WebhookLog extends Model
{
    use HasFactory, HasUlids;

    /**
     * Get the table name for the model.
     *
     * @return string The fully qualified table name with configured prefix
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
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeSuccessful($query): void
    {
        $query->where('processed', true)
            ->whereNull('processing_error');
    }

    /**
     * Scope a query to only include failed webhook deliveries.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeFailed($query): void
    {
        $query->where('processed', false)
            ->orWhereNotNull('processing_error');
    }

    /**
     * Scope a query to only include a specific event type.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @param string $type The event type to filter by
     * @return void
     */
    public function scopeEventType($query, string $type): void
    {
        $query->where('event_type', $type);
    }

    /**
     * Scope a query to only include webhooks for a specific payment.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @param string $paymentId The NOWPayments payment ID
     * @return void
     */
    public function scopeForPayment($query, string $paymentId): void
    {
        $query->where('payment_id', $paymentId);
    }

    /**
     * Scope a query to only include webhooks for a specific invoice.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @param string $invoiceId The NOWPayments invoice ID
     * @return void
     */
    public function scopeForInvoice($query, string $invoiceId): void
    {
        $query->where('invoice_id', $invoiceId);
    }

    /**
     * Scope a query to only include webhooks for a specific subscription.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @param string $subscriptionId The NOWPayments subscription ID
     * @return void
     */
    public function scopeForSubscription($query, string $subscriptionId): void
    {
        $query->where('subscription_id', $subscriptionId);
    }

    /**
     * Determine if the webhook was processed successfully.
     *
     * @return bool True if processed flag is set and no processing error
     */
    public function isSuccessful(): bool
    {
        return $this->processed && $this->processing_error === null;
    }

    /**
     * Determine if the webhook signature was valid.
     *
     * @return bool True if signature verification passed
     */
    public function hasValidSignature(): bool
    {
        return $this->signature_valid;
    }

    /**
     * Get the event type label.
     *
     * @return string The human-readable event type label
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
     *
     * @return $this
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
     *
     * @param string $error The error message describing the failure
     * @return $this
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

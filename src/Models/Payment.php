<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SerenityTechnologies\CashierNowPayments\Events\PaymentRefunded;
use SerenityTechnologies\CashierNowPayments\Events\PaymentStatusSynced;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class Payment extends Model
{
    use HasFactory, HasUlids;


    /**
     * Get the table name for the model.
     */
    public function getTable(): string
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return $prefix . 'payments';
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
        'fee' => 'array',
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'refunded_at' => 'datetime',
    ];

    /**
     * Get the customer that owns the payment.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo($this->getCustomerModel());
    }

    /**
     * Get the owning billable model.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the subscription that owns the payment.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo($this->getSubscriptionModel());
    }

    /**
     * Scope a query to only include successful payments.
     */
    public function scopeSuccessful($query): void
    {
        $query->where('status', 'finished');
    }

    /**
     * Scope a query to only include pending payments.
     */
    public function scopePending($query): void
    {
        $query->whereIn('status', ['waiting', 'confirming', 'confirmed', 'sending', 'partially_paid']);
    }

    /**
     * Scope a query to only include failed payments.
     */
    public function scopeFailed($query): void
    {
        $query->whereIn('status', ['failed', 'expired']);
    }

    /**
     * Scope a query to only include payments for a subscription.
     */
    public function scopeForSubscription($query, string $subscriptionId): void
    {
        $query->where('subscription_id', $subscriptionId);
    }

    /**
     * Determine if the payment is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'finished';
    }

    /**
     * Determine if the payment is pending.
     */
    public function isPending(): bool
    {
        return in_array($this->status, ['waiting', 'confirming', 'confirmed', 'sending', 'partially_paid'], true);
    }

    /**
     * Determine if the payment has failed.
     */
    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'expired'], true);
    }

    /**
     * Determine if the payment has been refunded.
     */
    public function isRefunded(): bool
    {
        return $this->status === 'refunded' || $this->refunded_at !== null;
    }

    /**
     * Sync the payment status with NOWPayments API.
     */
    public function syncStatus(): self
    {
        if ($this->nowpayments_payment_id === null) {
            return $this;
        }

        $response = NowPayments::getPaymentStatus((string) $this->nowpayments_payment_id);

        $this->update([
            'status' => $response->payment_status,
            'amount_paid' => $response->actually_paid,
            'payin_hash' => $response->payin_hash ?? $this->payin_hash,
            'payout_hash' => $response->payout_hash ?? $this->payout_hash,
            'paid_at' => $response->payment_status === 'finished' && $this->paid_at === null ? now() : $this->paid_at,
            'refunded_at' => $response->payment_status === 'refunded' && $this->refunded_at === null ? now() : $this->refunded_at,
        ]);

        PaymentStatusSynced::dispatch($this, $response);

        return $this;
    }

    /**
     * Refund the payment via NOWPayments API.
     *
     * Note: NOWPayments does not provide a direct refund endpoint.
     * Refunds must be initiated manually via the dashboard or by
     * contacting support. This method marks the payment as refunded
     * locally and dispatches an event for further processing.
     *
     * For automatic refunds, you must first contact NOWPayments support
     * or use the dashboard to initiate the refund, then call this method
     * to update the local record.
     *
     * @param string|null $reason Reason for the refund
     * @throws \InvalidArgumentException If payment is not in 'finished' status
     */
    public function refund(?string $reason = null): self
    {
        if (!$this->isSuccessful()) {
            throw new \InvalidArgumentException(
                'Only finished payments can be refunded. Current status: ' . $this->status
            );
        }

        if ($this->refunded_at !== null) {
            throw new \InvalidArgumentException('This payment has already been refunded.');
        }

        // Mark locally as refunded — the actual refund must be initiated
        // via the NOWPayments dashboard or by contacting support.
        $this->update([
            'status' => 'refunded',
            'refunded_at' => now(),
            'metadata' => array_merge($this->metadata ?? [], [
                'refund_reason' => $reason,
                'refund_initiated_at' => now()->toIso8601String(),
            ]),
        ]);

        PaymentRefunded::dispatch($this, $reason);

        return $this;
    }

    /**
     * Get the customer model class.
     */
    protected function getCustomerModel(): string
    {
        return config('cashier-nowpayments.model.customer', Customer::class);
    }

    /**
     * Get the subscription model class.
     */
    protected function getSubscriptionModel(): string
    {
        return config('cashier-nowpayments.model.subscription', Subscription::class);
    }
}

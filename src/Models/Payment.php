<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SerenityTechnologies\CashierNowPayments\Concerns\{BelongsToCustomer, HasNowPaymentsTable, HasStatusChecks};
use SerenityTechnologies\CashierNowPayments\Events\PaymentRefunded;
use SerenityTechnologies\CashierNowPayments\Events\PaymentStatusSynced;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

/**
 * Represents a payment processed through NOWPayments.
 *
 * Payments track the full lifecycle of a transaction including creation,
 * confirmation, completion, and refunds. They can be associated with a
 * subscription for recurring billing.
 *
 * @property string $id The ULID primary key
 * @property string $customer_id The owning customer's ULID
 * @property string $billable_type The owning billable model type
 * @property int|string $billable_id The owning billable model ID
 * @property string|null $subscription_id The associated subscription's ULID
 * @property string|null $nowpayments_payment_id The NOWPayments payment identifier
 * @property string|null $nowpayments_purchase_id The NOWPayments purchase identifier
 * @property string|null $parent_payment_id The parent payment's ULID for split payments
 * @property string|null $type The payment type
 * @property string $status The payment status (e.g., waiting, confirming, finished, refunded, failed)
 * @property string|null $currency The payment currency
 * @property string $amount The total payment amount
 * @property string $amount_paid The amount actually paid
 * @property string|null $pay_currency The cryptocurrency used for payment
 * @property string|null $pay_amount The amount in pay currency
 * @property string|null $pay_address The destination address for payment
 * @property string|null $order_id The order identifier
 * @property string|null $order_description Description of the order
 * @property string|null $payin_hash The payin transaction hash
 * @property string|null $payout_hash The payout transaction hash
 * @property array|null $fee Fee information
 * @property array|null $metadata Additional JSON metadata
 * @property \Carbon\Carbon|null $paid_at Timestamp when the payment was completed
 * @property \Carbon\Carbon|null $refunded_at Timestamp when the payment was refunded
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 *
 * @property-read Customer $customer
 * @property-read Subscription|null $subscription
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<self>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereId(string $id)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereCustomerId(string $customerId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereSubscriptionId(string $subscriptionId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNowPaymentsPaymentId(string $nowpaymentsPaymentId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereStatus(string $status)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereOrderId(string $orderId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> successful()
 * @method static \Illuminate\Database\Eloquent\Builder<self> pending()
 * @method static \Illuminate\Database\Eloquent\Builder<self> failed()
 * @method static \Illuminate\Database\Eloquent\Builder<self> forSubscription(string $subscriptionId)
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class Payment extends Model
{
    use HasFactory, HasUlids;
    use HasNowPaymentsTable;
    use HasStatusChecks;
    use BelongsToCustomer;

    /**
     * Table suffix for the config-based prefix.
     */
    protected string $nowPaymentsTable = 'payments';

    /**
     * Status values considered successful.
     */
    protected array $successfulStatuses = ['finished'];

    /**
     * Status values considered pending.
     */
    protected array $pendingStatuses = ['waiting', 'confirming', 'confirmed', 'sending', 'partially_paid'];

    /**
     * Status values considered failed.
     */
    protected array $failedStatuses = ['failed', 'expired'];


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
     * Get the owning billable model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the subscription that owns the payment.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo($this->getSubscriptionModel());
    }

    /**
     * Scope a query to only include payments for a subscription.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @param string $subscriptionId The subscription ULID to filter by
     * @return void
     */
    public function scopeForSubscription($query, string $subscriptionId): void
    {
        $query->where('subscription_id', $subscriptionId);
    }

    /**
     * Determine if the payment has been refunded.
     *
     * @return bool True if status is 'refunded' or refunded_at is set
     */
    public function isRefunded(): bool
    {
        return $this->status === 'refunded' || $this->refunded_at !== null;
    }

    /**
     * Sync the payment status with NOWPayments API.
     *
     * Fetches the latest payment status from NOWPayments and updates
     * the local record. Dispatches a PaymentStatusSynced event.
     *
     * @return $this
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
     * @return $this
     * @throws \InvalidArgumentException If payment is not in 'finished' status or already refunded
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
     *
     * @return class-string<Customer>
     */
    protected function getCustomerModel(): string
    {
        return config('cashier-nowpayments.model.customer', Customer::class);
    }

    /**
     * Get the subscription model class.
     *
     * @return class-string<Subscription>
     */
    protected function getSubscriptionModel(): string
    {
        return config('cashier-nowpayments.model.subscription', Subscription::class);
    }

    /**
     * Get the parent payment model class.
     *
     * @return class-string<Payment>
     */
    protected function getParentPaymentModel(): string
    {
        return config('cashier-nowpayments.model.payment', Payment::class);
    }

    public function parentPayment(): ?BelongsTo
    {
        return $this->belongsTo($this->getParentPaymentModel(), 'parent_payment_id');
    }
}

<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SerenityTechnologies\CashierNowPayments\Concerns\{BelongsToCustomer, HasNowPaymentsTable, HasStatusChecks};
use SerenityTechnologies\NowPayments\DTOs\Request\PayoutVerificationRequest;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

/**
 * Represents a payout (mass payout / withdrawal) processed through NOWPayments.
 *
 * Payouts are used to send funds from the NOWPayments wallet to external
 * addresses. They support batch withdrawals and 2FA verification.
 *
 * @property string $id The ULID primary key
 * @property string $customer_id The owning customer's ULID
 * @property string $billable_type The owning billable model type
 * @property int|string $billable_id The owning billable model ID
 * @property string|null $nowpayments_payout_id The NOWPayments payout identifier
 * @property string|null $batch_withdrawal_id The batch withdrawal identifier
 * @property string $status The payout status (e.g., creating, waiting, processing, finished, failed, rejected, cancelled)
 * @property string $currency The payout currency
 * @property string $amount The payout amount
 * @property string|null $address The destination wallet address
 * @property string|null $extra_id Additional destination identifier (e.g., memo, tag)
 * @property string|null $hash The transaction hash
 * @property string|null $error Error message if the payout failed
 * @property string|null $ipn_callback_url The IPN callback URL
 * @property \Carbon\Carbon|null $execute_at Scheduled execution timestamp
 * @property \Carbon\Carbon|null $processed_at Timestamp when the payout was processed
 * @property array|null $metadata Additional JSON metadata
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 *
 * @property-read Customer $customer
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<self>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereId(string $id)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereCustomerId(string $customerId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNowPaymentsPayoutId(string $nowpaymentsPayoutId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereStatus(string $status)
 * @method static \Illuminate\Database\Eloquent\Builder<self> successful()
 * @method static \Illuminate\Database\Eloquent\Builder<self> pending()
 * @method static \Illuminate\Database\Eloquent\Builder<self> failed()
 * @method static \Illuminate\Database\Eloquent\Builder<self> cancelled()
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class Payout extends Model
{
    use HasFactory, HasUlids;
    use HasNowPaymentsTable;
    use HasStatusChecks;
    use BelongsToCustomer;

    /**
     * Table suffix for the config-based prefix.
     */
    protected string $nowPaymentsTable = 'payouts';

    /**
     * Status values considered successful.
     */
    protected array $successfulStatuses = ['finished'];

    /**
     * Status values considered pending.
     */
    protected array $pendingStatuses = ['creating', 'waiting', 'processing', 'sending'];

    /**
     * Status values considered failed.
     */
    protected array $failedStatuses = ['failed', 'rejected'];

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
        'metadata' => 'array',
        'execute_at' => 'datetime',
        'processed_at' => 'datetime',
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
     * Scope a query to only include cancelled payouts.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeCancelled($query): void
    {
        $query->where('status', 'cancelled');
    }

    /**
     * Determine if the payout is cancelled.
     *
     * @return bool True if status is 'cancelled'
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Sync the payout status with NOWPayments API.
     *
     * Fetches the latest payout status from NOWPayments and updates
     * the local record.
     *
     * @return $this
     */
    public function syncStatus(): self
    {
        if ($this->nowpayments_payout_id === null) {
            return $this;
        }

        $response = NowPayments::getPayoutStatus($this->nowpayments_payout_id);

        $this->update([
            'status' => strtolower($response->status ?? $this->status),
            'hash' => $response->hash ?? $this->hash,
            'error' => $response->error ?? $this->error,
            'processed_at' => $response->status === 'finished' && $this->processed_at === null ? now() : $this->processed_at,
        ]);

        return $this;
    }

    /**
     * Cancel the payout.
     *
     * @return $this
     */
    public function cancel(): self
    {
        if ($this->isSuccessful() || $this->isCancelled()) {
            return $this;
        }

        $response = NowPayments::cancelPayout($this->nowpayments_payout_id);

        $this->update([
            'status' => 'cancelled',
        ]);

        return $this;
    }

    /**
     * Verify the payout with 2FA code.
     *
     * @param string $verificationCode The 2FA verification code
     * @return bool True if verification was successful
     */
    public function verify(string $verificationCode): bool
    {
        $request = new PayoutVerificationRequest(
            verificationCode: $verificationCode
        );

        return NowPayments::verifyPayout($this->nowpayments_payout_id, $request);
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
}

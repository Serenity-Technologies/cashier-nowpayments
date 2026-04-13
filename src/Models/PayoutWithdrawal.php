<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use SerenityTechnologies\CashierNowPayments\Concerns\{HasNowPaymentsTable, HasStatusChecks};
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents an individual withdrawal within a batch payout.
 *
 * A single payout may contain multiple withdrawals to different addresses.
 * This model tracks each one individually for auditing and status tracking.
 *
 * @property string $id The ULID primary key
 * @property string $payout_id The parent payout's ULID
 * @property string|null $nowpayments_withdrawal_id The NOWPayments withdrawal identifier
 * @property string $currency The withdrawal currency
 * @property string $amount The withdrawal amount
 * @property string|null $address The destination wallet address
 * @property string|null $extra_id Additional destination identifier (e.g., memo, tag)
 * @property string $status The withdrawal status (e.g., creating, waiting, processing, finished, failed, rejected)
 * @property string|null $hash The transaction hash
 * @property string|null $error Error message if the withdrawal failed
 * @property \Carbon\Carbon|null $processed_at Timestamp when the withdrawal was processed
 * @property array|null $metadata Additional JSON metadata
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 *
 * @property-read Payout $payout
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<self>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereId(string $id)
 * @method static \Illuminate\Database\Eloquent\Builder<self> wherePayoutId(string $payoutId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNowPaymentsWithdrawalId(string $nowpaymentsWithdrawalId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereStatus(string $status)
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class PayoutWithdrawal extends Model
{
    use HasFactory, HasUlids;
    use HasNowPaymentsTable;
    use HasStatusChecks;

    /**
     * Table suffix for the config-based prefix.
     */
    protected string $nowPaymentsTable = 'payout_withdrawals';

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
        'amount' => 'decimal:20,8',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the payout that owns this withdrawal.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Payout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo($this->getPayoutModel());
    }

    /**
     * Get the payout model class.
     *
     * @return class-string<Payout>
     */
    protected function getPayoutModel(): string
    {
        return config('cashier-nowpayments.model.payout', Payout::class);
    }
}

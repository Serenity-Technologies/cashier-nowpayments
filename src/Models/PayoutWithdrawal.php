<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents an individual withdrawal within a batch payout.
 *
 * A single payout may contain multiple withdrawals to different addresses.
 * This model tracks each one individually for auditing and status tracking.
 */
class PayoutWithdrawal extends Model
{
    use HasFactory, HasUlids;

    /**
     * Get the table name for the model.
     */
    public function getTable(): string
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return $prefix . 'payout_withdrawals';
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
        'amount' => 'decimal:20,8',
        'metadata' => 'array',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the payout that owns this withdrawal.
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo($this->getPayoutModel());
    }

    /**
     * Determine if the withdrawal is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'finished';
    }

    /**
     * Determine if the withdrawal is pending.
     */
    public function isPending(): bool
    {
        return in_array($this->status, ['creating', 'waiting', 'processing', 'sending'], true);
    }

    /**
     * Determine if the withdrawal has failed.
     */
    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'rejected'], true);
    }

    /**
     * Get the payout model class.
     */
    protected function getPayoutModel(): string
    {
        return config('cashier-nowpayments.model.payout', Payout::class);
    }
}

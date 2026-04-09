<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use SerenityTechnologies\NowPayments\DTOs\Request\PayoutVerificationRequest;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class Payout extends Model
{
    use HasFactory, HasUlids;

    /**
     * Get the table name for the model.
     */
    public function getTable(): string
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return $prefix . 'payouts';
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
        'metadata' => 'array',
        'execute_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    /**
     * Get the customer that owns the payout.
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
     * Scope a query to only include successful payouts.
     */
    public function scopeSuccessful($query): void
    {
        $query->where('status', 'finished');
    }

    /**
     * Scope a query to only include pending payouts.
     */
    public function scopePending($query): void
    {
        $query->whereIn('status', ['creating', 'waiting', 'processing', 'sending']);
    }

    /**
     * Scope a query to only include failed payouts.
     */
    public function scopeFailed($query): void
    {
        $query->whereIn('status', ['failed', 'rejected']);
    }

    /**
     * Scope a query to only include cancelled payouts.
     */
    public function scopeCancelled($query): void
    {
        $query->where('status', 'cancelled');
    }

    /**
     * Determine if the payout is successful.
     */
    public function isSuccessful(): bool
    {
        return $this->status === 'finished';
    }

    /**
     * Determine if the payout is pending.
     */
    public function isPending(): bool
    {
        return in_array($this->status, ['creating', 'waiting', 'processing', 'sending'], true);
    }

    /**
     * Determine if the payout has failed.
     */
    public function isFailed(): bool
    {
        return in_array($this->status, ['failed', 'rejected'], true);
    }

    /**
     * Determine if the payout is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /**
     * Sync the payout status with NOWPayments API.
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
     */
    protected function getCustomerModel(): string
    {
        return config('cashier-nowpayments.model.customer', Customer::class);
    }
}

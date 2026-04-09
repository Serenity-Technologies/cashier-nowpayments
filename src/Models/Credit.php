<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Credit extends Model
{
    use HasFactory, HasUlids;

    /**
     * Get the table name for the model.
     */
    public function getTable(): string
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return $prefix . 'credits';
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
        'amount' => 'decimal:8',
        'balance_before' => 'decimal:8',
        'balance_after' => 'decimal:8',
        'metadata' => 'array',
        'applied_at' => 'datetime',
    ];

    /**
     * Get the customer that owns the credit.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo($this->getCustomerModel());
    }

    /**
     * Get the subscription associated with the credit.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo($this->getSubscriptionModel());
    }

    /**
     * Get the owning reference model.
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include swap credits.
     */
    public function scopeSwaps($query): void
    {
        $query->where('type', 'swap');
    }

    /**
     * Scope a query to only include refunds.
     */
    public function scopeRefunds($query): void
    {
        $query->where('type', 'refund');
    }

    /**
     * Scope a query to only include manual adjustments.
     */
    public function scopeAdjustments($query): void
    {
        $query->where('type', 'adjustment');
    }

    /**
     * Determine if the credit is from a plan swap.
     */
    public function isSwap(): bool
    {
        return $this->type === 'swap';
    }

    /**
     * Determine if the credit is a refund.
     */
    public function isRefund(): bool
    {
        return $this->type === 'refund';
    }

    /**
     * Determine if the credit is a manual adjustment.
     */
    public function isAdjustment(): bool
    {
        return $this->type === 'adjustment';
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

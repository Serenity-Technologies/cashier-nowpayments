<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use SerenityTechnologies\CashierNowPayments\Concerns\{BelongsToCustomer, HasNowPaymentsTable};
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Credit Model
 *
 * Represents a credit entry in the customer's ledger. Credits can originate
 * from plan swaps (prorated refunds), manual refunds, or administrative
 * adjustments. Credits are consumed in FIFO order against future charges.
 *
 * @property-read string $id ULID primary key
 * @property string $customer_id Foreign key to the customer
 * @property string|null $subscription_id Associated subscription (for swap credits)
 * @property string $type Credit type: swap, refund, adjustment
 * @property string $amount Credit amount (8 decimal precision)
 * @property string $currency Currency code
 * @property string $balance_before Running balance before this credit
 * @property string $balance_after Running balance after this credit
 * @property string|null $reference_type Morph type of reference model
 * @property string|null $reference_id Morph ID of reference model
 * @property string|null $old_plan_id Plan being swapped away from
 * @property string|null $new_plan_id Plan being swapped to
 * @property string|null $description Human-readable description
 * @property array|null $metadata Additional JSON data
 * @property \Illuminate\Support\Carbon|null $applied_at When credit was consumed
 * @property \Illuminate\Support\Carbon|null $expires_at When credit expires
 * @property \Illuminate\Support\Carbon|null $created_at Creation timestamp
 * @property \Illuminate\Support\Carbon|null $updated_at Last update timestamp
 *
 * @property-read Customer|null $customer The customer that owns this credit
 * @property-read Subscription|null $subscription Associated subscription
 * @property-read Model|null $reference Polymorphic reference model
 *
 * @mixin \Illuminate\Database\Eloquent\Builder
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Credit swaps() Scope to swap credits
 * @method static \Illuminate\Database\Eloquent\Builder|Credit refunds() Scope to refund credits
 * @method static \Illuminate\Database\Eloquent\Builder|Credit adjustments() Scope to adjustment credits
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class Credit extends Model
{
    use HasFactory, HasUlids;
    use HasNowPaymentsTable;
    use BelongsToCustomer;

    /**
     * Table suffix for the config-based prefix.
     */
    protected string $nowPaymentsTable = 'credits';

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = true;

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
     * Get the subscription associated with the credit.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo($this->getSubscriptionModel());
    }

    /**
     * Get the owning reference model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Scope a query to only include swap credits.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeSwaps($query): void
    {
        $query->where('type', 'swap');
    }

    /**
     * Scope a query to only include refunds.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeRefunds($query): void
    {
        $query->where('type', 'refund');
    }

    /**
     * Scope a query to only include manual adjustments.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeAdjustments($query): void
    {
        $query->where('type', 'adjustment');
    }

    /**
     * Determine if the credit is from a plan swap.
     *
     * @return bool
     */
    public function isSwap(): bool
    {
        return $this->type === 'swap';
    }

    /**
     * Determine if the credit is a refund.
     *
     * @return bool
     */
    public function isRefund(): bool
    {
        return $this->type === 'refund';
    }

    /**
     * Determine if the credit is a manual adjustment.
     *
     * @return bool
     */
    public function isAdjustment(): bool
    {
        return $this->type === 'adjustment';
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
}

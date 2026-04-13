<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use SerenityTechnologies\CashierNowPayments\Concerns\HasNowPaymentsTable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Represents a subscription plan from NOWPayments.
 *
 * Plans define recurring billing configurations including amount,
 * currency, and billing interval. They can be synced from the
 * NOWPayments API.
 *
 * @property string $id The ULID primary key
 * @property string|null $nowpayments_plan_id The NOWPayments plan identifier
 * @property string $name The plan name
 * @property string|null $description The plan description
 * @property string $amount The plan price amount
 * @property string|null $currency The plan currency
 * @property int|null $interval_days The billing interval in days
 * @property string $status The plan status (e.g., active, inactive)
 * @property string|null $success_url Redirect URL after successful subscription creation
 * @property string|null $cancel_url Redirect URL after cancelled subscription creation
 * @property array|null $metadata Additional JSON metadata
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SubscriptionItem> $subscriptionItems
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Subscription> $subscriptions
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<self>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereId(string $id)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNowPaymentsPlanId(string $nowpaymentsPlanId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereStatus(string $status)
 * @method static \Illuminate\Database\Eloquent\Builder<self> active()
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class Plan extends Model
{
    use HasFactory, HasUlids;

    use HasNowPaymentsTable;

    /**
     * Table suffix for the config-based prefix.
     */
    protected string $nowPaymentsTable = 'plans';

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
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    /**
     * Get the subscription items for this plan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<SubscriptionItem, $this>
     */
    public function subscriptionItems(): HasMany
    {
        return $this->hasMany($this->getSubscriptionItemModel(), 'nowpayments_plan_id', 'nowpayments_plan_id');
    }

    /**
     * Get the subscriptions for this plan.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany($this->getSubscriptionModel(), 'nowpayments_plan_id', 'nowpayments_plan_id');
    }

    /**
     * Scope a query to only include active plans.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeActive($query): void
    {
        $query->where('status', 'active');
    }

    /**
     * Determine if the plan is active.
     *
     * @return bool True if status is 'active'
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Sync the plan details from NOWPayments API.
     *
     * Fetches the latest plan details from NOWPayments and updates
     * the local record.
     *
     * @return $this
     */
    public function syncFromApi(): self
    {
        if ($this->nowpayments_plan_id === null) {
            return $this;
        }

        $response = \SerenityTechnologies\NowPayments\Facades\NowPayments::getPlan($this->nowpayments_plan_id);

        $this->update([
            'name' => $response->title ?? $this->name,
            'amount' => $response->amount ?? $this->amount,
            'currency' => $response->currency ?? $this->currency,
            'interval_days' => $response->interval_days ?? $this->interval_days,
            'status' => $response->status ?? $this->status,
            'success_url' => $response->success_url ?? $this->success_url,
            'cancel_url' => $response->cancel_url ?? $this->cancel_url,
        ]);

        return $this;
    }

    /**
     * Get the subscription item model class.
     *
     * @return class-string<SubscriptionItem>
     */
    protected function getSubscriptionItemModel(): string
    {
        return config('cashier-nowpayments.model.subscription_item', SubscriptionItem::class);
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

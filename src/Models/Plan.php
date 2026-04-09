<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    use HasFactory, HasUlids;

    /**
     * Get the table name for the model.
     */
    public function getTable(): string
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return $prefix . 'plans';
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
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    /**
     * Get the subscription items for this plan.
     */
    public function subscriptionItems(): HasMany
    {
        return $this->hasMany($this->getSubscriptionItemModel(), 'nowpayments_plan_id', 'nowpayments_plan_id');
    }

    /**
     * Get the subscriptions for this plan.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany($this->getSubscriptionModel(), 'nowpayments_plan_id', 'nowpayments_plan_id');
    }

    /**
     * Scope a query to only include active plans.
     */
    public function scopeActive($query): void
    {
        $query->where('status', 'active');
    }

    /**
     * Determine if the plan is active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Sync the plan details from NOWPayments API.
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
     */
    protected function getSubscriptionItemModel(): string
    {
        return config('cashier-nowpayments.model.subscription_item', SubscriptionItem::class);
    }

    /**
     * Get the subscription model class.
     */
    protected function getSubscriptionModel(): string
    {
        return config('cashier-nowpayments.model.subscription', Subscription::class);
    }
}

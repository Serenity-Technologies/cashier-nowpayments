<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionItem extends Model
{
    use HasFactory, HasUlids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Get the table name for the model.
     */
    public function getTable(): string
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return $prefix . 'subscription_items';
    }

    /**
     * Get the subscription that owns the item.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo($this->getSubscriptionModel());
    }

    /**
     * Get the subscription model class.
     */
    protected function getSubscriptionModel(): string
    {
        return config('cashier-nowpayments.model.subscription', Subscription::class);
    }
}

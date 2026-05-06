<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments\Models;

use SerenityTechnologies\CashierNowPayments\Concerns\HasNowPaymentsTable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Represents an individual line item within a subscription.
 *
 * Subscription items allow a single subscription to contain multiple
 * plan components, each with its own description, amount, and quantity.
 *
 * @property string $id The ULID primary key
 * @property string $subscription_id The parent subscription's ULID
 * @property string $nowpayments_plan_id The NOWPayments plan identifier
 * @property string|null $description The item description
 * @property string $amount The item price amount
 * @property int $quantity The item quantity
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 *
 * @property-read Subscription $subscription
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<self>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereId(string $id)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereSubscriptionId(string $subscriptionId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNowPaymentsPlanId(string $nowpaymentsPlanId)
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class SubscriptionItem extends Model
{
    use HasFactory, HasUlids;
    use HasNowPaymentsTable;

    /**
     * Table suffix for the config-based prefix.
     */
    protected string $nowPaymentsTable = 'subscription_items';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * Get the subscription that owns the item.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo($this->getSubscriptionModel());
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

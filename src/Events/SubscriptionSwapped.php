<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

use Illuminate\Database\Eloquent\Model;
use SerenityTechnologies\CashierNowPayments\Models\Subscription;

/**
 * Event dispatched when a subscription is swapped to a new plan.
 *
 * @package SerenityTechnologies\CashierNowPayments\Events
 */
class SubscriptionSwapped extends CashierNowPaymentsEvent
{
    /**
     * The subscription that was swapped.
     *
     * @var \SerenityTechnologies\CashierNowPayments\Models\Subscription
     */
    public Subscription $subscription;

    /**
     * Additional swap metadata.
     *
     * @var array<string, mixed>
     */
    public array $payload;

    /**
     * Create a new event instance.
     *
     * @param \SerenityTechnologies\CashierNowPayments\Models\Subscription $subscription
     * @param array<string, mixed> $payload
     */
    public function __construct(Subscription $subscription, array $payload = [])
    {
        $this->subscription = $subscription;
        $this->payload = $payload;
        parent::__construct($subscription, $payload);
    }

    /**
     * Get the billable model that owns the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Model|null
     */
    public function getBillable(): ?Model
    {
        return $this->subscription->customer?->billable;
    }
}

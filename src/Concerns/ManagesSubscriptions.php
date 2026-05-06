<?php
/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use SerenityTechnologies\CashierNowPayments\Models\Subscription;
use SerenityTechnologies\CashierNowPayments\SubscriptionBuilder;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

trait ManagesSubscriptions
{
    /**
     * Begin creating a new subscription.
     */
    public function newSubscription(string $type, int|string $planId): SubscriptionBuilder
    {
        $customer = $this->createOrGetCustomer();

        return new SubscriptionBuilder($this, $customer, $type, $planId);
    }

    /**
     * Get a subscription by type.
     */
    public function subscription(string $type = 'default'): ?Subscription
    {
        $customer = $this->customer;

        if ($customer === null) {
            return null;
        }

        return $customer->subscription($type);
    }

    /**
     * Get all subscriptions for the billable model.
     */
    public function subscriptions(): HasMany
    {
        $customer = $this->createOrGetCustomer();

        return $customer->subscriptions();
    }

    /**
     * Get subscriptions from NOWPayments API.
     */
    public function remoteSubscriptions(array $filters = []): \SerenityTechnologies\NowPayments\DTOs\Response\SubscriptionListResponse
    {
        return NowPayments::listSubscriptions($filters);
    }

    /**
     * Determine if the billable model is on trial.
     */
    public function onTrial(string $type = 'default', ?string $planId = null): bool
    {
        $customer = $this->customer;

        if ($customer === null) {
            return false;
        }

        if ($customer->onTrial()) {
            return true;
        }

        $subscription = $customer->subscription($type);

        if ($subscription === null) {
            return false;
        }

        if ($planId !== null && $subscription->nowpayments_plan_id !== $planId) {
            return false;
        }

        return $subscription->isOnTrial();
    }

    /**
     * Determine if the billable model is subscribed.
     */
    public function subscribed(string $type = 'default', ?string $planId = null): bool
    {
        $customer = $this->customer;

        if ($customer === null) {
            return false;
        }

        return $customer->subscribed($type, $planId);
    }
}

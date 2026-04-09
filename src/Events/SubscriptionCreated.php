<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

use SerenityTechnologies\CashierNowPayments\Models\Subscription;

class SubscriptionCreated extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly object $billable,
        public readonly object $customer,
        public readonly Subscription $subscription,
        public readonly \SerenityTechnologies\NowPayments\DTOs\Response\SubscriptionResponse $subscriptionResponse,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($subscription, $nowpaymentsPayload);
    }
}

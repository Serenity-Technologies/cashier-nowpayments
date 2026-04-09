<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

use SerenityTechnologies\CashierNowPayments\Models\Subscription;

class SubscriptionExpired extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly Subscription $subscription,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($subscription, $nowpaymentsPayload);
    }
}

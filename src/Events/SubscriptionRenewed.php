<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments\Events;

use SerenityTechnologies\CashierNowPayments\Models\Subscription;

class SubscriptionRenewed extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly Subscription $subscription,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($subscription, $nowpaymentsPayload);
    }
}

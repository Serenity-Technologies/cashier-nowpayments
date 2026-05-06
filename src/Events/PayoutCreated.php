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

class PayoutCreated extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly object $billable,
        public readonly object $customer,
        public readonly \SerenityTechnologies\NowPayments\DTOs\Response\PayoutResponse $payoutResponse,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($payoutResponse, $nowpaymentsPayload);
    }
}

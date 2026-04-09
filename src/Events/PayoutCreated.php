<?php

declare(strict_types=1);

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

<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

class PaymentCreated extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly object $billable,
        public readonly object $customer,
        public readonly \SerenityTechnologies\NowPayments\DTOs\Response\PaymentResponse $paymentResponse,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($paymentResponse, $nowpaymentsPayload);
    }
}

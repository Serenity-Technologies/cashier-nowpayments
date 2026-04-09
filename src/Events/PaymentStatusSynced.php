<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

use SerenityTechnologies\CashierNowPayments\Models\Payment;

class PaymentStatusSynced extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly Payment $payment,
        public readonly \SerenityTechnologies\NowPayments\DTOs\Response\PaymentResponse $apiResponse,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($payment, $nowpaymentsPayload);
    }
}

<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

use SerenityTechnologies\CashierNowPayments\Models\Payment;

class PaymentReceived extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly Payment $payment,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($payment, $nowpaymentsPayload);
    }
}

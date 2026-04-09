<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

use SerenityTechnologies\CashierNowPayments\Models\Payment;

class PaymentRefunded extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly Payment $payment,
        public readonly ?float $refundAmount = null,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($payment, $nowpaymentsPayload);
    }
}

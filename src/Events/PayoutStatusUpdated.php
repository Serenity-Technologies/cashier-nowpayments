<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

use SerenityTechnologies\CashierNowPayments\Models\Payout;

class PayoutStatusUpdated extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly Payout $payout,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($payout, $nowpaymentsPayload);
    }
}

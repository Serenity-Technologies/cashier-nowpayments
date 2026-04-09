<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

use Illuminate\Support\Collection;

class CreditExpired extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly Collection $credits,
        public readonly int $count,
        public readonly string $totalAmount,
    ) {
        parent::__construct($credits, []);
    }
}

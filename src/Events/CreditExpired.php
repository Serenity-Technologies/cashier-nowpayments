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

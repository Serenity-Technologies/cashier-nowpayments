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

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

abstract class CashierNowPaymentsEvent
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly object $model,
        public readonly array $nowpaymentsPayload = [],
    ) {
    }
}

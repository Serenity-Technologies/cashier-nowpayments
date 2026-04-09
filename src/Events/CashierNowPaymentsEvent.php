<?php

declare(strict_types=1);

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

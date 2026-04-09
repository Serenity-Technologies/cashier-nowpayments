<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

use SerenityTechnologies\CashierNowPayments\Models\Invoice;

class InvoicePaid extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly Invoice $invoice,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($invoice, $nowpaymentsPayload);
    }
}

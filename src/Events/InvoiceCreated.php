<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Events;

class InvoiceCreated extends CashierNowPaymentsEvent
{
    public function __construct(
        public readonly object $billable,
        public readonly object $customer,
        public readonly \SerenityTechnologies\NowPayments\DTOs\Response\InvoiceResponse $invoiceResponse,
        array $nowpaymentsPayload = [],
    ) {
        parent::__construct($invoiceResponse, $nowpaymentsPayload);
    }
}

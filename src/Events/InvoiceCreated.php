<?php
/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */

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

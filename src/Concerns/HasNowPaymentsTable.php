<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

/**
 * Provides automatic table name resolution using the configured prefix.
 *
 * Models using this trait must define a protected `$nowPaymentsTable` property
 * with the table suffix (e.g., 'invoices', 'payments').
 */
trait HasNowPaymentsTable
{
    /**
     * Get the table name for the model.
     */
    public function getTable(): string
    {
        return config('cashier-nowpayments.prefix', 'cashier_nowpayments_') . $this->nowPaymentsTable;
    }
}

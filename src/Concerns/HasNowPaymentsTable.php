<?php
/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */

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

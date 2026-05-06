<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments;

use SerenityTechnologies\CashierNowPayments\Concerns\{
    ManagesBalance,
    ManagesConversions,
    ManagesCustomer,
    ManagesCurrencies,
    ManagesFiatPayouts,
    ManagesInvoices,
    ManagesPayments,
    ManagesPayouts,
    ManagesSubscriptions,
    ProvidesCheckoutHelpers
};

/**
 * Main Billable trait.
 *
 * This trait aggregates all concerns for a billable model. Include this single trait
 * in your model to enable all Cashier NOWPayments functionality.
 *
 * @see ManagesCustomer
 * @see ManagesPayments
 * @see ManagesInvoices
 * @see ManagesSubscriptions
 * @see ManagesPayouts
 * @see ManagesBalance
 * @see ManagesCurrencies
 * @see ManagesConversions
 * @see ManagesFiatPayouts
 * @see ManagesPlans
 */
trait Billable
{
    use ManagesCustomer;
    use ManagesPayments;
    use ManagesInvoices;
    use ManagesSubscriptions;
    use ManagesPayouts;
    use ManagesBalance;
    use ManagesCurrencies;
    use ManagesConversions;
    use ManagesFiatPayouts;
    use ProvidesCheckoutHelpers;
}

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

use SerenityTechnologies\NowPayments\Facades\NowPayments;

trait ManagesBalance
{
    /**
     * Get account balance from NOWPayments.
     */
    public function balance(): \SerenityTechnologies\NowPayments\DTOs\Response\BalanceResponse
    {
        return NowPayments::getBalance();
    }
}

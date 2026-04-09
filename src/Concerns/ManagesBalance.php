<?php

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

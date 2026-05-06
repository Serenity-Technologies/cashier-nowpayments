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

use SerenityTechnologies\NowPayments\DTOs\Request\ConversionRequest;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

trait ManagesConversions
{
    /**
     * Convert cryptocurrency.
     */
    public function convert(float $amount, string $fromCurrency, string $toCurrency): \SerenityTechnologies\NowPayments\DTOs\Response\ConversionResponse
    {
        $request = new ConversionRequest(
            amount: $amount,
            fromCurrency: $fromCurrency,
            toCurrency: $toCurrency,
        );

        return NowPayments::createConversion($request);
    }

    /**
     * Get conversion history.
     */
    public function remoteConversions(array $filters = []): \SerenityTechnologies\NowPayments\DTOs\Response\ConversionListResponse
    {
        return NowPayments::listConversions($filters);
    }
}

<?php

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

<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

trait ManagesCurrencies
{
    /**
     * Get available currencies.
     */
    public static function availableCurrencies(bool $fixedRate = false): Collection
    {
        $cacheKey = 'nowpayments.currencies.' . ($fixedRate ? 'fixed' : 'standard');

        return Cache::remember($cacheKey, now()->addHour(), function () use ($fixedRate) {
            $response = NowPayments::getAvailableCurrencies($fixedRate);
            return collect($response->currencies ?? []);
        });
    }

    /**
     * Get full currency list with details.
     */
    public static function fullCurrencies(): \SerenityTechnologies\NowPayments\DTOs\Response\FullCurrencyResponse
    {
        return NowPayments::getFullCurrencies();
    }

    /**
     * Get merchant's enabled coins.
     */
    public static function merchantCoins(): \SerenityTechnologies\NowPayments\DTOs\Response\CurrencyResponse
    {
        return NowPayments::getMerchantCoins();
    }
}

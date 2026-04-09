<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use SerenityTechnologies\NowPayments\Facades\NowPayments;

trait ManagesFiatPayouts
{
    /**
     * Get fiat providers.
     */
    public static function fiatProviders(): \SerenityTechnologies\NowPayments\DTOs\Response\FiatProvidersResponse
    {
        return NowPayments::getProviders();
    }

    /**
     * Get supported fiat currencies.
     */
    public static function supportedFiatCurrencies(): \SerenityTechnologies\NowPayments\DTOs\Response\FiatCurrenciesResponse
    {
        return NowPayments::getFiatCurrencies();
    }

    /**
     * Get supported crypto currencies for fiat payout.
     */
    public static function supportedCryptoForFiat(string $provider, string $fiatCurrency): \SerenityTechnologies\NowPayments\DTOs\Response\FiatCryptoCurrenciesResponse
    {
        return NowPayments::getCryptoCurrencies($provider, $fiatCurrency);
    }

    /**
     * Get fiat payment methods.
     */
    public static function fiatPaymentMethods(string $provider, string $fiatCurrency): \SerenityTechnologies\NowPayments\DTOs\Response\FiatPaymentMethodsResponse
    {
        return NowPayments::getPaymentMethods($provider, $fiatCurrency);
    }
}

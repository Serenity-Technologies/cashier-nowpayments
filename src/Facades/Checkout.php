<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Checkout Facade
 *
 * @see \SerenityTechnologies\CashierNowPayments\Services\CheckoutService
 *
 * @method static \SerenityTechnologies\CashierNowPayments\Support\CheckoutSession createSession(float $amount, string $currency, array $options = [])
 * @method static \SerenityTechnologies\CashierNowPayments\Support\CheckoutSession|null getSession(string $sessionId)
 * @method static bool isApiAvailable()
 * @method static array getAvailableCurrencies(bool $fixedRate = false)
 * @method static float getMinimumPaymentAmount(string $fromCurrency, string $toCurrency)
 * @method static \SerenityTechnologies\CashierNowPayments\Support\EstimateResult getEstimate(float $amount, string $fromCurrency, string $toCurrency, bool $forceRefresh = false)
 * @method static \SerenityTechnologies\CashierNowPayments\Support\ValidationResult validateAmount(float $amount, string $fromCurrency, string $toCurrency)
 * @method static \SerenityTechnologies\CashierNowPayments\Support\PaymentResult createPayment(float $amount, string $currency, string $payCurrency, array $options = [])
 * @method static \SerenityTechnologies\CashierNowPayments\Support\InvoiceResult createInvoice(float $amount, string $currency, array $options = [])
 * @method static \SerenityTechnologies\CashierNowPayments\Models\Payment completeCheckout(\SerenityTechnologies\CashierNowPayments\Support\PaymentResult $paymentResult, \SerenityTechnologies\CashierNowPayments\Models\Customer $customer, ?\Illuminate\Database\Eloquent\Model $billable = null)
 * @method static string generateQrCodeUri(string $address, float $amount)
 *
 * @mixin \SerenityTechnologies\CashierNowPayments\Services\CheckoutService
 */
class Checkout extends Facade
{
    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return \SerenityTechnologies\CashierNowPayments\Services\CheckoutService::class;
    }
}

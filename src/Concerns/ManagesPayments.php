<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use SerenityTechnologies\CashierNowPayments\PaymentBuilder;
use SerenityTechnologies\NowPayments\DTOs\Request\{EstimateRequest, MinAmountRequest, PaymentListQuery};
use SerenityTechnologies\NowPayments\Exceptions\NowPaymentsException;
use SerenityTechnologies\NowPayments\Facades\NowPayments;
use SerenityTechnologies\NowPayments\QueryBuilders\PaymentListQueryBuilder;

trait ManagesPayments
{
    /**
     * Begin creating a new one-time payment.
     */
    public function charge(float $amount, string $currency): PaymentBuilder
    {
        $customer = $this->createOrGetCustomer();

        return new PaymentBuilder($this, $customer, $amount, $currency);
    }

    /**
     * Get all payments for the billable model.
     */
    public function payments(): HasMany
    {
        $customer = $this->createOrGetCustomer();

        return $customer->payments();
    }

    /**
     * Get payment history from NOWPayments API with filters.
     */
    public function remotePayments(array $filters = []): \SerenityTechnologies\NowPayments\DTOs\Response\PaymentListResponse
    {
        // Scope to this customer's order IDs if not explicitly provided
        $customer = $this->createOrGetCustomer();
        if (!isset($filters['order_id'])) {
            $filters['order_id'] = $customer->nowpayments_customer_id;
        }

        $paymentListQuery = new PaymentListQuery(
            limit: $filters['limit'] ?? null,
            page: $filters['page'] ?? null,
            sortBy: $filters['sort_by'] ?? null,
            orderBy: $filters['order_by'] ?? null,
            dateFrom: $filters['date_from'] ?? null,
            dateTo: $filters['date_to'] ?? null,
            paymentStatus: $filters['payment_status'] ?? null,
            payCurrency: $filters['pay_currency'] ?? null,
            priceCurrency: $filters['price_currency'] ?? null,
            orderId: $filters['order_id'] ?? null,
        );

        return NowPayments::getListPayments($paymentListQuery);
    }

    /**
     * Estimate crypto amount for a fiat payment.
     */
    public function estimateCrypto(float $fiatAmount, string $fiatCurrency, string $cryptoCurrency): \SerenityTechnologies\NowPayments\DTOs\Response\EstimateResponse
    {
        $request = new EstimateRequest(
            currencyFrom: $fiatCurrency,
            currencyTo: $cryptoCurrency,
            amount: $fiatAmount,
        );

        return NowPayments::getEstimate($request);
    }

    /**
     * Get minimum payment amount for a currency pair.
     * @throws NowPaymentsException
     */
    public function minimumPaymentAmount(string $fromCurrency, string $toCurrency): \SerenityTechnologies\NowPayments\DTOs\Response\MinAmountResponse
    {
        $request = new MinAmountRequest(
            currencyFrom: $fromCurrency,
            currencyTo: $toCurrency,
        );

        return NowPayments::getMinAmount($request);
    }

    /**
     * Determine if the billable model has any incomplete payments.
     */
    public function hasIncompletePayment(): bool
    {
        $customer = $this->customer;

        if ($customer === null) {
            return false;
        }

        return $customer->hasIncompletePayment();
    }
}

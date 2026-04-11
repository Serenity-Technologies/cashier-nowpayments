<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Services;

use Illuminate\Support\Facades\Cache;
use SerenityTechnologies\CashierNowPayments\Exceptions\CheckoutException;
use SerenityTechnologies\CashierNowPayments\Models\Customer;
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use SerenityTechnologies\CashierNowPayments\Support\CheckoutSession;
use SerenityTechnologies\CashierNowPayments\Support\EstimateResult;
use SerenityTechnologies\CashierNowPayments\Support\InvoiceResult;
use SerenityTechnologies\CashierNowPayments\Support\PaymentResult;
use SerenityTechnologies\CashierNowPayments\Support\ValidationResult;
use SerenityTechnologies\NowPayments\DTOs\Request\{EstimateRequest, MinAmountRequest, PaymentRequest};
use SerenityTechnologies\NowPayments\Facades\NowPayments;

/**
 * Checkout Service
 *
 * Orchestrates the complete checkout flow based on the NOWPayments API specification.
 * This service provides a unified API for handling all checkout scenarios,
 * inspired by Laravel Cashier Stripe's service-oriented approach.
 *
 * Standard E-commerce Flow:
 * 1. Verify API availability
 * 2. Get available currencies
 * 3. Get minimum payment amount for currency pair
 * 4. Get estimate for crypto amount
 * 5. Create payment and get deposit address
 * 6. Display payment details to customer
 * 7. Monitor payment status (via webhooks or polling)
 *
 * Alternative Flow (Invoice):
 * 1-4. Same as above
 * 5. Create invoice and redirect customer
 * 6. Customer completes payment on NOWPayments hosted page
 * 7. Customer redirected back to success_url
 */
class CheckoutService
{
    /**
     * Cache key prefix for checkout data.
     */
    protected const CACHE_PREFIX = 'cashier-nowpayments.checkout.';

    /**
     * Cache TTL for currency list (1 hour).
     */
    protected const CURRENCY_CACHE_TTL = 3600;

    /**
     * Cache TTL for minimum payment amounts (30 minutes).
     */
    protected const MIN_AMOUNT_CACHE_TTL = 1800;

    /**
     * Cache TTL for estimates (2 minutes - rates fluctuate).
     */
    protected const ESTIMATE_CACHE_TTL = 120;

    /**
     * Checkout session data.
     */
    protected array $session = [];

    /**
     * Create a new checkout session.
     *
     * @param float $amount The payment amount in fiat currency
     * @param string $currency The fiat currency code (e.g., 'usd', 'eur')
     * @param array $options Additional checkout options
     * @return CheckoutSession
     */
    public function createSession(float $amount, string $currency, array $options = []): CheckoutSession
    {
        $sessionId = $this->generateSessionId();

        $this->session = [
            'id' => $sessionId,
            'amount' => $amount,
            'currency' => $currency,
            'description' => $options['description'] ?? null,
            'order_id' => $options['order_id'] ?? null,
            'success_url' => $options['success_url'] ?? config('app.url'),
            'cancel_url' => $options['cancel_url'] ?? config('app.url'),
            'pay_currency' => $options['pay_currency'] ?? null,
            'fixed_rate' => $options['fixed_rate'] ?? config('cashier-nowpayments.fixed_rate', false),
            'fee_paid_by_user' => $options['fee_paid_by_user'] ?? config('cashier-nowpayments.fee_paid_by_user', false),
            'metadata' => $options['metadata'] ?? [],
            'created_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(30)->toIso8601String(),
        ];

        // Cache session for 30 minutes
        Cache::put(
            self::CACHE_PREFIX . 'session.' . $sessionId,
            $this->session,
            now()->addMinutes(30)
        );

        return new CheckoutSession($this->session);
    }

    /**
     * Retrieve an existing checkout session.
     *
     * @param string $sessionId
     * @return CheckoutSession|null
     */
    public function getSession(string $sessionId): ?CheckoutSession
    {
        $data = Cache::get(self::CACHE_PREFIX . 'session.' . $sessionId);

        if ($data === null) {
            return null;
        }

        return new CheckoutSession($data);
    }

    /**
     * Check NOWPayments API availability.
     *
     * @return bool
     */
    public function isApiAvailable(): bool
    {
        try {
            $response = NowPayments::getStatus();
            return $response->message === 'OK';
        } catch (\Exception $e) {
            report($e);
            return false;
        }
    }

    /**
     * Get available payment currencies.
     *
     * @param bool $fixedRate Whether to get currencies available for fixed rate
     * @return array
     */
    public function getAvailableCurrencies(bool $fixedRate = false): array
    {
        $cacheKey = self::CACHE_PREFIX . 'currencies.' . ($fixedRate ? 'fixed' : 'standard');

        return Cache::remember($cacheKey, self::CURRENCY_CACHE_TTL, function () use ($fixedRate) {
            try {
                $response = NowPayments::getAvailableCurrencies($fixedRate);
                return $response->currencies ?? [];
            } catch (\Exception $e) {
                report($e);
                return [];
            }
        });
    }

    /**
     * Get minimum payment amount for a currency pair.
     *
     * @param string $fromCurrency The fiat currency (e.g., 'usd')
     * @param string $toCurrency The crypto currency (e.g., 'btc')
     * @return float
     */
    public function getMinimumPaymentAmount(string $fromCurrency, string $toCurrency): float
    {
        $cacheKey = self::CACHE_PREFIX . 'min-amount.' . $fromCurrency . '.' . $toCurrency;

        return Cache::remember($cacheKey, self::MIN_AMOUNT_CACHE_TTL, function () use ($fromCurrency, $toCurrency) {
            try {
                $response = NowPayments::getMinAmount(new MinAmountRequest(
                    currencyFrom: $fromCurrency,
                    currencyTo: $toCurrency,
                ));

                return (float) $response->min_amount;
            } catch (\Exception $e) {
                report($e);
                return 0.0;
            }
        });
    }

    /**
     * Get estimated crypto amount for a fiat payment.
     *
     * @param float $amount The fiat amount
     * @param string $fromCurrency The fiat currency
     * @param string $toCurrency The crypto currency
     * @param bool $forceRefresh Force fresh API call instead of cache
     * @return EstimateResult
     * @throws CheckoutException
     */
    public function getEstimate(
        float $amount,
        string $fromCurrency,
        string $toCurrency,
        bool $forceRefresh = false
    ): EstimateResult {
        $cacheKey = self::CACHE_PREFIX . 'estimate.' . $amount . '.' . $fromCurrency . '.' . $toCurrency;

        if (!$forceRefresh) {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return new EstimateResult($cached);
            }
        }

        try {
            $response = NowPayments::getEstimate(new EstimateRequest(
                currencyFrom: $fromCurrency,
                currencyTo: $toCurrency,
                amount: $amount,
            ));

            $result = [
                'estimated_amount' => (float) $response->estimated_amount,
                'fee' => $response->fee_estimated ?? null,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'amount' => $amount,
            ];

            Cache::put($cacheKey, $result, now()->addSeconds(self::ESTIMATE_CACHE_TTL));

            return new EstimateResult($result);
        } catch (\Exception $e) {
            report($e);
            throw new CheckoutException('Failed to get payment estimate: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Validate that the amount meets minimum requirements.
     *
     * @param float $amount The fiat amount
     * @param string $fromCurrency The fiat currency
     * @param string $toCurrency The crypto currency
     * @return ValidationResult
     * @throws CheckoutException
     */
    public function validateAmount(float $amount, string $fromCurrency, string $toCurrency): ValidationResult
    {
        $estimate = $this->getEstimate($amount, $fromCurrency, $toCurrency);
        $minimum = $this->getMinimumPaymentAmount($fromCurrency, $toCurrency);

        $isValid = $estimate->getEstimatedAmount() >= $minimum;

        return new ValidationResult(
            valid: $isValid,
            amount: $amount,
            estimatedAmount: $estimate->getEstimatedAmount(),
            minimumAmount: $minimum,
            currency: $fromCurrency,
            payCurrency: $toCurrency,
            errors: $isValid ? [] : ['Amount is below minimum payment requirement']
        );
    }

    /**
     * Create a direct payment (standard e-commerce flow).
     *
     * This creates a payment on NOWPayments and returns payment details
     * including deposit address and QR code URI.
     *
     * @param float $amount The payment amount
     * @param string $currency The fiat currency
     * @param string $payCurrency The crypto currency to pay with
     * @param array $options Additional options
     * @return PaymentResult
     * @throws CheckoutException
     */
    public function createPayment(
        float $amount,
        string $currency,
        string $payCurrency,
        array $options = []
    ): PaymentResult {
        // Validate amount first
        $validation = $this->validateAmount($amount, $currency, $payCurrency);
        if (!$validation->isValid()) {
            throw new CheckoutException(
                "Amount {$amount} {$currency} is below minimum. Minimum: {$validation->getMinimumAmount()} {$validation->getCurrency()}",
                422
            );
        }

        try {
            $request = new PaymentRequest(
                priceAmount: $amount,
                priceCurrency: $currency,
                payCurrency: $payCurrency,
                ipnCallbackUrl: $options['ipn_callback_url'] ?? $this->getDefaultWebhookUrl(),
                orderId: $options['order_id'] ?? $this->generateOrderId(),
                orderDescription: $options['description'] ?? null,
                payoutAddress: $options['payout_address'] ?? null,
                payoutCurrency: $options['payout_currency'] ?? null,
                payoutExtraId: $options['payout_extra_id'] ?? null,
                isFixedRate: $options['fixed_rate'] ?? config('cashier-nowpayments.fixed_rate', false),
                isFeePaidByUser: $options['fee_paid_by_user'] ?? config('cashier-nowpayments.fee_paid_by_user', false),
            );

            $response = NowPayments::createPayment($request);

            return new PaymentResult(
                paymentId: (string) $response->payment_id,
                purchaseId: (string) $response->purchase_id,
                payAddress: $response->pay_address,
                payAmount: (float) $response->pay_amount,
                payCurrency: $response->pay_currency,
                priceAmount: (float) $response->price_amount,
                priceCurrency: $response->price_currency,
                orderId: $response->order_id,
                description: $response->order_description,
                qrCodeUri: $this->generateQrCodeUri($response->pay_address, (float) $response->pay_amount),
                expirationTime: now()->addMinutes(15)->toIso8601String(),
                metadata: $options['metadata'] ?? [],
            );
        } catch (\Exception $e) {
            report($e);
            throw new CheckoutException('Failed to create payment: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a hosted invoice (alternative checkout flow).
     *
     * This creates an invoice on NOWPayments and returns the invoice URL
     * for customer redirect.
     *
     * @param float $amount The payment amount
     * @param string $currency The fiat currency
     * @param array $options Additional options
     * @return InvoiceResult
     */
    public function createInvoice(
        float $amount,
        string $currency,
        array $options = []
    ): InvoiceResult {
        try {
            $response = NowPayments::createInvoice(new \SerenityTechnologies\NowPayments\DTOs\Request\InvoiceRequest(
                priceAmount: $amount,
                priceCurrency: $currency,
                ipnCallbackUrl: $options['ipn_callback_url'] ?? $this->getDefaultWebhookUrl(),
                orderId: $options['order_id'] ?? $this->generateOrderId(),
                orderDescription: $options['description'] ?? null,
                successUrl: $options['success_url'] ?? config('app.url'),
                cancelUrl: $options['cancel_url'] ?? config('app.url'),
                partiallyPaidUrl: $options['partially_paid_url'] ?? null,
                isFixedRate: $options['fixed_rate'] ?? config('cashier-nowpayments.fixed_rate', false),
            ));

            return new InvoiceResult(
                invoiceId: (string) $response->id,
                invoiceUrl: $response->invoice_url,
                orderId: $response->order_id,
                description: $response->order_description,
                amount: (float) $response->price_amount,
                currency: $response->price_currency,
                successUrl: $options['success_url'] ?? config('app.url'),
                cancelUrl: $options['cancel_url'] ?? config('app.url'),
                expiresAt: $response->expires_at ?? null,
            );
        } catch (\Exception $e) {
            report($e);
            throw new CheckoutException('Failed to create invoice: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Complete the checkout by persisting payment data and firing events.
     *
     * This method should be called after payment verification to:
     * - Create/update local Payment record
     * - Associate with Customer
     * - Fire PaymentCreated event
     *
     * @param PaymentResult $paymentResult The result from createPayment()
     * @param Customer $customer The customer model
     * @param \Illuminate\Database\Eloquent\Model|null $billable The billable model (User, etc.)
     * @return Payment
     */
    public function completeCheckout(
        PaymentResult $paymentResult,
        Customer $customer,
        ?\Illuminate\Database\Eloquent\Model $billable = null
    ): Payment {
        $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);

        /** @var Payment $payment */
        $payment = new $paymentModel();

        $payment->fill([
            'customer_id' => $customer->id,
            'billable_id' => $billable?->getKey(),
            'billable_type' => $billable?->getMorphClass(),
            'nowpayments_payment_id' => $paymentResult->getPaymentId(),
            'nowpayments_purchase_id' => $paymentResult->getPurchaseId(),
            'type' => 'onetime',
            'status' => 'waiting',
            'currency' => $paymentResult->getPriceCurrency(),
            'amount' => $paymentResult->getPriceAmount(),
            'amount_paid' => 0,
            'pay_currency' => $paymentResult->getPayCurrency(),
            'pay_amount' => $paymentResult->getPayAmount(),
            'pay_address' => $paymentResult->getPayAddress(),
            'order_id' => $paymentResult->getOrderId(),
            'order_description' => $paymentResult->getDescription(),
            'metadata' => $paymentResult->getMetadata(),
        ]);

        $payment->save();

        // Fire event
        \SerenityTechnologies\CashierNowPayments\Events\PaymentCreated::dispatch(
            $billable ?? $customer,
            $customer,
            new \stdClass() // API response wrapper
        );

        return $payment;
    }

    /**
     * Generate a QR code URI for a payment address.
     *
     * @param string $address The crypto address
     * @param float $amount The crypto amount
     * @return string
     */
    public function generateQrCodeUri(string $address, float $amount): string
    {
        return sprintf('crypto:%s?amount=%s', $address, $amount);
    }

    /**
     * Get the default webhook URL.
     *
     * @return string
     */
    protected function getDefaultWebhookUrl(): string
    {
        $path = config('cashier-nowpayments.webhook.path', '/nowpayments/webhook');
        return rtrim(config('app.url', 'http://localhost'), '/') . $path;
    }

    /**
     * Generate a unique order ID.
     *
     * @return string
     */
    protected function generateOrderId(): string
    {
        return 'ORDER-' . \Illuminate\Support\Str::ulid()->toString();
    }

    /**
     * Generate a unique session ID.
     *
     * @return string
     */
    protected function generateSessionId(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }
}

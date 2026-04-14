<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use SerenityTechnologies\CashierNowPayments\Events\PaymentCreated;
use SerenityTechnologies\CashierNowPayments\Models\Customer;
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use SerenityTechnologies\NowPayments\DTOs\Request\PaymentRequest;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class PaymentBuilder
{
    /**
     * The billable model.
     */
    protected Model $billable;

    /**
     * The customer model.
     */
    protected Customer $customer;

    /**
     * The payment amount.
     */
    protected float $amount;

    /**
     * The payment currency.
     */
    protected string $currency;

    /**
     * The payment description.
     */
    protected ?string $description = null;

    /**
     * The internal order ID.
     */
    protected ?string $orderId = null;

    /**
     * The cryptocurrency to pay with.
     */
    protected ?string $payCurrency = null;

    /**
     * Whether to use fixed rate.
     */
    protected ?bool $fixedRate = null;

    /**
     * Whether the fee is paid by the user.
     */
    protected ?bool $feePaidByUser = null;

    /**
     * Whether to apply available credits automatically.
     */
    protected bool $applyCredits = false;

    /**
     * Additional metadata.
     */
    protected array $metadata = [];

    /**
     * Redirect URL after payment.
     */
    protected ?string $redirectUrl = null;

    /**
     * Create a new payment builder instance.
     */
    public function __construct(Model $billable, Customer $customer, float $amount, string $currency)
    {
        $this->billable = $billable;
        $this->customer = $customer;
        $this->amount = $amount;
        $this->currency = $currency;
    }

    /**
     * Set the payment description.
     */
    public function withDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the internal order ID.
     */
    public function withOrderId(string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * Set the cryptocurrency to pay with.
     */
    public function withPayCurrency(string $currency): self
    {
        $this->payCurrency = $currency;

        return $this;
    }

    /**
     * Enable fixed rate.
     */
    public function withFixedRate(bool $fixed = true): self
    {
        $this->fixedRate = $fixed;

        return $this;
    }

    /**
     * Enable fee paid by user.
     */
    public function withFeePaidByUser(bool $paidByUser = true): self
    {
        $this->feePaidByUser = $paidByUser;

        return $this;
    }

    /**
     * Set additional metadata.
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Enable automatic credit application before charging.
     *
     * When enabled, available customer credits will be consumed
     * in FIFO order to reduce the charge amount before creating
     * the payment on NOWPayments.
     */
    public function withCredits(bool $apply = true): self
    {
        $this->applyCredits = $apply;

        return $this;
    }

    /**
     * Set redirect URL after payment.
     */
    public function withRedirectUrl(string $url): self
    {
        $this->redirectUrl = $url;

        return $this;
    }

    /**
     * Create the payment via NOWPayments API.
     */
    public function create(): \SerenityTechnologies\NowPayments\DTOs\Response\PaymentResponse
    {
        if ($this->amount <= 0) {
            throw new \InvalidArgumentException('Payment amount must be greater than zero.');
        }

        if (trim($this->currency) === '') {
            throw new \InvalidArgumentException('Payment currency is required.');
        }

        // Apply credits if enabled
        $creditApplied = false;
        if ($this->applyCredits) {
            $creditResult = $this->customer->applyCredits($this->amount);
            $remaining = (float) $creditResult['remaining'];
            if ($remaining < $this->amount && $remaining > 0) {
                $this->amount = $remaining;
                $creditApplied = true;
                $this->metadata['credits_applied'] = $creditResult['covered'];
            } elseif ((float) $creditResult['covered'] >= $this->amount) {
                // Fully covered by credits — still create payment for tracking
                // but amount is effectively zero
                $this->metadata['credits_applied'] = $creditResult['covered'];
            }
        }

        $request = new PaymentRequest(
            priceAmount: $this->amount,
            priceCurrency: $this->currency,
            payCurrency: $this->payCurrency ?? config('cashier-nowpayments.currency', 'usd'),
            ipnCallbackUrl: route('cashier-nowpayments.webhook'),
            orderId: $this->orderId ?? 'ORDER-' . \Illuminate\Support\Str::ulid()->toString(),
            orderDescription: $this->description,
            isFixedRate: $this->fixedRate,
            isFeePaidByUser: $this->feePaidByUser,
        );

        $response = NowPayments::createPayment($request);

        if ($creditApplied) {
            $this->metadata['original_amount'] = $this->amount;
        }

        PaymentCreated::dispatch($this->billable, $this->customer, $response);

        return $response;
    }

    /**
     * Create and persist the payment.
     * @throws \Throwable
     */
    public function charge(): Payment
    {
        return DB::transaction(function () {
            $response = $this->create();
            return $this->persistPayment($response);
        });
    }

    /**
     * Persist the payment to the database.
     */
    protected function persistPayment(\SerenityTechnologies\NowPayments\DTOs\Response\PaymentResponse $response): Payment
    {
        $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);

        /** @var Payment $payment */
        $payment = new $paymentModel();

        $payment->fill([
            'customer_id' => $this->customer->id,
            'billable_id' => $this->billable->getKey(),
            'billable_type' => $this->billable->getMorphClass(),
            'nowpayments_payment_id' => (string) $response->payment_id,
            'nowpayments_purchase_id' => (string) $response->purchase_id,
            'parent_payment_id' => $response->parent_payment_id !== null ? (string) $response->parent_payment_id : null,
            'type' => 'onetime',
            'status' => $response->payment_status,
            'currency' => $response->price_currency,
            'amount' => $response->price_amount,
            'amount_paid' => $response->actually_paid,
            'pay_currency' => $response->pay_currency,
            'pay_amount' => $response->pay_amount,
            'pay_address' => $response->pay_address,
            'order_id' => $response->order_id,
            'order_description' => $response->order_description,
            'payin_hash' => $response->payin_hash,
            'payout_hash' => $response->payout_hash,
            'fee' => $response->fee !== null ? (array) $response->fee : null,
            'metadata' => $this->metadata,
        ]);

        $payment->save();

        return $payment;
    }
}

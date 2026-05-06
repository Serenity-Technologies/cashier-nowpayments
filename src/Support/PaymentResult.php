<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments\Support;

/**
 * Payment Result
 *
 * Represents the result of a successful payment creation.
 * Contains all information needed to display payment details to the customer.
 */
class PaymentResult
{
    /**
     * NOWPayments payment ID.
     */
    protected string $paymentId;

    /**
     * NOWPayments purchase ID.
     */
    protected string $purchaseId;

    /**
     * The crypto payment address.
     */
    protected string $payAddress;

    /**
     * The crypto amount to pay.
     */
    protected float $payAmount;

    /**
     * The crypto currency code.
     */
    protected string $payCurrency;

    /**
     * The fiat price amount.
     */
    protected float $priceAmount;

    /**
     * The fiat currency code.
     */
    protected string $priceCurrency;

    /**
     * The order ID.
     */
    protected string $orderId;

    /**
     * The order description.
     */
    protected ?string $description;

    /**
     * QR code URI (crypto:address?amount=...).
     */
    protected string $qrCodeUri;

    /**
     * Payment expiration time (ISO 8601).
     */
    protected string $expirationTime;

    /**
     * Additional metadata.
     */
    protected array $metadata;

    /**
     * Create a new payment result.
     */
    public function __construct(
        string $paymentId,
        string $purchaseId,
        string $payAddress,
        float $payAmount,
        string $payCurrency,
        float $priceAmount,
        string $priceCurrency,
        string $orderId,
        ?string $description,
        string $qrCodeUri,
        string $expirationTime,
        array $metadata = []
    ) {
        $this->paymentId = $paymentId;
        $this->purchaseId = $purchaseId;
        $this->payAddress = $payAddress;
        $this->payAmount = $payAmount;
        $this->payCurrency = $payCurrency;
        $this->priceAmount = $priceAmount;
        $this->priceCurrency = $priceCurrency;
        $this->orderId = $orderId;
        $this->description = $description;
        $this->qrCodeUri = $qrCodeUri;
        $this->expirationTime = $expirationTime;
        $this->metadata = $metadata;
    }

    /**
     * Get the payment ID.
     */
    public function getPaymentId(): string
    {
        return $this->paymentId;
    }

    /**
     * Get the purchase ID.
     */
    public function getPurchaseId(): string
    {
        return $this->purchaseId;
    }

    /**
     * Get the payment address.
     */
    public function getPayAddress(): string
    {
        return $this->payAddress;
    }

    /**
     * Get the pay amount.
     */
    public function getPayAmount(): float
    {
        return $this->payAmount;
    }

    /**
     * Get the pay currency.
     */
    public function getPayCurrency(): string
    {
        return $this->payCurrency;
    }

    /**
     * Get the price amount.
     */
    public function getPriceAmount(): float
    {
        return $this->priceAmount;
    }

    /**
     * Get the price currency.
     */
    public function getPriceCurrency(): string
    {
        return $this->priceCurrency;
    }

    /**
     * Get the order ID.
     */
    public function getOrderId(): string
    {
        return $this->orderId;
    }

    /**
     * Get the description.
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Get the QR code URI.
     */
    public function getQrCodeUri(): string
    {
        return $this->qrCodeUri;
    }

    /**
     * Get the expiration time.
     */
    public function getExpirationTime(): string
    {
        return $this->expirationTime;
    }

    /**
     * Get metadata.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get minutes until expiration.
     */
    public function getMinutesUntilExpiration(): int
    {
        $now = new \DateTime();
        $expiration = new \DateTime($this->expirationTime);
        return max(0, (int) $now->diff($expiration)->format('%i') + ($now->diff($expiration)->format('%h') * 60));
    }

    /**
     * Check if payment has expired.
     */
    public function isExpired(): bool
    {
        return now()->gt(new \DateTime($this->expirationTime));
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'payment_id' => $this->paymentId,
            'purchase_id' => $this->purchaseId,
            'pay_address' => $this->payAddress,
            'pay_amount' => $this->payAmount,
            'pay_currency' => $this->payCurrency,
            'price_amount' => $this->priceAmount,
            'price_currency' => $this->priceCurrency,
            'order_id' => $this->orderId,
            'description' => $this->description,
            'qr_code_uri' => $this->qrCodeUri,
            'expiration_time' => $this->expirationTime,
            'minutes_until_expiration' => $this->getMinutesUntilExpiration(),
            'metadata' => $this->metadata,
        ];
    }
}

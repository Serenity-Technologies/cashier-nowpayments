<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Support;

/**
 * Invoice Result
 *
 * Represents the result of a successful invoice creation.
 * Contains all information needed to redirect customer to invoice page.
 */
class InvoiceResult
{
    /**
     * NOWPayments invoice ID.
     */
    protected string $invoiceId;

    /**
     * The hosted invoice URL.
     */
    protected string $invoiceUrl;

    /**
     * The order ID.
     */
    protected string $orderId;

    /**
     * The order description.
     */
    protected ?string $description;

    /**
     * The invoice amount.
     */
    protected float $amount;

    /**
     * The currency code.
     */
    protected string $currency;

    /**
     * Success redirect URL.
     */
    protected string $successUrl;

    /**
     * Cancel redirect URL.
     */
    protected string $cancelUrl;

    /**
     * Invoice expiration time (ISO 8601).
     */
    protected ?string $expiresAt;

    /**
     * Create a new invoice result.
     */
    public function __construct(
        string $invoiceId,
        string $invoiceUrl,
        string $orderId,
        ?string $description,
        float $amount,
        string $currency,
        string $successUrl,
        string $cancelUrl,
        ?string $expiresAt = null
    ) {
        $this->invoiceId = $invoiceId;
        $this->invoiceUrl = $invoiceUrl;
        $this->orderId = $orderId;
        $this->description = $description;
        $this->amount = $amount;
        $this->currency = $currency;
        $this->successUrl = $successUrl;
        $this->cancelUrl = $cancelUrl;
        $this->expiresAt = $expiresAt;
    }

    /**
     * Get the invoice ID.
     */
    public function getInvoiceId(): string
    {
        return $this->invoiceId;
    }

    /**
     * Get the invoice URL.
     */
    public function getInvoiceUrl(): string
    {
        return $this->invoiceUrl;
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
     * Get the amount.
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * Get the currency.
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Get the success URL.
     */
    public function getSuccessUrl(): string
    {
        return $this->successUrl;
    }

    /**
     * Get the cancel URL.
     */
    public function getCancelUrl(): string
    {
        return $this->cancelUrl;
    }

    /**
     * Get the expiration time.
     */
    public function getExpiresAt(): ?string
    {
        return $this->expiresAt;
    }

    /**
     * Check if invoice has expired.
     */
    public function isExpired(): bool
    {
        if ($this->expiresAt === null) {
            return false;
        }

        return now()->gt(new \DateTime($this->expiresAt));
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'invoice_id' => $this->invoiceId,
            'invoice_url' => $this->invoiceUrl,
            'order_id' => $this->orderId,
            'description' => $this->description,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
            'expires_at' => $this->expiresAt,
        ];
    }
}

<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Support;

/**
 * Checkout Session
 *
 * Represents a single checkout session with all payment parameters.
 */
class CheckoutSession
{
    /**
     * Session data.
     */
    protected array $data;

    /**
     * Create a new checkout session.
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Get the session ID.
     */
    public function getId(): string
    {
        return $this->data['id'];
    }

    /**
     * Get the payment amount.
     */
    public function getAmount(): float
    {
        return $this->data['amount'];
    }

    /**
     * Get the currency code.
     */
    public function getCurrency(): string
    {
        return $this->data['currency'];
    }

    /**
     * Get the payment description.
     */
    public function getDescription(): ?string
    {
        return $this->data['description'] ?? null;
    }

    /**
     * Get the order ID.
     */
    public function getOrderId(): ?string
    {
        return $this->data['order_id'] ?? null;
    }

    /**
     * Get the success URL.
     */
    public function getSuccessUrl(): string
    {
        return $this->data['success_url'];
    }

    /**
     * Get the cancel URL.
     */
    public function getCancelUrl(): string
    {
        return $this->data['cancel_url'];
    }

    /**
     * Get the selected payment currency.
     */
    public function getPayCurrency(): ?string
    {
        return $this->data['pay_currency'] ?? null;
    }

    /**
     * Check if fixed rate is enabled.
     */
    public function hasFixedRate(): bool
    {
        return (bool) ($this->data['fixed_rate'] ?? false);
    }

    /**
     * Check if fee is paid by user.
     */
    public function isFeePaidByUser(): bool
    {
        return (bool) ($this->data['fee_paid_by_user'] ?? false);
    }

    /**
     * Get metadata.
     */
    public function getMetadata(): array
    {
        return $this->data['metadata'] ?? [];
    }

    /**
     * Get the session creation time.
     */
    public function getCreatedAt(): string
    {
        return $this->data['created_at'];
    }

    /**
     * Get the session expiration time.
     */
    public function getExpiresAt(): string
    {
        return $this->data['expires_at'];
    }

    /**
     * Check if the session has expired.
     */
    public function isExpired(): bool
    {
        return now()->gt(new \DateTime($this->getExpiresAt()));
    }

    /**
     * Get all session data as array.
     */
    public function toArray(): array
    {
        return $this->data;
    }
}

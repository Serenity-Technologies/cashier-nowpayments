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
 * Validation Result
 *
 * Represents the result of a payment amount validation.
 */
class ValidationResult
{
    /**
     * Whether the amount is valid.
     */
    protected bool $valid;

    /**
     * The original amount.
     */
    protected float $amount;

    /**
     * The estimated crypto amount.
     */
    protected float $estimatedAmount;

    /**
     * The minimum required amount.
     */
    protected float $minimumAmount;

    /**
     * The fiat currency code.
     */
    protected string $currency;

    /**
     * The crypto currency code.
     */
    protected string $payCurrency;

    /**
     * Validation errors.
     */
    protected array $errors;

    /**
     * Create a new validation result.
     */
    public function __construct(
        bool $valid,
        float $amount,
        float $estimatedAmount,
        float $minimumAmount,
        string $currency,
        string $payCurrency,
        array $errors = []
    ) {
        $this->valid = $valid;
        $this->amount = $amount;
        $this->estimatedAmount = $estimatedAmount;
        $this->minimumAmount = $minimumAmount;
        $this->currency = $currency;
        $this->payCurrency = $payCurrency;
        $this->errors = $errors;
    }

    /**
     * Check if the amount is valid.
     */
    public function isValid(): bool
    {
        return $this->valid;
    }

    /**
     * Get the original amount.
     */
    public function getAmount(): float
    {
        return $this->amount;
    }

    /**
     * Get the estimated crypto amount.
     */
    public function getEstimatedAmount(): float
    {
        return $this->estimatedAmount;
    }

    /**
     * Get the minimum required amount.
     */
    public function getMinimumAmount(): float
    {
        return $this->minimumAmount;
    }

    /**
     * Get the fiat currency.
     */
    public function getCurrency(): string
    {
        return $this->currency;
    }

    /**
     * Get the crypto currency.
     */
    public function getPayCurrency(): string
    {
        return $this->payCurrency;
    }

    /**
     * Get validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get the first validation error.
     */
    public function getFirstError(): ?string
    {
        return $this->errors[0] ?? null;
    }
}

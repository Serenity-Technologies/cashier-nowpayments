<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Support;

/**
 * Estimate Result
 *
 * Represents the result of a currency conversion estimate.
 */
class EstimateResult
{
    /**
     * Estimate data.
     */
    protected array $data;

    /**
     * Create a new estimate result.
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * Get the estimated crypto amount.
     */
    public function getEstimatedAmount(): float
    {
        return $this->data['estimated_amount'];
    }

    /**
     * Get the estimated fee.
     */
    public function getFee(): ?float
    {
        return $this->data['fee'] ?? null;
    }

    /**
     * Get the source currency.
     */
    public function getFromCurrency(): string
    {
        return $this->data['from_currency'];
    }

    /**
     * Get the target currency.
     */
    public function getToCurrency(): string
    {
        return $this->data['to_currency'];
    }

    /**
     * Get the original fiat amount.
     */
    public function getAmount(): float
    {
        return $this->data['amount'];
    }

    /**
     * Format the estimated amount with currency.
     */
    public function getFormattedEstimatedAmount(): string
    {
        return sprintf('%.8f %s', $this->getEstimatedAmount(), strtoupper($this->getToCurrency()));
    }

    /**
     * Get all data as array.
     */
    public function toArray(): array
    {
        return $this->data;
    }
}

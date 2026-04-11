<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Exceptions;

use Exception;

/**
 * Checkout Exception
 *
 * Thrown when a checkout operation fails.
 */
class CheckoutException extends Exception
{
    /**
     * Create a new checkout exception for API failures.
     */
    public static function apiError(string $message, \Throwable $previous = null): self
    {
        return new self('NOWPayments API Error: ' . $message, 0, $previous);
    }

    /**
     * Create a new checkout exception for validation failures.
     */
    public static function validationError(string $message): self
    {
        return new self('Validation Error: ' . $message, 422);
    }

    /**
     * Create a new checkout exception for session expiration.
     */
    public static function sessionExpired(): self
    {
        return new self('Checkout session has expired. Please start a new checkout.', 410);
    }

    /**
     * Create a new checkout exception for minimum amount violations.
     */
    public static function belowMinimum(float $amount, string $currency, float $minimum): self
    {
        return new self(
            "Amount {$amount} {$currency} is below the minimum payment amount of {$minimum} {$currency}.",
            422
        );
    }

    /**
     * Create a new checkout exception for currency unavailability.
     */
    public static function currencyUnavailable(string $currency): self
    {
        return new self("The currency '{$currency}' is not available for payments.", 400);
    }
}

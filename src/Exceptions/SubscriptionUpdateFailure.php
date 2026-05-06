<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments\Exceptions;

use Exception;

class SubscriptionUpdateFailure extends Exception
{
    /**
     * Create a new exception for invalid plan.
     */
    public static function invalidPlan(string $message = 'The subscription plan is invalid.'): self
    {
        return new static($message);
    }

    /**
     * Create a new exception for quantity exceeded.
     */
    public static function quantityExceeded(int $quantity = 0): self
    {
        return new static("Subscription quantity cannot exceed available limits (requested: {$quantity}).");
    }

    /**
     * Create a new exception for subscription not cancellable.
     */
    public static function notCancellable(): self
    {
        return new static('The subscription cannot be cancelled in its current state.');
    }

    /**
     * Create a new exception for subscription not resumable.
     */
    public static function notResumable(): self
    {
        return new static('The subscription cannot be resumed in its current state.');
    }
}

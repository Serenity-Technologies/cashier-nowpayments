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

class InvalidCustomer extends Exception
{
    /**
     * Create a new exception for customer not created.
     */
    public static function notCreated(string $message = 'Customer record could not be created.'): self
    {
        return new static($message);
    }

    /**
     * Create a new exception for customer not found.
     */
    public static function notFound(string $customerId): self
    {
        return new static("Customer with ID {$customerId} not found.");
    }
}

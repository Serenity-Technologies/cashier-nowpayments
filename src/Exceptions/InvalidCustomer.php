<?php

declare(strict_types=1);

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

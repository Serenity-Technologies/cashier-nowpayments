<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SerenityTechnologies\CashierNowPayments\Models\Customer;

/**
 * Provides a standard customer relationship for models owned by a customer.
 *
 * Models using this trait must implement `getCustomerModel(): string`
 * to return the customer model class name.
 */
trait BelongsToCustomer
{
    /**
     * Get the customer that owns this record.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo($this->getCustomerModel());
    }

    /**
     * Get the customer model class.
     *
     * @return class-string<Customer>
     */
    abstract protected function getCustomerModel(): string;
}

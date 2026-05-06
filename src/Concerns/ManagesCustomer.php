<?php
/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use Illuminate\Database\Eloquent\Relations\MorphOne;
use SerenityTechnologies\CashierNowPayments\Models\Customer;

trait ManagesCustomer
{
    /**
     * Get the customer record for the billable model.
     */
    public function customer(): MorphOne
    {
        return $this->morphOne($this->getCustomerModel(), 'billable');
    }

    /**
     * Get or create the customer record for the billable model.
     */
    public function createOrGetCustomer(array $attributes = []): Customer
    {
        if ($this->relationLoaded('customer') && $this->customer !== null) {
            return $this->customer;
        }

        $this->load('customer');

        if ($this->customer !== null) {
            return $this->customer;
        }

        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');

        /** @var Customer $customer */
        $customer = $this->customer()->create(array_merge([
            'nowpayments_customer_id' => $prefix . $this->getKey(),
            'email' => $this->getBillableEmail(),
            'name' => $this->getBillableName(),
        ], $attributes));

        return $customer;
    }

    /**
     * Mark the billable model as a customer with NOWPayments data.
     */
    public function markAsCustomer(array $nowpaymentsData): Customer
    {
        $customer = $this->createOrGetCustomer();

        $customer->update([
            'nowpayments_customer_id' => $nowpaymentsData['customer_id'] ?? $customer->nowpayments_customer_id,
            'metadata' => array_merge(
                $customer->metadata ?? [],
                $nowpaymentsData
            ),
        ]);

        return $customer;
    }

    /**
     * Get the billable model's email.
     */
    protected function getBillableEmail(): ?string
    {
        return $this->email ?? null;
    }

    /**
     * Get the billable model's name.
     */
    protected function getBillableName(): ?string
    {
        return $this->name ?? $this->first_name ?? null;
    }

    /**
     * Get the customer model class.
     */
    protected function getCustomerModel(): string
    {
        return config('cashier-nowpayments.model.customer', Customer::class);
    }
}

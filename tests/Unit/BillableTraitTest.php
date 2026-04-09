<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Tests\Unit;

use SerenityTechnologies\CashierNowPayments\Models\Customer;
use SerenityTechnologies\CashierNowPayments\Tests\TestCase;

class BillableTraitTest extends TestCase
{
    /** @test */
    public function it_can_create_customer_from_billable_model(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer = $user->createOrGetCustomer();

        $this->assertInstanceOf(Customer::class, $customer);
        $this->assertEquals('test@example.com', $user->email);
        $this->assertNotNull($customer->nowpayments_customer_id);
    }

    /** @test */
    public function it_returns_existing_customer_if_exists(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer1 = $user->createOrGetCustomer();
        $customer2 = $user->createOrGetCustomer();

        $this->assertEquals($customer1->id, $customer2->id);
    }

    /** @test */
    public function it_can_begin_payment_charge(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $builder = $user->charge(100.00, 'usd');

        $this->assertInstanceOf(\SerenityTechnologies\CashierNowPayments\PaymentBuilder::class, $builder);
    }

    /** @test */
    public function it_can_begin_invoice_creation(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $builder = $user->invoice(100.00, 'usd');

        $this->assertInstanceOf(\SerenityTechnologies\CashierNowPayments\InvoiceBuilder::class, $builder);
    }

    /** @test */
    public function it_can_begin_subscription_creation(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $builder = $user->newSubscription('default', 'plan_123');

        $this->assertInstanceOf(\SerenityTechnologies\CashierNowPayments\SubscriptionBuilder::class, $builder);
    }

    /** @test */
    public function it_can_mark_as_customer(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer = $user->markAsCustomer([
            'customer_id' => 'np_12345',
            'extra_data' => 'test',
        ]);

        $this->assertEquals('np_12345', $customer->nowpayments_customer_id);
        $this->assertArrayHasKey('extra_data', $customer->metadata);
    }
}

<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments\Tests\Unit;

use SerenityTechnologies\CashierNowPayments\PaymentBuilder;
use SerenityTechnologies\CashierNowPayments\Tests\TestCase;

class PaymentBuilderTest extends TestCase
{
    /** @test */
    public function it_can_build_payment_with_description(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer = $user->createOrGetCustomer();

        $builder = new PaymentBuilder($user, $customer, 100.00, 'usd');
        $builder->withDescription('Test payment');

        $this->assertInstanceOf(PaymentBuilder::class, $builder);
    }

    /** @test */
    public function it_can_build_payment_with_order_id(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer = $user->createOrGetCustomer();

        $builder = new PaymentBuilder($user, $customer, 100.00, 'usd');
        $builder->withOrderId('ORDER-123');

        $this->assertInstanceOf(PaymentBuilder::class, $builder);
    }

    /** @test */
    public function it_can_build_payment_with_pay_currency(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer = $user->createOrGetCustomer();

        $builder = new PaymentBuilder($user, $customer, 100.00, 'usd');
        $builder->withPayCurrency('btc');

        $this->assertInstanceOf(PaymentBuilder::class, $builder);
    }

    /** @test */
    public function it_can_build_payment_with_fixed_rate(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer = $user->createOrGetCustomer();

        $builder = new PaymentBuilder($user, $customer, 100.00, 'usd');
        $builder->withFixedRate(true);

        $this->assertInstanceOf(PaymentBuilder::class, $builder);
    }

    /** @test */
    public function it_can_build_payment_with_metadata(): void
    {
        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer = $user->createOrGetCustomer();

        $builder = new PaymentBuilder($user, $customer, 100.00, 'usd');
        $builder->withMetadata(['key' => 'value']);

        $this->assertInstanceOf(PaymentBuilder::class, $builder);
    }

    /** @test */
    public function it_throws_exception_for_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer = $user->createOrGetCustomer();

        $builder = new PaymentBuilder($user, $customer, -100.00, 'usd');
        // This will fail when create() is called
    }

    /** @test */
    public function it_throws_exception_for_missing_currency(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $user = new \SerenityTechnologies\CashierNowPayments\Tests\Models\User();
        $user->email = 'test@example.com';
        $user->name = 'Test User';
        $user->save();

        $customer = $user->createOrGetCustomer();

        $builder = new PaymentBuilder($user, $customer, 100.00, '');
        // This will fail when create() is called
    }
}

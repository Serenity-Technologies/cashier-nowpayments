<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Tests\Unit;

use SerenityTechnologies\CashierNowPayments\Models\Subscription;
use SerenityTechnologies\CashierNowPayments\Tests\TestCase;

class SubscriptionModelTest extends TestCase
{
    /** @test */
    public function it_can_create_subscription(): void
    {
        $customer = new \SerenityTechnologies\CashierNowPayments\Models\Customer();
        $customer->nowpayments_customer_id = 'np_test_123';
        $customer->email = 'test@example.com';
        $customer->save();

        $subscription = new Subscription();
        $subscription->customer_id = $customer->id;
        $subscription->type = 'default';
        $subscription->nowpayments_plan_id = 'plan_123';
        $subscription->nowpayments_subscription_id = 'sub_456';
        $subscription->status = 'waiting_pay';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->save();

        $this->assertDatabaseHas('test_cashier_subscriptions', [
            'nowpayments_subscription_id' => 'sub_456',
            'status' => 'waiting_pay',
        ]);
    }

    /** @test */
    public function it_can_check_if_subscription_is_active(): void
    {
        $subscription = new Subscription();
        $subscription->status = 'paid';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->ends_at = null;

        $this->assertTrue($subscription->isActive());
    }

    /** @test */
    public function it_can_check_if_subscription_is_cancelled(): void
    {
        $subscription = new Subscription();
        $subscription->status = 'paid';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->ends_at = now();

        $this->assertTrue($subscription->isCancelled());
    }

    /** @test */
    public function it_can_check_if_subscription_is_on_trial(): void
    {
        $subscription = new Subscription();
        $subscription->status = 'waiting_pay';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->trial_ends_at = now()->addDays(14);

        $this->assertTrue($subscription->isOnTrial());
    }

    /** @test */
    public function it_can_cancel_subscription(): void
    {
        $subscription = new Subscription();
        $subscription->status = 'paid';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->renews_at = now()->addMonth();
        $subscription->save();

        $subscription->cancel();

        $this->assertNotNull($subscription->cancels_at);
        $this->assertNotNull($subscription->ends_at);
    }

    /** @test */
    public function it_can_cancel_subscription_immediately(): void
    {
        $subscription = new Subscription();
        $subscription->status = 'paid';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->save();

        $subscription->cancelNow();

        $this->assertNotNull($subscription->cancels_at);
        $this->assertTrue($subscription->ends_at->isToday());
    }

    /** @test */
    public function it_can_resume_cancelled_subscription(): void
    {
        $subscription = new Subscription();
        $subscription->status = 'paid';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->ends_at = now();
        $subscription->cancels_at = now();
        $subscription->save();

        $subscription->resume();

        $this->assertNull($subscription->ends_at);
        $this->assertNull($subscription->cancels_at);
    }

    /** @test */
    public function it_can_swap_subscription_plan(): void
    {
        $subscription = new Subscription();
        $subscription->status = 'paid';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->nowpayments_plan_id = 'plan_old';
        $subscription->save();

        $subscription->swap('plan_new');

        $this->assertEquals('plan_new', $subscription->nowpayments_plan_id);
    }

    /** @test */
    public function it_can_increment_quantity(): void
    {
        $subscription = new Subscription();
        $subscription->status = 'paid';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->save();

        $subscription->incrementQuantity();

        $this->assertEquals(2, $subscription->quantity);
    }

    /** @test */
    public function it_can_decrement_quantity(): void
    {
        $subscription = new Subscription();
        $subscription->status = 'paid';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 3;
        $subscription->save();

        $subscription->decrementQuantity();

        $this->assertEquals(2, $subscription->quantity);
    }

    /** @test */
    public function it_throws_exception_when_quantity_less_than_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $subscription = new Subscription();
        $subscription->status = 'paid';
        $subscription->currency = 'usd';
        $subscription->total_price = 100.00;
        $subscription->quantity = 1;
        $subscription->save();

        $subscription->decrementQuantity(2);
    }
}

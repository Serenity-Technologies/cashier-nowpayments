<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Tests\Unit;

use SerenityTechnologies\CashierNowPayments\Models\Payment;
use SerenityTechnologies\CashierNowPayments\Tests\TestCase;

class PaymentModelTest extends TestCase
{
    /** @test */
    public function it_can_create_payment(): void
    {
        $customer = new \SerenityTechnologies\CashierNowPayments\Models\Customer();
        $customer->nowpayments_customer_id = 'np_test_123';
        $customer->email = 'test@example.com';
        $customer->save();

        $payment = new Payment();
        $payment->customer_id = $customer->id;
        $payment->nowpayments_payment_id = '123456';
        $payment->nowpayments_purchase_id = '789';
        $payment->type = 'onetime';
        $payment->status = 'waiting';
        $payment->currency = 'usd';
        $payment->amount = 100.00;
        $payment->amount_paid = 0;
        $payment->pay_currency = 'btc';
        $payment->pay_amount = 0.0025;
        $payment->pay_address = 'bc1qtest';
        $payment->order_id = 'ORDER-123';
        $payment->save();

        $this->assertDatabaseHas('test_cashier_payments', [
            'nowpayments_payment_id' => '123456',
            'status' => 'waiting',
        ]);
    }

    /** @test */
    public function it_can_check_if_payment_is_successful(): void
    {
        $payment = new Payment();
        $payment->status = 'finished';
        $payment->currency = 'usd';
        $payment->amount = 100.00;
        $payment->amount_paid = 100.00;

        $this->assertTrue($payment->isSuccessful());
        $this->assertFalse($payment->isPending());
        $this->assertFalse($payment->isFailed());
    }

    /** @test */
    public function it_can_check_if_payment_is_pending(): void
    {
        $payment = new Payment();
        $payment->status = 'waiting';
        $payment->currency = 'usd';
        $payment->amount = 100.00;
        $payment->amount_paid = 0;

        $this->assertFalse($payment->isSuccessful());
        $this->assertTrue($payment->isPending());
        $this->assertFalse($payment->isFailed());
    }

    /** @test */
    public function it_can_check_if_payment_is_failed(): void
    {
        $payment = new Payment();
        $payment->status = 'failed';
        $payment->currency = 'usd';
        $payment->amount = 100.00;
        $payment->amount_paid = 0;

        $this->assertFalse($payment->isSuccessful());
        $this->assertFalse($payment->isPending());
        $this->assertTrue($payment->isFailed());
    }

    /** @test */
    public function it_can_check_if_payment_is_refunded(): void
    {
        $payment = new Payment();
        $payment->status = 'refunded';
        $payment->currency = 'usd';
        $payment->amount = 100.00;
        $payment->amount_paid = 100.00;
        $payment->refunded_at = now();

        $this->assertTrue($payment->isRefunded());
    }

    /** @test */
    public function it_can_scope_successful_payments(): void
    {
        $payment1 = new Payment();
        $payment1->status = 'finished';
        $payment1->currency = 'usd';
        $payment1->amount = 100.00;
        $payment1->amount_paid = 100.00;
        $payment1->save();

        $payment2 = new Payment();
        $payment2->status = 'waiting';
        $payment2->currency = 'usd';
        $payment2->amount = 50.00;
        $payment2->amount_paid = 0;
        $payment2->save();

        $successfulPayments = Payment::successful()->get();

        $this->assertCount(1, $successfulPayments);
        $this->assertEquals('finished', $successfulPayments->first()->status);
    }
}

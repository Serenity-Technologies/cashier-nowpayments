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

use SerenityTechnologies\CashierNowPayments\Models\Invoice;
use SerenityTechnologies\CashierNowPayments\Tests\TestCase;

class InvoiceModelTest extends TestCase
{
    /** @test */
    public function it_can_create_invoice(): void
    {
        $customer = new \SerenityTechnologies\CashierNowPayments\Models\Customer();
        $customer->nowpayments_customer_id = 'np_test_123';
        $customer->email = 'test@example.com';
        $customer->save();

        $invoice = new Invoice();
        $invoice->customer_id = $customer->id;
        $invoice->nowpayments_invoice_id = '123456';
        $invoice->status = 'active';
        $invoice->currency = 'usd';
        $invoice->amount = 100.00;
        $invoice->amount_paid = 0;
        $invoice->order_id = 'INV-123';
        $invoice->invoice_url = 'https://nowpayments.io/invoice/123456';
        $invoice->save();

        $this->assertDatabaseHas('test_cashier_invoices', [
            'nowpayments_invoice_id' => '123456',
            'status' => 'active',
        ]);
    }

    /** @test */
    public function it_can_check_if_invoice_is_paid(): void
    {
        $invoice = new Invoice();
        $invoice->status = 'paid';
        $invoice->currency = 'usd';
        $invoice->amount = 100.00;
        $invoice->amount_paid = 100.00;

        $this->assertTrue($invoice->isPaid());
        $this->assertFalse($invoice->isActive());
    }

    /** @test */
    public function it_can_check_if_invoice_is_active(): void
    {
        $invoice = new Invoice();
        $invoice->status = 'active';
        $invoice->currency = 'usd';
        $invoice->amount = 100.00;
        $invoice->amount_paid = 0;

        $this->assertTrue($invoice->isActive());
        $this->assertFalse($invoice->isPaid());
    }

    /** @test */
    public function it_can_check_if_invoice_is_expired(): void
    {
        $invoice = new Invoice();
        $invoice->status = 'active';
        $invoice->currency = 'usd';
        $invoice->amount = 100.00;
        $invoice->amount_paid = 0;
        $invoice->expires_at = now()->subDay();

        $this->assertTrue($invoice->isExpired());
    }

    /** @test */
    public function it_can_check_if_invoice_is_not_expired(): void
    {
        $invoice = new Invoice();
        $invoice->status = 'active';
        $invoice->currency = 'usd';
        $invoice->amount = 100.00;
        $invoice->amount_paid = 0;
        $invoice->expires_at = now()->addDay();

        $this->assertFalse($invoice->isExpired());
    }
}

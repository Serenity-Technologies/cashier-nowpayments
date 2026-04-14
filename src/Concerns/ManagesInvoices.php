<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use Illuminate\Database\Eloquent\Relations\HasMany;
use SerenityTechnologies\CashierNowPayments\Models\Invoice;
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use SerenityTechnologies\CashierNowPayments\InvoiceBuilder;
use SerenityTechnologies\NowPayments\DTOs\Request\InvoicePaymentRequest;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

trait ManagesInvoices
{
    /**
     * Begin creating a new invoice.
     */
    public function invoice(float $amount, string $currency): InvoiceBuilder
    {
        $customer = $this->createOrGetCustomer();

        return new InvoiceBuilder($this, $customer, $amount, $currency);
    }

    /**
     * Get all invoices for the billable model.
     */
    public function invoices(): HasMany
    {
        $customer = $this->customer ?? $this->createOrGetCustomer();

        return $customer->invoices();
    }

    /**
     * Create a payment for an existing invoice.
     */
    public function payInvoice(Invoice $invoice, string $payCurrency, ?string $payoutAddress = null): Payment
    {
        $request = new InvoicePaymentRequest(
            iid: (int) $invoice->nowpayments_invoice_id,
            payCurrency: $payCurrency,
            orderDescription: $invoice->order_description,
            customerEmail: $invoice->customer->email,
            payoutAddress: $payoutAddress
        );

        $response = NowPayments::createInvoicePayment($request);

        $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);

        /** @var Payment $payment */
        $payment = new $paymentModel();

        $payment->fill([
            'customer_id' => $invoice->customer_id,
            'billable_id' => $this->getKey(),
            'billable_type' => $this->getMorphClass(),
            'nowpayments_payment_id' => (string) $response->payment_id,
            'nowpayments_purchase_id' => (string) $response->purchase_id,
            'type' => 'invoice',
            'status' => $response->payment_status,
            'currency' => $response->price_currency,
            'amount' => $response->price_amount,
            'amount_paid' => $response->actually_paid,
            'pay_currency' => $response->pay_currency,
            'pay_amount' => $response->pay_amount,
            'pay_address' => $response->pay_address,
            'order_id' => $response->order_id,
            'order_description' => $response->order_description,
        ]);

        $payment->save();

        return $payment;
    }
}

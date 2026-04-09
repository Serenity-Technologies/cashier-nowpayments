<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments;

use Illuminate\Database\Eloquent\Model;
use SerenityTechnologies\CashierNowPayments\Events\InvoiceCreated;
use SerenityTechnologies\CashierNowPayments\Models\Customer;
use SerenityTechnologies\CashierNowPayments\Models\Invoice;
use SerenityTechnologies\CashierNowPayments\Support\GeneratesWebhookUrl;
use SerenityTechnologies\NowPayments\DTOs\Request\InvoiceRequest;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class InvoiceBuilder
{
    use GeneratesWebhookUrl;
    /**
     * The billable model.
     */
    protected Model $billable;

    /**
     * The customer model.
     */
    protected Customer $customer;

    /**
     * The invoice amount.
     */
    protected float $amount;

    /**
     * The invoice currency.
     */
    protected string $currency;

    /**
     * The invoice description.
     */
    protected ?string $description = null;

    /**
     * The internal order ID.
     */
    protected ?string $orderId = null;

    /**
     * Success URL after payment.
     */
    protected ?string $successUrl = null;

    /**
     * Cancel URL after payment.
     */
    protected ?string $cancelUrl = null;

    /**
     * Additional metadata.
     */
    protected array $metadata = [];

    /**
     * Whether to use fixed rate.
     */
    protected ?bool $fixedRate = null;

    /**
     * Create a new invoice builder instance.
     */
    public function __construct(Model $billable, Customer $customer, float $amount, string $currency)
    {
        $this->billable = $billable;
        $this->customer = $customer;
        $this->amount = $amount;
        $this->currency = $currency;
    }

    /**
     * Set the invoice description.
     */
    public function withDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Set the internal order ID.
     */
    public function withOrderId(string $orderId): self
    {
        $this->orderId = $orderId;

        return $this;
    }

    /**
     * Set the success URL.
     */
    public function withSuccessUrl(string $url): self
    {
        $this->successUrl = $url;

        return $this;
    }

    /**
     * Set the cancel URL.
     */
    public function withCancelUrl(string $url): self
    {
        $this->cancelUrl = $url;

        return $this;
    }

    /**
     * Set additional metadata.
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Enable fixed rate.
     */
    public function withFixedRate(bool $fixed = true): self
    {
        $this->fixedRate = $fixed;

        return $this;
    }

    /**
     * Create the invoice via NOWPayments API.
     */
    public function create(): \SerenityTechnologies\NowPayments\DTOs\Response\InvoiceResponse
    {
        if ($this->amount <= 0) {
            throw new \InvalidArgumentException('Invoice amount must be greater than zero.');
        }

        if (trim($this->currency) === '') {
            throw new \InvalidArgumentException('Invoice currency is required.');
        }

        $request = new InvoiceRequest(
            priceAmount: $this->amount,
            priceCurrency: $this->currency,
            ipnCallbackUrl: $this->getWebhookUrl(),
            orderId: $this->orderId ?? 'INV-' . \Illuminate\Support\Str::ulid()->toString(),
            orderDescription: $this->description,
            successUrl: $this->successUrl,
            cancelUrl: $this->cancelUrl,
            isFixedRate: $this->fixedRate,
        );

        $response = NowPayments::createInvoice($request);

        InvoiceCreated::dispatch($this->billable, $this->customer, $response);

        return $response;
    }

    /**
     * Create and persist the invoice.
     */
    public function generate(): Invoice
    {
        $response = $this->create();

        $invoice = $this->persistInvoice($response);

        return $invoice;
    }

    /**
     * Persist the invoice to the database.
     */
    protected function persistInvoice(\SerenityTechnologies\NowPayments\DTOs\Response\InvoiceResponse $response): Invoice
    {
        $invoiceModel = config('cashier-nowpayments.model.invoice', Invoice::class);

        /** @var Invoice $invoice */
        $invoice = new $invoiceModel();

        $invoice->fill([
            'customer_id' => $this->customer->id,
            'billable_id' => $this->billable->getKey(),
            'billable_type' => $this->billable->getMorphClass(),
            'nowpayments_invoice_id' => $response->id,
            'status' => $response->payment_status ?? 'active',
            'currency' => $response->price_currency,
            'amount' => $response->price_amount,
            'amount_paid' => 0,
            'order_id' => $response->order_id,
            'order_description' => $response->order_description,
            'invoice_url' => $response->invoice_url ?? null,
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
            'metadata' => $this->metadata,
        ]);

        $invoice->save();

        return $invoice;
    }
}

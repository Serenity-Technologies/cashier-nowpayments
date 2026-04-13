<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use SerenityTechnologies\NowPayments\DTOs\Response\PaymentResponse;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

/**
 * Represents an invoice generated via NOWPayments.
 *
 * Invoices are one-time payment requests that can be sent to customers.
 * They track payment URLs, status, and can have multiple associated payments.
 *
 * @property string $id The ULID primary key
 * @property string $customer_id The owning customer's ULID
 * @property string $billable_type The owning billable model type
 * @property int|string $billable_id The owning billable model ID
 * @property string|null $nowpayments_invoice_id The NOWPayments invoice identifier
 * @property string $status The invoice status (e.g., active, paid, expired, finished)
 * @property string|null $currency The invoice currency
 * @property string $amount The total invoice amount
 * @property string $amount_paid The amount already paid
 * @property string|null $order_id The order identifier
 * @property string|null $order_description Description of the order
 * @property string|null $invoice_url URL to the invoice payment page
 * @property string|null $success_url Redirect URL after successful payment
 * @property string|null $cancel_url Redirect URL after cancelled payment
 * @property array|null $metadata Additional JSON metadata
 * @property \Carbon\Carbon|null $paid_at Timestamp when the invoice was paid
 * @property \Carbon\Carbon|null $expires_at Timestamp when the invoice expires
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 *
 * @property-read Customer $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<self>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereId(string $id)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereCustomerId(string $customerId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNowPaymentsInvoiceId(string $nowpaymentsInvoiceId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereStatus(string $status)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereOrderId(string $orderId)
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class Invoice extends Model
{
    use HasFactory, HasUlids;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'paid_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Get the table name for the model.
     *
     * @return string The fully qualified table name with configured prefix
     */
    public function getTable(): string
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return $prefix . 'invoices';
    }

    /**
     * Get the customer that owns the invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo($this->getCustomerModel());
    }

    /**
     * Get the owning billable model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the payments for the invoice.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany($this->getPaymentModel(), 'order_id', 'order_id');
    }

    /**
     * Determine if the invoice is paid.
     *
     * @return bool True if status is 'paid' or 'finished'
     */
    public function isPaid(): bool
    {
        return in_array($this->status, ['paid', 'finished'], true);
    }

    /**
     * Determine if the invoice is active.
     *
     * @return bool True if status is 'active'
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Determine if the invoice is expired.
     *
     * @return bool True if expires_at is in the past or status is 'expired'
     */
    public function isExpired(): bool
    {
        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return true;
        }

        return $this->status === 'expired';
    }

    /**
     * Redirect to the invoice payment page.
     *
     * @return \Illuminate\Http\RedirectResponse Redirect response to the invoice URL
     */
    public function redirect(): RedirectResponse
    {
        return redirect($this->invoice_url);
    }

    /**
     * Create a payment for this invoice.
     *
     * Uses NOWPayments' createInvoicePayment API to generate a
     * crypto payment address for the given invoice.
     *
     * @param string $payCurrency The cryptocurrency to pay with (e.g., 'btc', 'eth')
     * @param string|null $payoutAddress Optional payout address for refunds
     * @return PaymentResponse
     * @throws \SerenityTechnologies\NowPayments\Exceptions\NowPaymentsException
     */
    public function pay(string $payCurrency, ?string $payoutAddress = null): PaymentResponse
    {
        if ($this->nowpayments_invoice_id === null) {
            throw new \InvalidArgumentException('Cannot pay invoice: missing NOWPayments invoice ID.');
        }

        $request = new \SerenityTechnologies\NowPayments\DTOs\Request\InvoicePaymentRequest(
            iid: $this->nowpayments_invoice_id,
            payCurrency: $payCurrency,
            orderDescription: $this->order_description,
            customerEmail: $this->customer->email,
            payoutAddress: $payoutAddress,
        );

        return NowPayments::createInvoicePayment($request);
    }

    /**
     * Get the customer model class.
     *
     * @return class-string<Customer>
     */
    protected function getCustomerModel(): string
    {
        return config('cashier-nowpayments.model.customer', Customer::class);
    }

    /**
     * Get the payment model class.
     *
     * @return class-string<Payment>
     */
    protected function getPaymentModel(): string
    {
        return config('cashier-nowpayments.model.payment', Payment::class);
    }
}

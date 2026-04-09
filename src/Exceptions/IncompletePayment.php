<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Exceptions;

use Exception;
use SerenityTechnologies\CashierNowPayments\Models\Payment;

class IncompletePayment extends Exception
{
    /**
     * The payment instance.
     */
    public Payment $payment;

    /**
     * Create a new IncompletePayment instance.
     */
    public function __construct(Payment $payment, string $message = 'Payment is incomplete.')
    {
        parent::__construct($message);

        $this->payment = $payment;
    }

    /**
     * Create a new IncompletePayment from a payment instance.
     */
    public static function create(Payment $payment): self
    {
        return new static(
            $payment,
            "Payment {$payment->nowpayments_payment_id} is incomplete (status: {$payment->status})."
        );
    }
}

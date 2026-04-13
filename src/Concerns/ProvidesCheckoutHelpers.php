<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use Illuminate\Support\HtmlString;
use SerenityTechnologies\CashierNowPayments\Services\CheckoutService;

trait ProvidesCheckoutHelpers
{
    /**
     * Get the checkout service instance.
     */
    public function checkout(): CheckoutService
    {
        return app(CheckoutService::class);
    }

    /**
     * Generate a checkout button that opens the overlay.
     *
     * When no custom JavaScript handler is provided, returns a plain button
     * with data attributes that the consuming application can bind to.
     *
     * @param float $amount
     * @param string $currency
     * @param array $options
     * @return HtmlString
     */
    public function checkoutButton(float $amount, string $currency, array $options = []): HtmlString
    {
        $attributes = json_encode(array_merge([
            'amount' => $amount,
            'currency' => $currency,
            'success_url' => config('app.url'),
            'cancel_url' => config('app.url'),
        ], $options));

        $buttonText = e($options['text'] ?? 'Pay with Crypto');
        $buttonClass = e($options['class'] ?? 'cashier-nowpayments-checkout-btn');
        $route = route('cashier-nowpayments.checkout');

        // Return a plain button with data attributes — no inline script dependency
        $html = <<<HTML
<a href="{$route}?amount={$amount}&currency={$currency}" class="{$buttonClass}" data-cashier-nowpayments data-config="{$attributes}">
    {$buttonText}
</a>
HTML;

        return new HtmlString($html);
    }

    /**
     * Generate a checkout URL for direct redirect.
     *
     * @param float $amount
     * @param string $currency
     * @param array $options
     * @return string
     */
    public function checkoutUrl(float $amount, string $currency, array $options = []): string
    {
        $params = http_build_query(array_merge([
            'amount' => $amount,
            'currency' => $currency,
        ], $options));

        return route('cashier-nowpayments.checkout') . '?' . $params;
    }

    /**
     * Generate an embedded checkout URL with NOWPayments payment widget.
     *
     * @param float $amount
     * @param string $currency
     * @param array $options
     * @return string
     */
    public function embeddedCheckoutUrl(float $amount, string $currency, array $options = []): string
    {
        $params = http_build_query(array_merge([
            'amount' => $amount,
            'currency' => $currency,
            'success_url' => config('app.url'),
            'cancel_url' => config('app.url'),
        ], $options));

        return route('cashier-nowpayments.checkout.embedded') . '?' . $params;
    }

    /**
     * Generate a URL to pay an existing invoice using the NOWPayments widget.
     *
     * Opens the checkout overlay with the embedded payment widget rendered
     * directly. The widget handles currency selection, QR codes, and payment tracking.
     *
     * @param \SerenityTechnologies\CashierNowPayments\Models\Invoice $invoice
     * @param array $options
     * @return string
     */
    public function payInvoiceUrl(\SerenityTechnologies\CashierNowPayments\Models\Invoice $invoice, array $options = []): string
    {
        $params = http_build_query(array_merge([
            'amount' => $invoice->amount,
            'currency' => $invoice->currency,
            'type' => 'invoice_payment',
            'invoice_id' => $invoice->nowpayments_invoice_id ?? $invoice->id,
            'description' => $invoice->order_description ?? "Invoice #{$invoice->order_id}",
            'order_id' => $invoice->order_id,
            'success_url' => $invoice->success_url ?? config('app.url'),
            'cancel_url' => $invoice->cancel_url ?? config('app.url'),
        ], $options));

        return route('cashier-nowpayments.checkout') . '?' . $params;
    }
}

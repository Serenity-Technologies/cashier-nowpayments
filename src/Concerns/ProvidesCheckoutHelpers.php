<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use Illuminate\Support\HtmlString;

trait ProvidesCheckoutHelpers
{
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
}

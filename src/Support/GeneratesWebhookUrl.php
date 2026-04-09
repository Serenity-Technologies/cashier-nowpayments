<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Support;

trait GeneratesWebhookUrl
{
    /**
     * Get the webhook URL for IPN callbacks.
     */
    protected function getWebhookUrl(): string
    {
        $path = config('cashier-nowpayments.webhook.path', '/nowpayments/webhook');

        return rtrim(config('app.url', 'http://localhost'), '/') . $path;
    }
}

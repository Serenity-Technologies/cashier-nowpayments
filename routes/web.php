<?php
/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use SerenityTechnologies\CashierNowPayments\Http\Controllers\{CheckoutController};
use SerenityTechnologies\CashierNowPayments\Http\Controllers\PaymentStatusController;

/*
|--------------------------------------------------------------------------
| Cashier NOWPayments Routes
|--------------------------------------------------------------------------
|
| These routes are responsible for handling checkout overlay, payment
| creation, and payment status checking. All routes are protected
| by the configured middleware group (default: 'web').
|
| Configuration: config('cashier-nowpayments.routes.middleware')
|
*/

Route::prefix(config('cashier-nowpayments.routes.prefix', 'cashier-nowpayments'))
    ->name(config('cashier-nowpayments.routes.name', 'cashier-nowpayments.'))
    ->middleware(config('cashier-nowpayments.routes.middleware', ['web']))
    ->group(function () {
        // Checkout overlay (custom UI)
        Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout');

        // Embedded checkout with NOWPayments payment widget
        Route::get('/checkout/embedded', [CheckoutController::class, 'showEmbedded'])->name('checkout.embedded');

        // Create payment via AJAX (rate limited to prevent abuse)
        Route::post('/checkout/payment', [CheckoutController::class, 'createPayment'])
            ->middleware(['throttle:30,1'])
            ->name('checkout.payment');

        // Create invoice and redirect
        Route::post('/checkout/invoice', [CheckoutController::class, 'createInvoice'])
            ->middleware(['throttle:20,1'])
            ->name('checkout.invoice');

        // Create payment for an invoice
        Route::post('/checkout/invoice/{invoiceId}/pay', [CheckoutController::class, 'payInvoice'])
            ->middleware(['throttle:30,1'])
            ->name('checkout.invoice.pay');

        // Create subscription and redirect
        Route::post('/checkout/subscription', [CheckoutController::class, 'createSubscription'])
            ->middleware(['throttle:10,1'])
            ->name('checkout.subscription');

        // Get supported currencies
        Route::get('/checkout/currencies', [CheckoutController::class, 'getSupportedCurrencies'])
            ->name('checkout.currencies');

        // Get payment estimate (rate limited to prevent API abuse)
        Route::post('/checkout/estimate', [CheckoutController::class, 'getEstimate'])
            ->middleware(['throttle:60,1'])
            ->name('checkout.estimate');

        // Check payment status (for polling) — rate limited + auth configurable
        Route::get('/payment/status/{paymentId}', [PaymentStatusController::class, 'check'])
            ->middleware(['throttle:30,1', 'nowpayments.payment.auth'])
            ->name('payment.status');

        // Check local payment status — rate limited + auth configurable
        Route::get('/payment/local/{paymentId}', [PaymentStatusController::class, 'checkLocal'])
            ->middleware(['throttle:30,1', 'nowpayments.payment.auth'])
            ->name('payment.local');
    });

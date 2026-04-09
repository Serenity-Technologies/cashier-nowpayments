<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\View\View;
use SerenityTechnologies\CashierNowPayments\Models\Customer;
use SerenityTechnologies\CashierNowPayments\Models\Invoice;
use SerenityTechnologies\NowPayments\DTOs\Request\{EstimateRequest, InvoiceRequest, MinAmountRequest, SubscriptionRequest};
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class CheckoutController extends Controller
{
    /**
     * Maximum number of retries for transient NOWPayments API failures.
     */
    protected const MAX_RETRIES = 3;

    /**
     * Delay (ms) between retries — exponential backoff: 500ms, 1000ms.
     */
    protected const RETRY_DELAY_MS = 500;

    /**
     * Display the checkout overlay.
     */
    public function show(Request $request): View
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string',
            'type' => 'sometimes|string|in:payment,invoice,subscription',
            'description' => 'sometimes|string|max:500',
            'order_id' => 'sometimes|string|max:255',
            'metadata' => 'sometimes|array',
            'success_url' => 'sometimes|url',
            'cancel_url' => 'sometimes|url',
            'pay_currency' => 'sometimes|string',
        ]);

        $checkoutData = [
            'amount' => (float) $validated['amount'],
            'currency' => $validated['currency'],
            'type' => $validated['type'] ?? config('cashier-nowpayments.payment_method', 'payment'),
            'description' => $validated['description'] ?? null,
            'order_id' => $validated['order_id'] ?? null,
            'metadata' => $validated['metadata'] ?? [],
            'success_url' => $validated['success_url'] ?? config('app.url'),
            'cancel_url' => $validated['cancel_url'] ?? config('app.url'),
            'pay_currency' => $validated['pay_currency'] ?? null,
            // Server-side timeout so the frontend timer matches
            'timeout_seconds' => config('cashier-nowpayments.checkout.payment_timeout_seconds', 900),
        ];

        return view('cashier-nowpayments::checkout', compact('checkoutData'));
    }

    /**
     * Create a payment and return payment details.
     */
    public function createPayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string',
            'pay_currency' => 'required|string',
            'description' => 'sometimes|string|max:500',
            'order_id' => 'sometimes|string|max:255',
            'success_url' => 'sometimes|url',
            'cancel_url' => 'sometimes|url',
        ]);

        // Always generate a unique order ID to prevent collisions,
        // even when the client supplies one — we prefix it.
        $uniqueSuffix = Str::ulid()->toString();
        $clientOrderId = $validated['order_id'] ?? null;
        $orderId = $clientOrderId !== null
            ? "CLIENT-{$clientOrderId}-{$uniqueSuffix}"
            : "CHECKOUT-{$uniqueSuffix}";

        // Generate idempotency key to prevent duplicate payments
        $idempotencyKey = $this->generateIdempotencyKey($request, $validated);

        try {
            // Check for existing payment within idempotency window (5 minutes)
            $cachedPayment = Cache::get('checkout.payment.' . $idempotencyKey);
            if ($cachedPayment !== null) {
                return response()->json($cachedPayment);
            }

            // Get estimate first
            $estimate = $this->withRetry(fn() => NowPayments::getEstimate(new EstimateRequest(
                currencyFrom: $validated['currency'],
                currencyTo: $validated['pay_currency'],
                amount: $validated['amount'],
            )));

            // Check minimum payment amount using precise comparison
            $minAmount = $this->withRetry(fn() => NowPayments::getMinAmount(new MinAmountRequest(
                currencyFrom: $validated['currency'],
                currencyTo: $validated['pay_currency'],
            )));

            if (bccomp((string) $estimate->estimated_amount, (string) $minAmount->min_amount, 8) < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount is below minimum payment requirement.',
                    'minimum' => $minAmount->min_amount,
                ], 422);
            }

            // Use PaymentBuilder for ALL checkouts (authenticated and guest)
            // This prevents double payment creation
            $billable = $request->user() ?? $this->getOrCreateGuestCustomer($request);

            // Cache billable mapping for webhook reconciliation
            if ($billable !== null && $orderId !== null) {
                Cache::put('checkout.billable.' . $orderId, [
                    'billable_type' => $billable->getMorphClass(),
                    'billable_id' => $billable->getKey(),
                ], now()->addHours(24));
            }

            $localPayment = $billable->charge($validated['amount'], $validated['currency'])
                ->withPayCurrency($validated['pay_currency'])
                ->withDescription($validated['description'] ?? '')
                ->withOrderId($orderId)
                ->charge();

            $response = [
                'success' => true,
                'payment_id' => $localPayment->nowpayments_payment_id,
                'purchase_id' => $localPayment->nowpayments_purchase_id,
                'pay_address' => $localPayment->pay_address,
                'pay_amount' => $localPayment->pay_amount,
                'pay_currency' => $localPayment->pay_currency,
                'price_amount' => $localPayment->amount,
                'price_currency' => $localPayment->currency,
                'qr_code' => $this->generateQRCode($localPayment->pay_address, (float) $localPayment->pay_amount),
                'payment_url' => null,
                'local_payment_id' => $localPayment->id,
                'timeout_seconds' => config('cashier-nowpayments.checkout.payment_timeout_seconds', 900),
            ];

            // Cache response for idempotency (5 minutes)
            Cache::put('checkout.payment.' . $idempotencyKey, $response, now()->addMinutes(5));

            return response()->json($response);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment. Please try again.',
            ], 500);
        }
    }

    /**
     * Create an invoice and redirect to payment page.
     */
    public function createInvoice(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string',
            'description' => 'sometimes|string|max:500',
            'order_id' => 'sometimes|string|max:255',
            'success_url' => 'required|url',
            'cancel_url' => 'required|url',
        ]);

        try {
            $invoice = $this->withRetry(function () use ($validated) {
                $invoiceRequest = new InvoiceRequest(
                    priceAmount: $validated['amount'],
                    priceCurrency: $validated['currency'],
                    ipnCallbackUrl: route('cashier-nowpayments.webhook'),
                    orderId: $validated['order_id'] ?? 'INVOICE-' . Str::ulid()->toString(),
                    orderDescription: $validated['description'] ?? null,
                    successUrl: $validated['success_url'],
                    cancelUrl: $validated['cancel_url'],
                    isFixedRate: config('cashier-nowpayments.fixed_rate', false),
                );

                return NowPayments::createInvoice($invoiceRequest);
            });

            // Store invoice locally for ALL users (authenticated + guest)
            $this->persistInvoice($request, $invoice);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'invoice_url' => $invoice->invoice_url,
                    'invoice_id' => $invoice->id,
                ]);
            }

            return redirect($invoice->invoice_url);
        } catch (\Exception $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create invoice.',
                ], 500);
            }

            return redirect()->back()->with('error', 'Failed to create invoice.');
        }
    }

    /**
     * Create a subscription and redirect to payment page.
     */
    public function createSubscription(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'plan_id' => 'required|integer',
            'success_url' => 'required|url',
            'cancel_url' => 'required|url',
        ]);

        $billable = $request->user();

        if ($billable === null) {
            return response()->json([
                'success' => false,
                'message' => 'Subscription checkout requires authentication.',
            ], 401);
        }

        try {
            $subscription = $this->withRetry(function () use ($validated) {
                $subscriptionRequest = new SubscriptionRequest(
                    subscriptionPlanId: $validated['plan_id'],
                );

                return NowPayments::createSubscription($subscriptionRequest);
            });

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'subscription_id' => $subscription->id,
                    'subscription_url' => $subscription->subscription_url ?? null,
                ]);
            }

            $redirectUrl = $subscription->subscription_url ?? config('app.url');

            return redirect($redirectUrl);
        } catch (\Exception $e) {
            report($e);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to create subscription.',
                ], 500);
            }

            return redirect()->back()->with('error', 'Failed to create subscription.');
        }
    }

    /**
     * Get supported currencies for checkout.
     */
    public function getSupportedCurrencies(): JsonResponse
    {
        try {
            $currencies = Cache::remember(
                'nowpayments.currencies.available',
                now()->addHour(),
                function () {
                    $response = NowPayments::getAvailableCurrencies();
                    return $response->currencies ?? [];
                }
            );

            return response()->json([
                'success' => true,
                'currencies' => $currencies,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to load currencies.',
            ], 500);
        }
    }

    /**
     * Get estimate for payment amount.
     */
    public function getEstimate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'from_currency' => 'required|string',
            'to_currency' => 'required|string',
        ]);

        try {
            $estimate = $this->withRetry(fn() => NowPayments::getEstimate(new EstimateRequest(
                currencyFrom: $validated['from_currency'],
                currencyTo: $validated['to_currency'],
                amount: $validated['amount'],
            )));

            $minAmount = $this->withRetry(fn() => NowPayments::getMinAmount(new MinAmountRequest(
                currencyFrom: $validated['from_currency'],
                currencyTo: $validated['to_currency'],
            )));

            return response()->json([
                'success' => true,
                'estimated_amount' => $estimate->estimated_amount,
                'minimum_amount' => $minAmount->min_amount,
                'fee' => $estimate->fee_estimated ?? null,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to get estimate.',
            ], 500);
        }
    }

    /**
     * Generate QR code data URI for payment address.
     *
     * Returns the raw payment URI. The frontend renders it as a QR code
     * using the built-in qrcode.js library loaded in the checkout view.
     */
    protected function generateQRCode(string $address, float $amount): string
    {
        return sprintf('crypto:%s?amount=%s', $address, $amount);
    }

    /**
     * Generate an idempotency key from request parameters.
     *
     * Always includes a server-generated ULID component to prevent
     * collisions even when identical requests come from different tabs.
     */
    protected function generateIdempotencyKey(Request $request, array $validated): string
    {
        $userId = $request->user()?->getKey() ?? 'guest';
        $params = [
            'user' => $userId,
            'amount' => $validated['amount'],
            'currency' => $validated['currency'],
            'pay_currency' => $validated['pay_currency'],
            'order_id' => $validated['order_id'] ?? '',
            'session' => $request->session()->getId(),
        ];

        return 'checkout.idempotency.' . hash('sha256', json_encode($params));
    }

    /**
     * Persist an invoice locally for both authenticated and guest users.
     */
    protected function persistInvoice(Request $request, object $invoice): void
    {
        $invoiceModel = config('cashier-nowpayments.model.invoice', Invoice::class);

        if ($request->user()) {
            // Authenticated — use the billable's invoice builder
            $request->user()->invoice($invoice->price_amount, $invoice->price_currency)
                ->withDescription($invoice->order_description ?? '')
                ->withOrderId($invoice->order_id)
                ->withSuccessUrl($invoice->success_url ?? '')
                ->withCancelUrl($invoice->cancel_url ?? '')
                ->generate();

            return;
        }

        // Guest — create a minimal invoice record tied to guest customer
        $customer = $this->getOrCreateGuestCustomer($request);

        /** @var Invoice $localInvoice */
        $localInvoice = new $invoiceModel();
        $localInvoice->fill([
            'customer_id' => $customer->id,
            'nowpayments_invoice_id' => $invoice->id,
            'status' => $invoice->payment_status ?? 'active',
            'currency' => $invoice->price_currency,
            'amount' => $invoice->price_amount,
            'amount_paid' => 0,
            'order_id' => $invoice->order_id,
            'order_description' => $invoice->order_description,
            'invoice_url' => $invoice->invoice_url ?? null,
            'success_url' => $invoice->success_url ?? null,
            'cancel_url' => $invoice->cancel_url ?? null,
            'metadata' => ['source' => 'guest_checkout'],
        ]);
        $localInvoice->save();
    }

    /**
     * Get or create a guest customer for unauthenticated checkout.
     */
    protected function getOrCreateGuestCustomer(Request $request): mixed
    {
        $customerModel = config('cashier-nowpayments.model.customer', Customer::class);

        $sessionKey = $request->session()->getId();

        /** @var Customer|null $customer */
        $customer = $customerModel::whereJsonContains('metadata->session_key', $sessionKey)
            ->whereNull('billable_id')
            ->first();

        if ($customer === null) {
            $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');

            /** @var Customer $customer */
            $customer = new $customerModel();
            $customer->fill([
                'nowpayments_customer_id' => $prefix . 'guest_' . $sessionKey,
                'email' => null,
                'name' => 'Guest User',
                'metadata' => [
                    'session_key' => $sessionKey,
                    'source' => 'guest_checkout',
                ],
            ]);
            $customer->save();
        }

        return $customer->billable ?? $customer;
    }

    /**
     * Execute a callback with retry logic for transient API failures.
     *
     * Uses exponential backoff: 500ms, 1000ms between attempts.
     */
    protected function withRetry(callable $callback, int $maxRetries = self::MAX_RETRIES): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (\Throwable $e) {
                $attempt++;

                if ($attempt > $maxRetries) {
                    throw $e;
                }

                // Only retry on transient errors (connection timeouts, etc.)
                $message = strtolower($e->getMessage());
                $isTransient = str_contains($message, 'connection')
                    || str_contains($message, 'timeout')
                    || str_contains($message, 'curl')
                    || str_contains($message, 'stream');

                if (!$isTransient) {
                    throw $e;
                }

                usleep(self::RETRY_DELAY_MS * 1000 * (2 ** ($attempt - 1)));
            }
        }
    }
}

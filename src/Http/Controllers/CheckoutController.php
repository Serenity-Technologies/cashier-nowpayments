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
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use SerenityTechnologies\NowPayments\DTOs\Response\SubscriptionResponse;
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
            'type' => 'sometimes|string|in:payment,invoice,subscription,invoice_payment',
            'description' => 'sometimes|string|max:500',
            'order_id' => 'sometimes|string|max:255',
            'metadata' => 'sometimes|array',
            'success_url' => 'sometimes|url',
            'cancel_url' => 'sometimes|url',
            'pay_currency' => 'sometimes|string',
            'invoice_id' => 'sometimes|string',
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

        // Handle invoice payment flow with embedded widget
        if ($checkoutData['type'] === 'invoice_payment' && !empty($validated['invoice_id'])) {
            $checkoutData['invoice_id'] = $validated['invoice_id'];
            $checkoutData['widget_url'] = 'https://nowpayments.io/embeds/payment-widget?iid=' . $validated['invoice_id'];
        }

        return view('cashier-nowpayments::checkout', compact('checkoutData'));
    }

    /**
     * Display the embedded checkout overlay with NOWPayments payment widget.
     *
     * This creates an invoice and displays it in an embedded iframe widget.
     */
    public function showEmbedded(Request $request): View
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'currency' => 'required|string',
            'description' => 'sometimes|string|max:500',
            'order_id' => 'sometimes|string|max:255',
            'metadata' => 'sometimes|array',
            'success_url' => 'sometimes|url',
            'cancel_url' => 'sometimes|url',
        ]);

        try {
            // Create invoice to get widget URL
            $invoice = $this->withRetry(function () use ($validated) {
                $invoiceRequest = new InvoiceRequest(
                    priceAmount: (float) $validated['amount'],
                    priceCurrency: $validated['currency'],
                    ipnCallbackUrl: route('cashier-nowpayments.webhook'),
                    orderId: $validated['order_id'] ?? 'INV-' . Str::ulid()->toString(),
                    orderDescription: $validated['description'] ?? null,
                    successUrl: $validated['success_url'] ?? config('app.url'),
                    cancelUrl: $validated['cancel_url'] ?? config('app.url'),
                    isFixedRate: config('cashier-nowpayments.fixed_rate', false),
                );

                return NowPayments::createInvoice($invoiceRequest);
            });

            // Store invoice locally for both authenticated and guest users
            $this->persistInvoice($request, $invoice);

            // Build widget URL from invoice URL
            // NOWPayments widget URL format: https://nowpayments.io/embeds/payment-widget?iid={invoice_id}
            $widgetUrl = 'https://nowpayments.io/embeds/payment-widget?iid=' . $invoice->id;

            $checkoutData = [
                'amount' => (float) $validated['amount'],
                'currency' => $validated['currency'],
                'description' => $validated['description'] ?? null,
                'order_id' => $invoice->order_id,
                'metadata' => $validated['metadata'] ?? [],
                'success_url' => $validated['success_url'] ?? config('app.url'),
                'cancel_url' => $validated['cancel_url'] ?? config('app.url'),
                'widget_url' => $widgetUrl,
                'invoice_id' => $invoice->id,
            ];

            return view('cashier-nowpayments::checkout-embedded', compact('checkoutData'));
        } catch (\Exception $e) {
            report($e);

            // Fallback to regular checkout if embedded widget fails
            $checkoutData = [
                'amount' => (float) $validated['amount'],
                'currency' => $validated['currency'],
                'type' => config('cashier-nowpayments.payment_method', 'payment'),
                'description' => $validated['description'] ?? null,
                'order_id' => $validated['order_id'] ?? null,
                'metadata' => $validated['metadata'] ?? [],
                'success_url' => $validated['success_url'] ?? config('app.url'),
                'cancel_url' => $validated['cancel_url'] ?? config('app.url'),
                'pay_currency' => null,
                'timeout_seconds' => config('cashier-nowpayments.checkout.payment_timeout_seconds', 900),
            ];

            return view('cashier-nowpayments::checkout', compact('checkoutData'));
        }
    }

    /**
     * Create a payment and return payment details.
     * @throws \Throwable
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

            // Check minimum payment amount using precise comparison.
            // IMPORTANT: getMinAmount() returns the minimum in the FROM currency (fiat),
            // NOT the TO currency (crypto). So we compare fiat amount against fiat minimum.
            $minAmount = $this->withRetry(fn() => NowPayments::getMinAmount(new MinAmountRequest(
                currencyFrom: $validated['currency'],
                currencyTo: $validated['pay_currency'],
            )));

            // If amount is below minimum, automatically add the difference as a processing fee
            $processingFee = '0';
            $originalAmount = $validated['amount'];
            if (bccomp((string) $originalAmount, (string) $minAmount->min_amount, 8) < 0) {
                // Calculate the difference and add it as processing fee
                $processingFee = bcsub((string) $minAmount->min_amount, (string) $originalAmount, 8);
                $validated['amount'] = bcadd((string) $originalAmount, $processingFee, 8);

                // Update description to include processing fee notice
                $feeDisplay = rtrim(rtrim($processingFee, '0'), '.');
                $validated['description'] = ($validated['description'] ?? '')
                    . ' (incl. $' . $feeDisplay . ' processing fee)';
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

            /** @var Payment $localPayment */
            $localPayment = $billable->newPayment((float) $validated['amount'], $validated['currency'])
                ->withPayCurrency($validated['pay_currency'])
                ->withDescription($validated['description'] ?? '')
                ->withOrderId($orderId)
                ->withMetadata($processingFee !== '0' ? [
                    'original_amount' => $originalAmount,
                    'processing_fee' => $processingFee,
                    'minimum_amount' => $minAmount->min_amount,
                ] : [])
                ->charge();

            $responseData = [
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

            // Include processing fee info if applied
            if ($processingFee !== '0') {
                $responseData['processing_fee'] = $processingFee;
                $responseData['original_amount'] = $originalAmount;
                $responseData['minimum_amount'] = $minAmount->min_amount;
            }

            // Cache response for idempotency (5 minutes)
            Cache::put('checkout.payment.' . $idempotencyKey, $responseData, now()->addMinutes(5));

            return response()->json($responseData);
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
     * Create a payment for an existing invoice.
     *
     * This uses NOWPayments' createInvoicePayment API to generate
     * a crypto payment address for the given invoice.
     *
     * @param string $invoiceId The local invoice ID
     * @param Request $request
     * @return JsonResponse
     */
    public function payInvoice(string $invoiceId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pay_currency' => 'required|string',
            'payout_address' => 'sometimes|string|max:255',
        ]);

        try {
            // Find the local invoice
            $invoiceModel = config('cashier-nowpayments.model.invoice', Invoice::class);
            /** @var Invoice $invoice */
            $invoice = $invoiceModel::findOrFail($invoiceId);

            // Verify ownership if user is authenticated
            if ($request->user() !== null) {
                if ($invoice->billable_id !== $request->user()->getKey()
                    || $invoice->billable_type !== $request->user()->getMorphClass()) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invoice not found or access denied.',
                    ], 403);
                }
            }

            // Validate invoice is active
            if (!$invoice->isActive()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invoice is not active. Status: ' . $invoice->status,
                ], 422);
            }

            // Validate minimum amount
            $minAmount = $this->withRetry(fn() => NowPayments::getMinAmount(new MinAmountRequest(
                currencyFrom: $invoice->currency,
                currencyTo: $validated['pay_currency'],
            )));

            if (bccomp((string) $invoice->amount, (string) $minAmount->min_amount, 8) < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount is below minimum payment requirement.',
                    'minimum' => $minAmount->min_amount,
                ], 422);
            }

            // Create payment for invoice via NOWPayments
            $paymentResponse = $this->withRetry(fn() => NowPayments::createInvoicePayment(
                new \SerenityTechnologies\NowPayments\DTOs\Request\InvoicePaymentRequest(
                    iid: $invoice->nowpayments_invoice_id,
                    payCurrency: $validated['pay_currency'],
                    orderDescription: $invoice->order_description,
                    customerEmail: $request->user()?->email,
                    payoutAddress: $validated['payout_address'] ?? null,
                )
            ));

            // Create local payment record
            $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);
            /** @var Payment $localPayment */
            $localPayment = new $paymentModel();
            $localPayment->fill([
                'customer_id' => $invoice->customer_id,
                'billable_id' => $invoice->billable_id,
                'billable_type' => $invoice->billable_type,
                'nowpayments_payment_id' => (string) $paymentResponse->payment_id,
                'nowpayments_purchase_id' => (string) $paymentResponse->purchase_id,
                'type' => 'invoice',
                'status' => $paymentResponse->payment_status,
                'currency' => $paymentResponse->price_currency,
                'amount' => $paymentResponse->price_amount,
                'amount_paid' => $paymentResponse->actually_paid,
                'pay_currency' => $paymentResponse->pay_currency,
                'pay_amount' => $paymentResponse->pay_amount,
                'pay_address' => $paymentResponse->pay_address,
                'order_id' => $paymentResponse->order_id,
                'order_description' => $paymentResponse->order_description,
                'metadata' => [
                    'invoice_id' => $invoice->id,
                    'nowpayments_invoice_id' => $invoice->nowpayments_invoice_id,
                ],
            ]);
            $localPayment->save();

            // Fire event
            \SerenityTechnologies\CashierNowPayments\Events\PaymentCreated::dispatch(
                $invoice->billable ?? $invoice->customer,
                $invoice->customer,
                $paymentResponse
            );

            return response()->json([
                'success' => true,
                'payment_id' => $localPayment->nowpayments_payment_id,
                'purchase_id' => $localPayment->nowpayments_purchase_id,
                'pay_address' => $localPayment->pay_address,
                'pay_amount' => $localPayment->pay_amount,
                'pay_currency' => $localPayment->pay_currency,
                'price_amount' => $localPayment->amount,
                'price_currency' => $localPayment->currency,
                'qr_code' => $this->generateQRCode($localPayment->pay_address, (float) $localPayment->pay_amount),
                'local_payment_id' => $localPayment->id,
                'timeout_seconds' => config('cashier-nowpayments.checkout.payment_timeout_seconds', 900),
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invoice not found.',
            ], 404);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create invoice payment. Please try again.',
            ], 500);
        }
    }

    /**
     * Create a subscription and redirect to payment page.
     */
    public function createSubscription(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'type' => 'required|string',
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
            /** @var SubscriptionResponse $subscription */
            $subscription = $this->withRetry(function () use ($billable, $validated) {
                return $billable->newSubscription($validated['type'], $validated['plan_id'])
                    ->withMetaData([
                        'success_url' => $validated['success_url'],
                        'cancel_url' => $validated['cancel_url'],
                    ])
                    ->create();
            });



            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'subscription_id' => $subscription->id,
                    'subscription_status' => $subscription->status ?? null,
                ]);
            }


            return redirect()->to('success_url');
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
     * Get supported currencies for checkout with full details.
     */
    public function getSupportedCurrencies(): JsonResponse
    {
        try {
            $currencies = Cache::remember(
                'nowpayments.currencies.detailed',
                now()->addHour(),
                function () {
                    // Get full currency details from NOWPayments API
                    // Returns array of FullCurrencyItemResponse DTOs
                    $fullResponse = NowPayments::getFullCurrencies();
                    $allCurrencies = $fullResponse->currencies ?? [];

                    // Filter to only currencies available for payment
                    $paymentCurrencies = array_filter($allCurrencies, function ($currency) {
                        return !empty($currency->availableForPayment);
                    });

                    // Map to our format using logoUrl from DTO

                    $currencies = array_map(function ($currency) {
                        $baseUrl = 'https://nowpayments.io';
                        $popular = ['btc', 'eth', 'usdttrc20', 'usdterc20', 'ltc', 'trx', 'usdc', 'bnbbsc', 'doge', 'xrp', 'sol', 'ada'];
                        $code = strtolower($currency->code ?? '');
                        $logoUrl = $currency->logoUrl ?? null;

                        return [
                            'code' => $code,
                            'name' => $currency->name ?? strtoupper($code),
                            'ticker' => strtoupper($currency->ticker ?? $code),
                            'network' => $currency->network ?? null,
                            'blockchain' => $currency->network ?? strtoupper($code),
                            'logo' => !empty($logoUrl) ? $baseUrl . $logoUrl : $this->getCurrencyLogoUrl($code),
                            'is_popular' => in_array($code, $popular, true),
                            'is_fiat' => false,
                            'precision' => $currency->precision ?? 8,
                        ];
                    }, $paymentCurrencies);

                    // Sort: popular first, then alphabetical by name
                    usort($currencies, function ($a, $b) {
                        if ($a['is_popular'] !== $b['is_popular']) {
                            return $b['is_popular'] <=> $a['is_popular'];
                        }
                        return $a['name'] <=> $b['name'];
                    });

                    return array_values($currencies);
                }
            );

            return response()->json([
                'success' => true,
                'currencies' => $currencies,
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to load currencies: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get the logo URL for a currency.
     * Uses local images if available, falls back to NOWPayments CDN.
     */
    protected function getCurrencyLogoUrl(string $code): string
    {
        $code = strtolower($code);

        // Check if local image exists in public directory
        $publicPath = public_path('vendor/cashier-nowpayments/coins/' . $code . '.svg');
        $packagePath = __DIR__ . '/../public/coins/' . $code . '.svg';

        if (file_exists($publicPath)) {
            return asset('vendor/cashier-nowpayments/coins/' . $code . '.svg');
        }

        if (file_exists($packagePath)) {
            return asset('vendor/cashier-nowpayments/coins/' . $code . '.svg');
        }

        // Fallback to NOWPayments CDN
        return "https://nowpayments.io/images/coins/{$code}.svg";
    }

    /**
     * Get estimate for payment amount.
     */
    public function getEstimate(Request $request): JsonResponse
    {
        // Manual validation to ensure JSON response on error
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'from_currency' => 'required|string',
            'to_currency' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => $validator->errors()->first(),
            ], 422);
        }

        $validated = $validator->validated();

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

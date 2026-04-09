<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use SerenityTechnologies\CashierNowPayments\Models\Customer;
use SerenityTechnologies\NowPayments\DTOs\Request\{EstimateRequest, InvoiceRequest, MinAmountRequest};
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class CheckoutController extends Controller
{
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

        // Generate idempotency key to prevent duplicate payments
        $idempotencyKey = $this->generateIdempotencyKey($request, $validated);

        try {
            // Check for existing payment within idempotency window (5 minutes)
            $cachedPayment = Cache::get('checkout.payment.' . $idempotencyKey);
            if ($cachedPayment !== null) {
                return response()->json($cachedPayment);
            }

            // Get estimate first
            $estimate = NowPayments::getEstimate(new EstimateRequest(
                currencyFrom: $validated['currency'],
                currencyTo: $validated['pay_currency'],
                amount: $validated['amount'],
            ));

            // Check minimum payment amount using precise comparison
            $minAmount = NowPayments::getMinAmount(new MinAmountRequest(
                currencyFrom: $validated['currency'],
                currencyTo: $validated['pay_currency'],
            ));

            if (bccomp((string) $estimate->estimated_amount, (string) $minAmount->min_amount, 8) < 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Amount is below minimum payment requirement.',
                    'minimum' => $minAmount->min_amount,
                ], 422);
            }

            $orderId = $validated['order_id'] ?? 'CHECKOUT-' . \Illuminate\Support\Str::ulid()->toString();

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
                'payment_url' => null, // PaymentBuilder doesn't return invoice_url for direct payments
                'local_payment_id' => $localPayment->id,
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
            $invoiceRequest = new InvoiceRequest(
                priceAmount: $validated['amount'],
                priceCurrency: $validated['currency'],
                ipnCallbackUrl: route('cashier-nowpayments.webhook'),
                orderId: $validated['order_id'] ?? 'INVOICE-' . \Illuminate\Support\Str::ulid()->toString(),
                orderDescription: $validated['description'] ?? null,
                successUrl: $validated['success_url'],
                cancelUrl: $validated['cancel_url'],
                isFixedRate: config('cashier-nowpayments.fixed_rate', false),
            );

            $invoice = NowPayments::createInvoice($invoiceRequest);

            // Store invoice locally if user is authenticated
            if ($request->user()) {
                $request->user()->invoice($validated['amount'], $validated['currency'])
                    ->withDescription($validated['description'] ?? '')
                    ->withOrderId($invoice->order_id)
                    ->withSuccessUrl($validated['success_url'])
                    ->withCancelUrl($validated['cancel_url'])
                    ->generate();
            }

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
            $estimate = NowPayments::getEstimate(new EstimateRequest(
                currencyFrom: $validated['from_currency'],
                currencyTo: $validated['to_currency'],
                amount: $validated['amount'],
            ));

            $minAmount = NowPayments::getMinAmount(new MinAmountRequest(
                currencyFrom: $validated['from_currency'],
                currencyTo: $validated['to_currency'],
            ));

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
     * Uses a local data URI scheme rather than an external API to avoid
     * leaking payment details to third parties.
     */
    protected function generateQRCode(string $address, float $amount): string
    {
        $uri = sprintf('%s:%s?amount=%s', 'crypto', $address, $amount);

        // Return the URI directly — frontend can use a QR library to render it.
        // For production, install bacon/bacon-qr-code and generate locally:
        // composer require bacon/bacon-qr-code
        return $uri;
    }

    /**
     * Generate an idempotency key from request parameters.
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
        ];

        return 'checkout.idempotency.' . hash('sha256', json_encode($params));
    }

    /**
     * Get or create a guest customer for unauthenticated checkout.
     */
    protected function getOrCreateGuestCustomer(Request $request): mixed
    {
        // For guest users, create a minimal customer record.
        // In production, you may want to tie this to session/cart data.
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

        // Return the billable model associated with the customer (if any)
        // or the customer itself as a fallback for guest checkouts.
        return $customer->billable ?? $customer;
    }
}

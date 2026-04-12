<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class PaymentStatusController extends Controller
{
    /**
     * Check payment status by purchase ID.
     *
     * Uses a short-lived cache to prevent excessive API calls when
     * the frontend polls every few seconds.
     */
    public function check(string $paymentId, Request $request): JsonResponse
    {
        $authConfig = config('cashier-nowpayments.payment_status.auth', []);

        if ($authConfig['enabled'] ?? false) {
            $guard = $authConfig['guard'] ?? null;
            $user = $guard !== null ? $request->user($guard) : $request->user();

            if ($user === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                ], 401);
            }

            // Verify ownership by checking if the user has a payment with this purchase ID
            $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);
            $ownsPayment = $paymentModel::where('billable_type', $user->getMorphClass())
                ->where('billable_id', $user->getKey())
                ->where(function ($query) use ($paymentId) {
                    $query->where('nowpayments_purchase_id', $paymentId)
                        ->orWhere('nowpayments_payment_id', $paymentId);
                })
                ->exists();

            if (!$ownsPayment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Payment not found or access denied.',
                ], 403);
            }
        }

        // Use a short-lived cache to reduce API calls during polling
        $cacheSeconds = (int) config('cashier-nowpayments.payment_status.cache_seconds', 10);
        $cacheKey = "nowpayments.status.remote.{$paymentId}";

        try {
            $cached = Cache::get($cacheKey);
            if ($cached !== null) {
                return response()->json($cached);
            }

            $payment = NowPayments::getPaymentStatus($paymentId);

            $status = match ($payment->payment_status) {
                'finished' => 'completed',
                'failed', 'expired' => 'failed',
                'refunded' => 'refunded',
                'partially_paid' => 'partial',
                default => 'pending',
            };

            $result = [
                'success' => true,
                'status' => $status,
                'payment_status' => $payment->payment_status,
                'actually_paid' => $payment->actually_paid,
                'pay_amount' => $payment->pay_amount,
                'pay_address' => $payment->pay_address,
                'pay_currency' => $payment->pay_currency,
            ];

            Cache::put($cacheKey, $result, now()->addSeconds($cacheSeconds));

            return response()->json($result);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check payment status.',
            ], 500);
        }
    }

    /**
     * Check local payment status.
     *
     * Syncs with NOWPayments only if the payment is pending and the
     * last sync was longer than the configured cooldown period.
     */
    public function checkLocal(string $paymentId, Request $request): JsonResponse
    {
        try {
            $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);

            /** @var Payment $payment */
            $payment = $paymentModel::findOrFail($paymentId);

            // Verify ownership when auth is enabled
            $authConfig = config('cashier-nowpayments.payment_status.auth', []);
            if ($authConfig['enabled'] ?? false) {
                if (!$this->verifyPaymentOwnership($payment, $request)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Payment not found or access denied.',
                    ], 403);
                }
            }

            // Sync with NOWPayments only if pending AND cooldown has elapsed
            if ($payment->isPending()) {
                $cooldownSeconds = (int) config('cashier-nowpayments.checkout.sync_cooldown_seconds', 15);
                $lastSync = $payment->metadata['last_status_sync'] ?? null;

                if ($lastSync === null || now()->diffInSeconds(new \DateTime($lastSync)) > $cooldownSeconds) {
                    $payment->syncStatus();
                    $payment->update([
                        'metadata' => array_merge($payment->metadata ?? [], [
                            'last_status_sync' => now()->toIso8601String(),
                        ]),
                    ]);
                }
            }

            $status = match ($payment->status) {
                'finished' => 'completed',
                'failed', 'expired' => 'failed',
                'refunded' => 'refunded',
                'partially_paid' => 'partial',
                default => 'pending',
            };

            return response()->json([
                'success' => true,
                'status' => $status,
                'payment_status' => $payment->status,
                'amount' => $payment->amount,
                'amount_paid' => $payment->amount_paid,
                'pay_currency' => $payment->pay_currency,
                'pay_amount' => $payment->pay_amount,
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check payment status.',
            ], 500);
        }
    }

    /**
     * Verify that the given payment belongs to the authenticated user.
     */
    protected function verifyPaymentOwnership(Payment $payment, Request $request): bool
    {
        $authConfig = config('cashier-nowpayments.payment_status.auth', []);
        $guard = $authConfig['guard'] ?? null;
        $user = $guard !== null ? $request->user($guard) : $request->user();

        if ($user === null) {
            return false;
        }

        // Check if the payment's billable matches the authenticated user
        if ($payment->billable !== null) {
            return $payment->billable->getKey() === $user->getKey()
                && $payment->billable->getMorphClass() === $user->getMorphClass();
        }

        // Fallback: check through the customer relationship
        $customerModel = config('cashier-nowpayments.model.customer');
        $customer = $customerModel::where('billable_type', $user->getMorphClass())
            ->where('billable_id', $user->getKey())
            ->first();

        if ($customer === null) {
            return false;
        }

        return (string) $payment->customer_id === (string) $customer->id;
    }
}

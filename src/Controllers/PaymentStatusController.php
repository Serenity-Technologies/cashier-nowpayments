<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class PaymentStatusController extends Controller
{
    /**
     * Check payment status by purchase ID.
     */
    public function check(string $purchaseId): JsonResponse
    {
        try {
            $payment = NowPayments::getPaymentStatus($purchaseId);

            $status = match ($payment->payment_status) {
                'finished' => 'completed',
                'failed', 'expired' => 'failed',
                'refunded' => 'refunded',
                'partially_paid' => 'partial',
                default => 'pending',
            };

            return response()->json([
                'success' => true,
                'status' => $status,
                'payment_status' => $payment->payment_status,
                'actually_paid' => $payment->actually_paid,
                'pay_amount' => $payment->pay_amount,
                'pay_address' => $payment->pay_address,
                'pay_currency' => $payment->pay_currency,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to check payment status.',
            ], 500);
        }
    }

    /**
     * Check local payment status.
     */
    public function checkLocal(string $paymentId): JsonResponse
    {
        try {
            $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);

            /** @var Payment $payment */
            $payment = $paymentModel::findOrFail($paymentId);

            // Sync with NOWPayments if needed
            if ($payment->isPending()) {
                $payment->syncStatus();
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
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Payment not found.',
            ], 404);
        }
    }
}

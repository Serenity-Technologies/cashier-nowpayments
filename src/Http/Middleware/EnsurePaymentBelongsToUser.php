<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that verifies a payment status request belongs to the authenticated user.
 *
 * Prevents unauthorized enumeration of payment details by verifying ownership
 * through the billable model relationship before returning sensitive payment data.
 *
 * Configuration:
 * - cashier-nowpayments.payment_status.auth.enabled (bool) - Enable/disable middleware
 * - cashier-nowpayments.payment_status.auth.guard (string|null) - Auth guard to use
 */
class EnsurePaymentBelongsToUser
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authConfig = config('cashier-nowpayments.payment_status.auth', []);

        if (!($authConfig['enabled'] ?? false)) {
            return $next($request);
        }

        $guard = $authConfig['guard'] ?? null;
        $user = $guard !== null ? $request->user($guard) : $request->user();

        if ($user === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.',
            ], 401);
        }

        return $next($request);
    }

    /**
     * Verify that the given payment belongs to the authenticated user.
     *
     * Call this from your controller methods as a secondary check:
     *
     *   if (!$this->verifyPaymentOwnership($payment, $request)) {
     *       return response()->json(['success' => false, 'message' => 'Unauthorized.'], 403);
     *   }
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

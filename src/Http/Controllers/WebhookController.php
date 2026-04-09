<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use SerenityTechnologies\CashierNowPayments\Events\InvoicePaid;
use SerenityTechnologies\CashierNowPayments\Events\InvoicePaymentFailed;
use SerenityTechnologies\CashierNowPayments\Events\PaymentFailed;
use SerenityTechnologies\CashierNowPayments\Events\PaymentReceived;
use SerenityTechnologies\CashierNowPayments\Events\PayoutStatusUpdated;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCancelled;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionExpired;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionRenewed;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionUpdated;
use SerenityTechnologies\CashierNowPayments\Models\Customer;
use SerenityTechnologies\CashierNowPayments\Models\Invoice;
use SerenityTechnologies\CashierNowPayments\Models\Payment;
use SerenityTechnologies\CashierNowPayments\Models\Payout;
use SerenityTechnologies\CashierNowPayments\Models\Subscription;
use SerenityTechnologies\NowPayments\Handlers\IpnHandler;
use SerenityTechnologies\NowPayments\Support\HandlesIpnWebhooks;

class WebhookController extends Controller
{
    use HandlesIpnWebhooks;

    /**
     * Handle incoming IPN webhook.
     */
    public function __invoke(Request $request, IpnHandler $ipnHandler): JsonResponse
    {
        try {
            // Secondary HMAC signature verification for audit
            if (!$this->verifySignature($request)) {
                report('NOWPayments webhook: HMAC signature mismatch.');

                return response()->json(['error' => 'Invalid signature'], 403);
            }

            $data = $ipnHandler->handleRequest($request);

            // Validate timestamp to prevent replay attacks
            if (!$this->validateTimestamp($data)) {
                report('NOWPayments webhook: Timestamp outside tolerance.');

                return response()->json(['error' => 'Timestamp outside tolerance'], 403);
            }

            // Process the webhook data and update local models
            $this->processWebhookData($data);

            // Fire appropriate events based on payload shape (from trait)
            $this->fireWebhookEvent($data);

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['error' => $e->getMessage()], 403);
        }
    }

    /**
     * Verify the HMAC signature of the incoming webhook.
     *
     * Provides a secondary verification layer independent of the
     * underlying IpnHandler to catch misconfigurations or bugs.
     */
    protected function verifySignature(Request $request): bool
    {
        $signature = $request->header('x-nowpayments-sig');
        $payload = $request->getContent();
        $ipnSecret = config('cashier-nowpayments.ipn_secret');

        if (empty($signature) || empty($ipnSecret)) {
            // If no IPN secret is configured, log a warning but allow
            // the request to proceed (the IpnHandler will also check)
            if (empty($ipnSecret)) {
                report('NOWPayments webhook: IPN secret not configured in cashier-nowpayments.ipn_secret.');
            }

            return true;
        }

        $computed = hash_hmac('sha512', $payload, trim($ipnSecret));

        return hash_equals($computed, $signature);
    }

    /**
     * Validate the webhook timestamp to prevent replay attacks.
     */
    protected function validateTimestamp(array $data): bool
    {
        $tolerance = config('cashier-nowpayments.webhook.tolerance', 300);

        if (isset($data['created_at'])) {
            try {
                $webhookTime = Carbon::parse($data['created_at']);
                if ($webhookTime->diffInSeconds(now()) > $tolerance) {
                    return false;
                }
            } catch (\Exception $e) {
                // If we can't parse the timestamp, allow the request
                // but log the issue
                report('NOWPayments webhook: Unable to parse created_at: ' . $data['created_at']);
            }
        }

        return true;
    }

    /**
     * Process webhook data and update local database models.
     */
    protected function processWebhookData(array $data): void
    {
        // Handle payout webhook (detected by currency+address without payment_id)
        if (isset($data['currency']) && isset($data['address']) && !isset($data['payment_id']) && !isset($data['subscription_id'])) {
            $this->handlePayout($data);

            return;
        }

        // Handle payment webhook
        if (isset($data['payment_id'])) {
            $this->handlePayment($data);
        }

        // Handle subscription webhook
        if (isset($data['subscription_id']) || isset($data['plan_id'])) {
            $this->handleSubscription($data);
        }

        // Handle invoice webhook
        if (isset($data['invoice_id'])) {
            $this->handleInvoice($data);
        }

        // Handle re-deposit
        if (isset($data['parent_payment_id'])) {
            $this->handleReDeposit($data);
        }
    }

    /**
     * Handle payment webhook data.
     */
    protected function handlePayment(array $data): void
    {
        $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);

        /** @var Payment|null $payment */
        $payment = $paymentModel::where('nowpayments_payment_id', (string) $data['payment_id'])->first();

        if ($payment === null) {
            // Create payment record if it doesn't exist
            $customer = $this->getOrCreateCustomerFromWebhook($data);

            $payment = new $paymentModel();
            $payment->fill([
                'customer_id' => $customer->id,
                'nowpayments_payment_id' => (string) $data['payment_id'],
                'nowpayments_purchase_id' => $data['purchase_id'] ?? null,
                'parent_payment_id' => $data['parent_payment_id'] ?? null,
                'type' => 'onetime',
                'status' => $data['payment_status'],
                'currency' => $data['price_currency'] ?? null,
                'amount' => $data['price_amount'] ?? 0,
                'amount_paid' => $data['actually_paid'] ?? 0,
                'pay_currency' => $data['pay_currency'] ?? null,
                'pay_amount' => $data['pay_amount'] ?? null,
                'pay_address' => $data['pay_address'] ?? null,
                'order_id' => $data['order_id'] ?? null,
                'order_description' => $data['order_description'] ?? null,
                'payin_hash' => $data['payin_hash'] ?? null,
                'payout_hash' => $data['payout_hash'] ?? null,
                'fee' => $data['fee'] ?? null,
            ]);

            if ($customer->billable !== null) {
                $payment->billable()->associate($customer->billable);
            }

            $payment->save();
        } else {
            // Update existing payment efficiently
            $changes = [];

            if (($data['payment_status'] ?? '') !== $payment->status) {
                $changes['status'] = $data['payment_status'];
            }

            if (isset($data['actually_paid']) && (string) $data['actually_paid'] !== (string) $payment->amount_paid) {
                $changes['amount_paid'] = $data['actually_paid'];
            }

            if (isset($data['payin_hash']) && $data['payin_hash'] !== $payment->payin_hash) {
                $changes['payin_hash'] = $data['payin_hash'];
            }

            if (isset($data['payout_hash']) && $data['payout_hash'] !== $payment->payout_hash) {
                $changes['payout_hash'] = $data['payout_hash'];
            }

            if (!empty($changes)) {
                $payment->update($changes);
            }
        }

        // Set paid timestamp if payment is finished
        if ($data['payment_status'] === 'finished' && $payment->paid_at === null) {
            $payment->update(['paid_at' => now()]);
        }

        // Fire event for finished payment
        if ($data['payment_status'] === 'finished') {
            PaymentReceived::dispatch($payment, $data);
        }

        // Fire event for failed payment
        if (in_array($data['payment_status'], ['failed', 'expired'], true)) {
            PaymentFailed::dispatch($payment, $data);
        }
    }

    /**
     * Handle subscription webhook data.
     */
    protected function handleSubscription(array $data): void
    {
        $subscriptionModel = config('cashier-nowpayments.model.subscription', Subscription::class);

        /** @var Subscription|null $subscription */
        $subscription = $subscriptionModel::where('nowpayments_subscription_id', $data['subscription_id'] ?? $data['id'] ?? null)->first();

        if ($subscription !== null) {
            $oldStatus = $subscription->status;
            $newStatus = $data['status'] ?? $subscription->status;

            $subscription->update([
                'status' => $newStatus,
            ]);

            // Fire appropriate events based on status changes
            if ($oldStatus !== $newStatus) {
                SubscriptionUpdated::dispatch($subscription, $data);

                if ($newStatus === 'cancelled' || $newStatus === 'expired') {
                    SubscriptionCancelled::dispatch($subscription, $data);
                }

                if ($newStatus === 'expired') {
                    SubscriptionExpired::dispatch($subscription, $data);
                }

                if ($newStatus === 'paid' && $oldStatus !== $newStatus) {
                    SubscriptionRenewed::dispatch($subscription, $data);
                }
            }
        }
    }

    /**
     * Handle invoice webhook data.
     */
    protected function handleInvoice(array $data): void
    {
        $invoiceModel = config('cashier-nowpayments.model.invoice', Invoice::class);

        /** @var Invoice|null $invoice */
        $invoice = $invoiceModel::where('nowpayments_invoice_id', $data['invoice_id'])->first();

        if ($invoice === null) {
            // Invoice not found locally — log a warning. This can happen if
            // the invoice was created outside this package (e.g., via dashboard)
            // or if the local record was deleted.
            report("NOWPayments webhook: Invoice {$data['invoice_id']} not found locally.");

            return;
        }

        $invoice->update([
            'status' => $data['payment_status'] ?? $invoice->status,
            'amount_paid' => $data['actually_paid'] ?? $invoice->amount_paid,
        ]);

        if ($data['payment_status'] === 'finished' && $invoice->paid_at === null) {
            $invoice->update(['paid_at' => now()]);
            InvoicePaid::dispatch($invoice, $data);
        }

        if (in_array($data['payment_status'] ?? '', ['failed', 'expired'], true)) {
            InvoicePaymentFailed::dispatch($invoice, $data);
        }
    }

    /**
     * Handle payout webhook data.
     */
    protected function handlePayout(array $data): void
    {
        $payoutModel = config('cashier-nowpayments.model.payout', Payout::class);

        // Try to find by NOWPayments payout ID or batch withdrawal ID
        $payoutId = $data['id'] ?? $data['batch_withdrawal_id'] ?? null;

        /** @var Payout|null $payout */
        $payout = $payoutModel::where('nowpayments_payout_id', $payoutId)
            ->orWhere('batch_withdrawal_id', $data['batch_withdrawal_id'] ?? null)
            ->first();

        if ($payout !== null) {
            $payout->update([
                'status' => strtolower($data['status'] ?? $payout->status),
                'hash' => $data['hash'] ?? $payout->hash,
                'error' => $data['error'] ?? $payout->error,
                'processed_at' => $data['status'] === 'finished' && $payout->processed_at === null
                    ? now()
                    : $payout->processed_at,
            ]);

            PayoutStatusUpdated::dispatch($payout, $data);
        }
    }

    /**
     * Handle re-deposit webhook data.
     */
    protected function handleReDeposit(array $data): void
    {
        // Re-deposits are linked to parent payment via parent_payment_id
        // They are handled in the handlePayment method
        // but we can add special logic here if needed
    }

    /**
     * Get or create customer from webhook data.
     *
     * Attempts to reconcile the billable association using the order_id
     * stored in the checkout session cache.
     */
    protected function getOrCreateCustomerFromWebhook(array $data): Customer
    {
        $customerModel = config('cashier-nowpayments.model.customer', Customer::class);

        // Try to find existing customer by email
        if (isset($data['email']) && !empty($data['email'])) {
            /** @var Customer|null $customer */
            $customer = $customerModel::where('email', $data['email'])->first();
            if ($customer !== null) {
                return $customer;
            }
        }

        // Try to find by order_id — check session cache for billable mapping
        if (isset($data['order_id']) && !empty($data['order_id'])) {
            $billableMapping = Cache::get('checkout.billable.' . $data['order_id']);
            if ($billableMapping !== null) {
                /** @var Customer|null $customer */
                $customer = $customerModel::where('billable_type', $billableMapping['billable_type'])
                    ->where('billable_id', $billableMapping['billable_id'])
                    ->first();
                if ($customer !== null) {
                    return $customer;
                }
            }

            // Fallback: search by metadata
            /** @var Customer|null $customer */
            $customer = $customerModel::whereJsonContains('metadata->order_id', $data['order_id'])->first();
            if ($customer !== null) {
                return $customer;
            }
        }

        // Create a new customer record — billable association will need
        // manual reconciliation via order_id or email matching.
        /** @var Customer $customer */
        $customer = new $customerModel();
        $customer->fill([
            'nowpayments_customer_id' => 'np_payment_' . $data['payment_id'],
            'email' => $data['email'] ?? null,
            'metadata' => [
                'order_id' => $data['order_id'] ?? null,
                'purchase_id' => $data['purchase_id'] ?? null,
                'source' => 'webhook_auto_created',
            ],
        ]);

        $customer->save();

        return $customer;
    }
}

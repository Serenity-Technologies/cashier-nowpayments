<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


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
use SerenityTechnologies\CashierNowPayments\Models\WebhookLog;
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
        $rawPayload = $request->getContent();
        $signature = $request->header('x-nowpayments-sig');

        // Log the incoming webhook for audit/debugging
        $webhookLog = null;
        if (config('cashier-nowpayments.webhook.log_enabled', true)) {
            $webhookLog = $this->logWebhook($request, $rawPayload, $signature);
        }

        try {
            // Secondary HMAC signature verification for audit
            $signatureValid = $this->verifySignature($request);

            if (!$signatureValid) {
                report('NOWPayments webhook: HMAC signature mismatch.');

                $webhookLog?->update([
                    'signature_valid' => false,
                    'processing_error' => 'HMAC signature mismatch',
                ]);

                return response()->json(['error' => 'Invalid signature'], 403);
            }

            $data = $ipnHandler->handleRequest($request);

            // Validate timestamp to prevent replay attacks
            if (!$this->validateTimestamp($data)) {
                report('NOWPayments webhook: Timestamp outside tolerance.');

                $webhookLog?->update([
                    'signature_valid' => true,
                    'payload' => $data,
                    'processing_error' => 'Timestamp outside tolerance',
                ]);

                return response()->json(['error' => 'Timestamp outside tolerance'], 403);
            }

            // Update the log with parsed payload and event type
            $webhookLog?->update([
                'signature_valid' => true,
                'payload' => $data,
                'payload_id' => $data['id'] ?? $data['payment_id'] ?? null,
                'event_type' => $this->detectEventType($data),
                'payment_id' => $data['payment_id'] ?? null,
                'invoice_id' => $data['invoice_id'] ?? null,
                'subscription_id' => $data['subscription_id'] ?? $data['id'] ?? null,
                'payout_id' => $data['batch_withdrawal_id'] ?? $data['id'] ?? null,
                'payment_status' => $data['payment_status'] ?? $data['status'] ?? null,
            ]);

            // Process the webhook data and update local models
            $this->processWebhookData($data);

            // Fire appropriate events based on payload shape (from trait)
            $this->fireWebhookEvent($data);

            $webhookLog?->markAsProcessed();

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            report($e);

            $webhookLog?->markAsFailed($e->getMessage());

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
        // Handle payout webhook (detected by batch_withdrawal_id)
        if (isset($data['batch_withdrawal_id'])) {
            $this->handlePayout($data);
            return;
        }

        // Handle payment webhook (includes invoice payments and direct payments)
        if (isset($data['payment_id'])) {
            $this->handlePayment($data);
            return;
        }

        // Handle subscription or recurring payment webhook
        if (isset($data['subscription_id']) || (isset($data['id']) && isset($data['status']) && !isset($data['batch_withdrawal_id']) && !isset($data['payment_id']))) {
            $this->handleSubscription($data);
            return;
        }

        // Handle invoice webhook (status changes without a specific payment_id)
        if (isset($data['invoice_id'])) {
            $this->handleInvoice($data);
            return;
        }
    }

    /**
     * Handle payment webhook data.
     *
     * This handles both direct payments and invoice payments.
     * For invoice payments (when invoice_id is present), the billable
     * is resolved through the invoice relationship, NOT from cache.
     */
    protected function handlePayment(array $data): void
    {
        $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);

        /** @var Payment|null $payment */
        $payment = $paymentModel::where('nowpayments_payment_id', (string) $data['payment_id'])->first();

        if ($payment === null) {
            // Resolve billable: invoice payments inherit billable from invoice,
            // direct payments resolve from checkout cache
            $billable = null;
            $customer = null;

            // If this is an invoice payment, get billable from the invoice
            if (isset($data['invoice_id'])) {
                $invoiceModel = config('cashier-nowpayments.model.invoice', Invoice::class);
                $invoice = $invoiceModel::with('billable', 'customer')->where('nowpayments_invoice_id', $data['invoice_id'])->first();

                if ($invoice !== null) {
                    $billable = $invoice->billable;
                    $customer = $invoice->customer;
                }
            }

            // Fallback: resolve from checkout cache (for direct payments or when invoice not found)
            if ($customer === null) {
                $customer = $this->getOrCreateCustomerFromWebhook($data);
                $billable = $customer->billable;
            }

            // Resolve parent payment if it's a re-deposit or partial payment
            $parentPaymentId = null;
            if (isset($data['parent_payment_id'])) {
                /** @var Payment|null $parent */
                $parent = $paymentModel::where('nowpayments_payment_id', (string) $data['parent_payment_id'])->first();
                $parentPaymentId = $parent?->id;
            }

            $payment = new $paymentModel();
            $dataArray = [
                'customer_id' => $customer->id,
                'nowpayments_payment_id' => (string) $data['payment_id'],
                'nowpayments_purchase_id' => $data['purchase_id'] ?? null,
                'parent_payment_id' => $parentPaymentId,
                'type' => isset($data['invoice_id']) ? 'invoice' : 'onetime',
                'status' => $data['payment_status'] ?? 'waiting',
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
                'metadata' => [
                    'ipn_id' => $data['id'] ?? null,
                    'actually_paid_at_fiat' => $data['actually_paid_at_fiat'] ?? null,
                    'outcome_amount' => $data['outcome_amount'] ?? null,
                    'outcome_currency' => $data['outcome_currency'] ?? null,
                ],
            ];

            if ($billable !== null) {
                $dataArray['billable_id'] = $billable->getKey();
                $dataArray['billable_type'] = $billable->getMorphClass();
            }

            $payment->fill($dataArray);
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

        // Subscriptions use 'subscription_id' or 'id' in IPN
        $remoteId = $data['subscription_id'] ?? $data['id'] ?? null;

        /** @var Subscription|null $subscription */
        $subscription = $subscriptionModel::where('nowpayments_subscription_id', (string) $remoteId)->first();

        if ($subscription !== null) {
            $oldStatus = $subscription->status;
            $newStatus = strtolower((string) ($data['status'] ?? $subscription->status));

            if ($newStatus === 'finished') {
                $newStatus = 'paid';
            }

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

                if ($newStatus === 'paid') {
                    SubscriptionRenewed::dispatch($subscription, $data);

                    // if($data['amount']) {
                    //     $this->recordRecurringPayment($subscription, $data);
                    // }
                }
            }
        }
    }

    /**
     * Handle invoice webhook data.
     *
     * This handles invoice status changes (not individual payments).
     * Individual payments against invoices are handled by handlePayment().
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
                'processed_at' => strtolower($data['status'] ?? '') === 'finished' && $payout->processed_at === null
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
     *
     * @throws \RuntimeException If the billable cannot be resolved for a new customer
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

        // Attempt to resolve billable association from order_id cache
        $billable = null;
        if (isset($data['order_id']) && !empty($data['order_id'])) {
            // Check session cache for billable mapping
            $billableMapping = Cache::get('checkout.billable.' . $data['order_id']);
            if ($billableMapping !== null) {
                // Try to find the billable model
                $billableType = $billableMapping['billable_type'];
                $billableId = $billableMapping['billable_id'];

                if (class_exists($billableType)) {
                    $billable = $billableType::find($billableId);
                }
            }
        }

        // If no billable found, try to find by email on existing billable models
        if ($billable === null && isset($data['email']) && !empty($data['email'])) {
            $billableModel = config('cashier-nowpayments.billable');
            if ($billableModel !== null) {
                $billable = $billableModel::where('email', $data['email'])->first();
            }
        }

        // If we still can't resolve the billable, we cannot create the customer
        // because the billable columns are NOT NULL. This typically means the
        // checkout cache expired (7-day TTL) or the payment was created outside
        // this package's flow.
        if ($billable === null) {
            $orderId = $data['order_id'] ?? 'unknown';
            throw new \RuntimeException(
                "Unable to resolve billable model for webhook payment. "
                . "Order ID: {$orderId}. "
                . "This usually means the checkout session cache has expired. "
                . "The payment exists on NOWPayments but has no local billable association."
            );
        }

        // Create a new customer record with the resolved billable association
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

        $customer->billable()->associate($billable);
        $customer->save();

        return $customer;
    }

    /**
     * Log an incoming webhook for audit and debugging.
     */
    protected function logWebhook(Request $request, string $rawPayload, ?string $signature): WebhookLog
    {
        $webhookLogModel = config('cashier-nowpayments.model.webhook_log', WebhookLog::class);

        // Try to parse the payload immediately for indexing
        $payload = json_decode($rawPayload, true) ?? [];

        /** @var WebhookLog $webhookLog */
        $webhookLog = new $webhookLogModel();
        $webhookLog->fill([
            'payload_id' => $payload['id'] ?? $payload['payment_id'] ?? null,
            'event_type' => $this->detectEventType($payload),
            'payment_id' => $payload['payment_id'] ?? null,
            'invoice_id' => $payload['invoice_id'] ?? null,
            'subscription_id' => $payload['subscription_id'] ?? null,
            'payout_id' => $payload['id'] ?? $payload['batch_withdrawal_id'] ?? null,
            'payment_status' => $payload['payment_status'] ?? $payload['status'] ?? null,
            'signature' => $signature,
            'signature_valid' => false, // Will be verified later
            'processed' => false,
            'payload' => !empty($payload) ? $payload : ['raw' => $rawPayload],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        $webhookLog->save();

        return $webhookLog;
    }

    /**
     * Detect the event type from the webhook payload.
     */
    protected function detectEventType(array $data): ?string
    {
        if (isset($data['payment_id'])) {
            return 'payment';
        }

        if (isset($data['batch_withdrawal_id'])) {
            return 'payout';
        }

        // Subscriptions/Recurring payments use 'id' or 'subscription_id'
        // and do NOT have 'payment_id' or 'batch_withdrawal_id'
        if (isset($data['subscription_id']) || (isset($data['id']) && !isset($data['payment_id']) && !isset($data['batch_withdrawal_id']))) {
            return 'subscription';
        }

        if (isset($data['invoice_id'])) {
            return 'invoice';
        }

        if (isset($data['parent_payment_id'])) {
            return 'redeposit';
        }

        return null;
    }

    /**
     * Record a recurring payment for a subscription.
     */
    protected function recordRecurringPayment(Subscription $subscription, array $data): void
    {
        $paymentModel = config('cashier-nowpayments.model.payment', Payment::class);

        // For recurring payments, we use the event ID + timestamp to create a unique reference
        // if a specific payment_id isn't provided.
        $externalId = $data['id'] ?? $data['subscription_id'] ?? $subscription->nowpayments_subscription_id;
        $timestamp = strtotime($data['created_at'] ?? 'now');
        $paymentRef = "recurring_{$externalId}_{$timestamp}";

        /** @var Payment|null $payment */
        $payment = $paymentModel::where('nowpayments_payment_id', $paymentRef)->first();

        if ($payment === null) {
            $payment = new $paymentModel();
            
            // Resolve billable association from subscription
            $customer = $subscription->customer;
            
            $payment->fill([
                'customer_id' => $subscription->customer_id,
                'billable_id' => $customer?->billable_id,
                'billable_type' => $customer?->billable_type,
                'subscription_id' => $subscription->id,
                'nowpayments_payment_id' => $paymentRef,
                'type' => 'recurring',
                'status' => 'finished',
                'currency' => $data['currency'] ?? $subscription->currency,
                'amount' => $data['amount'] ?? $subscription->total_price,
                'amount_paid' => $data['amount'] ?? $subscription->total_price,
                'pay_currency' => $data['currency'] ?? null,
                'pay_amount' => $data['amount'] ?? null,
                'paid_at' => Carbon::parse($data['created_at'] ?? now()),
                'metadata' => [
                    'source' => 'recurring_webhook',
                    'original_id' => $data['id'] ?? null,
                    'created_at' => $data['created_at'] ?? null,
                    'updated_at' => $data['updated_at'] ?? null,
                ],
            ]);
            $payment->save();

            PaymentReceived::dispatch($payment, $data);
        }
    }
}

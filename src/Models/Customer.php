<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use SerenityTechnologies\CashierNowPayments\Events\CreditExpired;

class Customer extends Model
{
    use HasFactory;
    use SoftDeletes, HasUlids;

    /**
     * Get the table name for the model.
     */
    public function getTable(): string
    {
        $prefix = config('cashier-nowpayments.prefix', 'cashier_nowpayments_');
        return $prefix . 'customers';
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'metadata' => 'array',
        'trial_ends_at' => 'datetime',
    ];

    /**
     * Get the owning billable model.
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the subscriptions for the customer.
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany($this->getSubscriptionModel());
    }

    /**
     * Get the payments for the customer.
     */
    public function payments(): HasMany
    {
        return $this->hasMany($this->getPaymentModel());
    }

    /**
     * Get the invoices for the customer.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany($this->getInvoiceModel());
    }

    /**
     * Get the payouts for the customer.
     */
    public function payouts(): HasMany
    {
        return $this->hasMany($this->getPayoutModel());
    }

    /**
     * Get the credits for the customer.
     */
    public function credits(): HasMany
    {
        return $this->hasMany($this->getCreditModel());
    }

    /**
     * Get the total credit balance for the customer.
     */
    public function creditBalance(): string
    {
        return (string) $this->credits()
            ->whereNull('applied_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum('amount');
    }

    /**
     * Get the original amount of a credit.
     *
     * For partially consumed credits, returns the original issued amount
     * from metadata. For fully intact credits, returns the current amount.
     */
    public function getOriginalAmountForCredit(Model $credit): string
    {
        return $credit->metadata['original_amount'] ?? number_format((float) $credit->amount, 8, '.', '');
    }

    /**
     * Apply available credits against a charge amount.
     *
     * Consumes credits in FIFO order (oldest first) up to the charge amount.
     * Returns the amount that was covered by credits and the remaining charge.
     *
     * Uses pessimistic locking to prevent race conditions when multiple
     * processes attempt to apply credits simultaneously.
     *
     * @param float $chargeAmount The amount to charge
     * @return array{covered: string, remaining: string}
     */
    public function applyCredits(float $chargeAmount): array
    {
        if ($chargeAmount <= 0) {
            return ['covered' => '0', 'remaining' => number_format($chargeAmount, 8, '.', '')];
        }

        $remaining = number_format($chargeAmount, 8, '.', '');
        $covered = '0';

        // Get credits in FIFO order with pessimistic locking to prevent races
        $credits = $this->credits()
            ->whereNull('applied_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->where('amount', '>', 0)
            ->orderBy('created_at', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($credits as $credit) {
            if (bccomp($remaining, '0', 8) <= 0) {
                break;
            }

            $creditAmount = number_format((float) $credit->amount, 8, '.', '');
            $useAmount = bccomp($creditAmount, $remaining, 8) > 0 ? $remaining : $creditAmount;

            // Mark credit as fully or partially applied
            if (bccomp($creditAmount, $useAmount, 8) === 0) {
                // Fully consumed
                $credit->update([
                    'applied_at' => now(),
                    'metadata' => array_merge($credit->metadata ?? [], [
                        'fully_applied' => true,
                    ]),
                ]);
            } else {
                // Partially consumed — store consumption record in metadata
                // and preserve original_amount for audit purposes
                $originalAmount = $credit->metadata['original_amount'] ?? $creditAmount;
                $partialApplications = $credit->metadata['partial_applications'] ?? [];
                $partialApplications[] = [
                    'applied_at' => now()->toIso8601String(),
                    'amount_used' => $useAmount,
                    'remaining_after' => bcsub($creditAmount, $useAmount, 8),
                    'original_amount' => $originalAmount,
                ];

                $credit->update([
                    'amount' => bcsub($creditAmount, $useAmount, 8),
                    'metadata' => array_merge($credit->metadata ?? [], [
                        'partial_applications' => $partialApplications,
                        'original_amount' => $originalAmount,
                        'total_consumed' => bcadd(
                            $credit->metadata['total_consumed'] ?? '0',
                            $useAmount,
                            8
                        ),
                    ]),
                ]);
            }

            $covered = bcadd($covered, $useAmount, 8);
            $remaining = bcsub($remaining, $useAmount, 8);
        }

        return [
            'covered' => $covered,
            'remaining' => $remaining,
        ];
    }

    /**
     * Expire credits past their expiration date.
     *
     * @return int Number of credits expired
     */
    public function expireCredits(): int
    {
        $count = $this->credits()
            ->whereNull('applied_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['applied_at' => now()]);

        if ($count > 0) {
            // Fetch expired credits for event dispatch (after they've been marked)
            $expiredCredits = $this->credits()
                ->where('applied_at', now())
                ->whereNotNull('expires_at')
                ->get();

            $totalAmount = $expiredCredits->sum('amount');

            CreditExpired::dispatch($expiredCredits, $count, (string) $totalAmount);
        }

        return $count;
    }

    /**
     * Determine if the customer is on trial.
     */
    public function onTrial(): bool
    {
        if ($this->trial_ends_at === null) {
            return false;
        }

        return $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the customer has an active subscription.
     *
     * Checks against all known NOWPayments subscription status values
     * to avoid false negatives from API status variations.
     */
    public function subscribed(string $type = 'default', ?string $planId = null): bool
    {
        $subscription = $this->subscription($type);

        if ($subscription === null || $subscription->ends_at !== null) {
            return false;
        }

        // Accept all active status variants from NOWPayments API
        $activeStatuses = ['paid', 'active', 'waiting_pay', 'waiting', 'confirming'];

        if (!in_array($subscription->status, $activeStatuses, true)) {
            return false;
        }

        if ($planId !== null && $subscription->nowpayments_plan_id !== $planId) {
            return false;
        }

        return true;
    }

    /**
     * Get a subscription by type.
     */
    public function subscription(string $type = 'default'): ?Subscription
    {
        return $this->subscriptions()
            ->where('type', $type)
            ->first();
    }

    /**
     * Determine if the customer has any incomplete payments.
     */
    public function hasIncompletePayment(): bool
    {
        return $this->payments()
            ->whereIn('status', ['waiting', 'confirming', 'partially_paid'])
            ->exists();
    }

    /**
     * Get the subscription model class.
     */
    protected function getSubscriptionModel(): string
    {
        return config('cashier-nowpayments.model.subscription', Subscription::class);
    }

    /**
     * Get the payment model class.
     */
    protected function getPaymentModel(): string
    {
        return config('cashier-nowpayments.model.payment', Payment::class);
    }

    /**
     * Get the invoice model class.
     */
    protected function getInvoiceModel(): string
    {
        return config('cashier-nowpayments.model.invoice', Invoice::class);
    }

    /**
     * Get the payout model class.
     */
    protected function getPayoutModel(): string
    {
        return config('cashier-nowpayments.model.payout', Payout::class);
    }

    /**
     * Get the credit model class.
     */
    protected function getCreditModel(): string
    {
        return config('cashier-nowpayments.model.credit', Credit::class);
    }
}

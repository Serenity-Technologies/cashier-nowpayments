<?php
/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use SerenityTechnologies\CashierNowPayments\Concerns\HasNowPaymentsTable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use SerenityTechnologies\CashierNowPayments\Events\CreditExpired;

/**
 * Represents a customer synced from the NOWPayments platform.
 *
 * A Customer is linked to a billable model (e.g., User) via a polymorphic
 * relationship. It tracks the NOWPayments customer ID, trial status, and
 * owns subscriptions, payments, invoices, payouts, and credits.
 *
 * @property string $id The ULID primary key
 * @property string $billable_type The owning billable model type
 * @property int|string $billable_id The owning billable model ID
 * @property string|null $nowpayments_customer_id The NOWPayments customer identifier
 * @property string|null $email The customer email address
 * @property string|null $name The customer name
 * @property array|null $metadata Additional JSON metadata
 * @property \Carbon\Carbon|null $trial_ends_at When the trial period ends
 * @property \Carbon\Carbon|null $deleted_at Soft delete timestamp
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 *
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Subscription> $subscriptions
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Invoice> $invoices
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payout> $payouts
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Credit> $credits
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<self>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereId(string $id)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNowPaymentsCustomerId(string $nowpaymentsCustomerId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereBillable(string $billableType, int|string $billableId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereEmail(string $email)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereTrialEndsAt(\Carbon\Carbon $date)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNull(string $column)
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class Customer extends Model
{
    use HasFactory;
    use SoftDeletes, HasUlids;
    use HasNowPaymentsTable;

    /**
     * Table suffix for the config-based prefix.
     */
    protected string $nowPaymentsTable = 'customers';

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
     * Cached credit balance to avoid repeated SUM() queries.
     *
     * @var string|null
     */
    protected ?string $creditBalanceCache = null;

    /**
     * Get the owning billable model.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo<Model, $this>
     */
    public function billable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the subscriptions for the customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Subscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany($this->getSubscriptionModel());
    }

    /**
     * Get the payments for the customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany($this->getPaymentModel());
    }

    /**
     * Get the invoices for the customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany($this->getInvoiceModel());
    }

    /**
     * Get the payouts for the customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Payout, $this>
     */
    public function payouts(): HasMany
    {
        return $this->hasMany($this->getPayoutModel());
    }

    /**
     * Get the credits for the customer.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Credit, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany($this->getCreditModel());
    }

    /**
     * Get the total credit balance for the customer.
     *
     * Uses cached balance if available to avoid repeated SUM() queries.
     *
     * @param bool $forceRefresh Force a fresh calculation from the database
     * @return string The sum of all unapplied, non-expired credit amounts
     */
    public function creditBalance(bool $forceRefresh = false): string
    {
        if ($this->creditBalanceCache !== null && !$forceRefresh) {
            return $this->creditBalanceCache;
        }

        $balance = (string) $this->credits()
            ->whereNull('applied_at')
            ->whereNull('expired_at')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->sum('amount');

        $this->creditBalanceCache = $balance;

        return $balance;
    }

    /**
     * Clear the cached credit balance.
     *
     * @return void
     */
    public function clearCreditBalanceCache(): void
    {
        $this->creditBalanceCache = null;
    }

    /**
     * Get the original amount of a credit.
     *
     * For partially consumed credits, returns the original issued amount
     * from metadata. For fully intact credits, returns the current amount.
     *
     * @param \Illuminate\Database\Eloquent\Model $credit The credit model instance
     * @return string The original credit amount as a formatted string
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
            ->whereNull('expired_at')
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

        // Invalidate cached balance since credits were modified
        $this->clearCreditBalanceCache();

        return [
            'covered' => $covered,
            'remaining' => $remaining,
        ];
    }

    /**
     * Expire credits past their expiration date.
     *
     * Dispatches a CreditExpired event if any credits were expired.
     *
     * @return int Number of credits expired
     */
    public function expireCredits(): int
    {
        // Get IDs of credits that will expire (before updating)
        $expiringIds = $this->credits()
            ->whereNull('applied_at')
            ->whereNull('expired_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->pluck('id')
            ->toArray();

        if (empty($expiringIds)) {
            return 0;
        }

        $count = $this->credits()
            ->whereIn('id', $expiringIds)
            ->update(['expired_at' => now()]);

        if ($count > 0) {
            // Fetch expired credits for event dispatch using tracked IDs
            $expiredCredits = $this->credits()
                ->whereIn('id', $expiringIds)
                ->get();

            $totalAmount = $expiredCredits->sum('amount');

            CreditExpired::dispatch($expiredCredits, $count, (string) $totalAmount);
        }

        // Invalidate cached balance since credits were modified
        $this->clearCreditBalanceCache();

        return $count;
    }

    /**
     * Determine if the customer is on trial.
     *
     * @return bool True if the trial period has not yet ended
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
     *
     * @param string $type The subscription type to check (default: 'default')
     * @param string|null $planId Optional plan ID to filter by
     * @return bool True if the customer has an active subscription of the given type
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
     *
     * @param string $type The subscription type to retrieve (default: 'default')
     * @return Subscription|null The subscription instance or null if not found
     */
    public function subscription(string $type = 'default'): ?Subscription
    {
        return $this->subscriptions()
            ->where('type', $type)
            ->first();
    }

    /**
     * Determine if the customer has any incomplete payments.
     *
     * @return bool True if there are payments in waiting, confirming, or partially_paid status
     */
    public function hasIncompletePayment(): bool
    {
        return $this->payments()
            ->whereIn('status', ['waiting', 'confirming', 'partially_paid'])
            ->exists();
    }

    /**
     * Get the subscription model class.
     *
     * @return class-string<Subscription>
     */
    protected function getSubscriptionModel(): string
    {
        return config('cashier-nowpayments.model.subscription', Subscription::class);
    }

    /**
     * Get the payment model class.
     *
     * @return class-string<Payment>
     */
    protected function getPaymentModel(): string
    {
        return config('cashier-nowpayments.model.payment', Payment::class);
    }

    /**
     * Get the invoice model class.
     *
     * @return class-string<Invoice>
     */
    protected function getInvoiceModel(): string
    {
        return config('cashier-nowpayments.model.invoice', Invoice::class);
    }

    /**
     * Get the payout model class.
     *
     * @return class-string<Payout>
     */
    protected function getPayoutModel(): string
    {
        return config('cashier-nowpayments.model.payout', Payout::class);
    }

    /**
     * Get the credit model class.
     *
     * @return class-string<Credit>
     */
    protected function getCreditModel(): string
    {
        return config('cashier-nowpayments.model.credit', Credit::class);
    }
}

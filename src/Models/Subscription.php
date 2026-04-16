<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Models;

use SerenityTechnologies\CashierNowPayments\Concerns\HasNowPaymentsTable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCancelled;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionSwapped;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionUpdated;
use SerenityTechnologies\CashierNowPayments\Services\CheckoutService;
use SerenityTechnologies\CashierNowPayments\Support\ProrationMode;
use SerenityTechnologies\NowPayments\DTOs\Request\SubscriptionRequest;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

/**
 * Represents a recurring subscription managed through NOWPayments.
 *
 * Subscriptions track the lifecycle of a recurring billing arrangement
 * including trial periods, active billing, cancellation, and expiration.
 * They can contain multiple subscription items for multi-plan subscriptions.
 *
 * @property string $id The ULID primary key
 * @property string $customer_id The owning customer's ULID
 * @property string $type The subscription type (e.g., 'default')
 * @property string|null $nowpayments_plan_id The NOWPayments plan identifier
 * @property string|null $nowpayments_subscription_id The NOWPayments subscription identifier
 * @property string $status The subscription status (e.g., active, paid, waiting_pay, cancelled, expired)
 * @property string|null $currency The subscription currency
 * @property string $total_price The total subscription price
 * @property int $quantity The subscription quantity
 * @property \Carbon\Carbon|null $trial_ends_at When the trial period ends
 * @property \Carbon\Carbon|null $ends_at When the subscription ends (null if active)
 * @property \Carbon\Carbon|null $renews_at When the subscription next renews
 * @property \Carbon\Carbon|null $cancels_at When the subscription was cancelled
 * @property array|null $metadata Additional JSON metadata
 * @property \Carbon\Carbon|null $deleted_at Soft delete timestamp
 * @property \Carbon\Carbon $created_at Creation timestamp
 * @property \Carbon\Carbon $updated_at Last update timestamp
 *
 * @property-read Customer $customer
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SubscriptionItem> $items
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Payment> $payments
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Credit> $credits
 *
 * @mixin \Illuminate\Database\Eloquent\Builder<self>
 *
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereId(string $id)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereCustomerId(string $customerId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNowPaymentsSubscriptionId(string $nowpaymentsSubscriptionId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereNowPaymentsPlanId(string $nowpaymentsPlanId)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereStatus(string $status)
 * @method static \Illuminate\Database\Eloquent\Builder<self> whereType(string $type)
 * @method static \Illuminate\Database\Eloquent\Builder<self> active()
 * @method static \Illuminate\Database\Eloquent\Builder<self> onTrial()
 * @method static \Illuminate\Database\Eloquent\Builder<self> cancelled()
 * @method static \Illuminate\Database\Eloquent\Builder<self> expired()
 *
 * @package SerenityTechnologies\CashierNowPayments\Models
 */
class Subscription extends Model
{
    use HasFactory;
    use SoftDeletes, HasUlids;
    use HasNowPaymentsTable;

    /**
     * Table suffix for the config-based prefix.
     */
    protected string $nowPaymentsTable = 'subscriptions';

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
        'ends_at' => 'datetime',
        'renews_at' => 'datetime',
        'cancels_at' => 'datetime',
        'total_price' => 'decimal:8',
    ];

    /**
     * Get the customer that owns the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo($this->getCustomerModel());
    }

    /**
     * Get the subscription items.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<SubscriptionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany($this->getSubscriptionItemModel());
    }

    /**
     * Get the payments for the subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany($this->getPaymentModel());
    }

    /**
     * Get the credits associated with this subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<Credit, $this>
     */
    public function credits(): HasMany
    {
        return $this->hasMany($this->getCreditModel());
    }

    /**
     * Get the plan for this subscription.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo($this->getPlanModel(), 'nowpayments_plan_id', 'nowpayments_plan_id');
    }

    /**
     * Scope a query to only include active subscriptions.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeActive($query): void
    {
        $query->whereNull('ends_at');
    }

    /**
     * Scope a query to only include subscriptions on trial.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeOnTrial($query): void
    {
        $query->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now());
    }

    /**
     * Scope a query to only include cancelled subscriptions.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeCancelled($query): void
    {
        $query->whereNotNull('ends_at');
    }

    /**
     * Scope a query to only include expired subscriptions.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     * @return void
     */
    public function scopeExpired($query): void
    {
        $query->where('status', 'expired');
    }

    /**
     * Determine if the subscription is active.
     *
     * @return bool True if ends_at is null
     */
    public function isActive(): bool
    {
        return $this->ends_at === null;
    }

    /**
     * Determine if the subscription is on trial.
     *
     * @return bool True if trial_ends_at is set and in the future
     */
    public function isOnTrial(): bool
    {
        if ($this->trial_ends_at === null) {
            return false;
        }

        return $this->trial_ends_at->isFuture();
    }

    /**
     * Determine if the subscription is cancelled.
     *
     * @return bool True if ends_at is set
     */
    public function isCancelled(): bool
    {
        return $this->ends_at !== null;
    }

    /**
     * Determine if the subscription is expired.
     *
     * @return bool True if status is 'expired'
     */
    public function isExpired(): bool
    {
        return $this->status === 'expired';
    }

    /**
     * Determine if the subscription has incomplete payments.
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
     * Cancel the subscription at the end of the billing period.
     *
     * Schedules cancellation and dispatches a SubscriptionCancelled event.
     *
     * @return $this
     * @throws \SerenityTechnologies\NowPayments\Exceptions\NowPaymentsException
     */
    public function cancel(): self
    {
        if ($this->nowpayments_subscription_id !== null) {
            NowPayments::deleteSubscription($this->nowpayments_subscription_id);
        }

        $this->cancels_at = now();
        $this->ends_at = $this->renews_at ?? now()->addDays($this->getBillingIntervalDays());
        $this->status = 'cancelled';
        $this->save();

        SubscriptionCancelled::dispatch($this);

        return $this;
    }

    /**
     * Cancel the subscription immediately.
     *
     * @return $this
     * @throws \SerenityTechnologies\NowPayments\Exceptions\NowPaymentsException
     */
    public function cancelNow(): self
    {
        if ($this->nowpayments_subscription_id !== null) {
            NowPayments::deleteSubscription($this->nowpayments_subscription_id);
        }

        $this->cancels_at = now();
        $this->ends_at = now();
        $this->status = 'cancelled';
        $this->save();

        SubscriptionCancelled::dispatch($this);

        return $this;
    }

    /**
     * Resume a cancelled subscription.
     *
     * @return $this
     * @throws \RuntimeException If the subscription was deleted on NOWPayments
     */
    public function resume(): self
    {
        if ($this->ends_at === null) {
            return $this;
        }

        throw new \RuntimeException(
            'NOWPayments does not support resuming cancelled subscriptions. '
            . 'The remote subscription was deleted when cancelled. Create a new subscription instead.'
        );
    }

    /**
     * Swap the subscription to a new plan.
     *
     * Wraps the entire operation in a database transaction to prevent
     * partial failures. Computes prorated credit based on remaining
     * billing days, updates the local total_price, and records the
     * credit ledger entry atomically.
     *
     * When using ProrationMode::IMMEDIATE for upgrades, the upgrade charge
     * is applied via the checkout flow. The checkout URL is included in the
     * dispatched events for redirect purposes.
     *
     * @param string $newPlanId The NOWPayments plan ID to swap to
     * @param string|null $prorationMode Override proration mode (credit, immediate, end_of_period, none)
     * @return $this
     * @throws \Throwable
     */
    public function swap(string $newPlanId, ?string $prorationMode = null): self
    {
        $mode = $prorationMode ?? config('cashier-nowpayments.proration.mode', ProrationMode::CREDIT);
        ProrationMode::validate($mode);

        $oldSubscriptionId = $this->nowpayments_subscription_id;

        return \Illuminate\Support\Facades\DB::transaction(function () use ($newPlanId, $mode, $oldSubscriptionId) {
            $oldPlanId = $this->nowpayments_plan_id;
            $oldPrice = $this->total_price;
            $oldCurrency = $this->currency;

            // Compute prorated remaining value based on mode
            $proratedCredit = 0.0;
            if ($mode !== ProrationMode::END_OF_PERIOD && $mode !== ProrationMode::NONE) {
                $proratedCredit = $this->calculateProratedCredit();
            }

            // Delete current subscription on NOWPayments
            if ($this->nowpayments_subscription_id !== null) {
                try {
                    NowPayments::deleteSubscription($this->nowpayments_subscription_id);
                } catch (\Throwable $e) {
                    $message = strtolower($e->getMessage());
                    // Only swallow "not found" type errors — the subscription may have been auto-deleted
                    if (!str_contains($message, '404') && !str_contains($message, 'not found') && !str_contains($message, 'does not exist')) {
                        throw $e;
                    }
                    report("Subscription {$oldSubscriptionId} already deleted on NOWPayments");
                }
            }

            // Create new subscription with new plan
            $request = new SubscriptionRequest(subscriptionPlanId: (int) $newPlanId);
            $response = NowPayments::createSubscription($request);

            // Fetch new plan details to get the correct price
            $newPlan = NowPayments::getPlan($newPlanId);

            // Update local record with new subscription details
            $this->nowpayments_plan_id = $newPlanId;
            $this->nowpayments_subscription_id = $response->id;
            $this->total_price = $newPlan->price ?? $oldPrice;
            $this->currency = $newPlan->currency ?? $oldCurrency;

            // Update renews_at from the response or calculate from plan interval
            if (isset($response->next_billing_date) && $response->next_billing_date !== null) {
                $this->renews_at = Carbon::parse($response->next_billing_date);
            } elseif ($newPlan->interval_days ?? null) {
                $this->renews_at = now()->addDays((int) $newPlan->interval_days);
            }
            // If neither is available, keep the existing renews_at

            $this->save();

            // Update subscription items
            $this->items()->update(['nowpayments_plan_id' => $newPlanId]);

            // Calculate price difference for upgrade/downgrade classification
            $difference = (float) bcsub(
                number_format($oldPrice, 8, '.', ''),
                number_format($this->total_price, 8, '.', ''),
                8,
            );

            $isUpgrade = $difference < 0;
            $isDowngrade = $difference > 0;

            // Handle immediate charging for upgrades
            $upgradeCheckoutUrl = null;
            if ($isUpgrade && $mode === ProrationMode::IMMEDIATE) {
                $upgradeCheckoutUrl = $this->handleUpgradeCharge(abs($difference), $oldPlanId);
            }

            // Record credit ledger entry (only for credit mode and if customer exists and prorated value > 0)
            if ($this->customer_id !== null && $proratedCredit > 0 && $mode === ProrationMode::CREDIT) {
                $this->recordSwapCredit(
                    oldPlanId: $oldPlanId,
                    newPlanId: $newPlanId,
                    proratedAmount: $proratedCredit,
                    oldPrice: $oldPrice,
                    newPrice: $this->total_price,
                );
            }

            SubscriptionUpdated::dispatch($this, [
                'old_plan_id' => $oldPlanId,
                'new_plan_id' => $newPlanId,
                'old_price' => $oldPrice,
                'new_price' => $this->total_price,
                'prorated_credit' => $proratedCredit,
                'proration_mode' => $mode,
                'swap_type' => $isUpgrade ? 'upgrade' : ($isDowngrade ? 'downgrade' : 'lateral'),
                'upgrade_checkout_url' => $upgradeCheckoutUrl,
            ]);

            SubscriptionSwapped::dispatch($this, [
                'old_plan_id' => $oldPlanId,
                'new_plan_id' => $newPlanId,
                'proration_mode' => $mode,
                'upgrade_checkout_url' => $upgradeCheckoutUrl,
            ]);

            return $this;
        });
    }

    /**
     * Calculate the prorated remaining value of the current billing period.
     *
     * Formula: (remaining_days / total_billing_days) * total_price
     *
     * If no billing cycle data is available, returns 0 (no credit).
     *
     * @return float The prorated credit amount
     */
    protected function calculateProratedCredit(): float
    {
        // If no renews_at, we can't compute proration
        if ($this->renews_at === null) {
            return 0.0;
        }

        $now = now();
        $renewsAt = $this->renews_at instanceof Carbon
            ? $this->renews_at
            : Carbon::parse($this->renews_at);

        // If already past renewal date, no remaining value
        if ($now->gte($renewsAt)) {
            return 0.0;
        }

        // Estimate billing period from renews_at minus the plan interval.
        // Fall back to created_at only for the very first billing cycle.
        $intervalDays = $this->getBillingIntervalDays();
        $periodStart = $renewsAt->copy()->subDays($intervalDays);

        $totalDays = max(1, $periodStart->diffInDays($renewsAt, true));
        $remainingDays = max(0, $now->diffInDays($renewsAt, true));

        $proratedAmount = ($remainingDays / $totalDays) * (float) $this->total_price;

        // Round to 8 decimal places to match bcmath precision standard used elsewhere
        return round($proratedAmount, 8);
    }

    /**
     * Get the billing interval in days.
     *
     * Uses the plan's interval_days if available, otherwise falls back to
     * the configured default interval, and finally to 30 days.
     *
     * @return int The billing interval in days
     */
    protected function getBillingIntervalDays(): int
    {
        // First try to get from the plan relationship
        if ($this->relationLoaded('plan') && $this->plan !== null) {
            return (int) ($this->plan->interval_days
                ?? config('cashier-nowpayments.proration.default_interval_days', 30));
        }

        // Fall back to config, then to default
        return (int) config('cashier-nowpayments.proration.default_interval_days', 30);
    }

    /**
     * Handle immediate charging for upgrades.
     *
     * When a user upgrades to a more expensive plan, this method creates
     * a checkout URL for the price difference. The checkout URL can be
     * used to redirect the customer to complete the payment.
     *
     * @param float $chargeAmount The amount to charge
     * @param string $oldPlanId The plan ID being swapped away from
     * @return string|null The checkout URL, or null if failed
     */
    protected function handleUpgradeCharge(float $chargeAmount, string $oldPlanId): ?string
    {
        if ($chargeAmount <= 0 || $this->customer_id === null) {
            return null;
        }

        try {
            $customer = $this->customer;

            // First try to apply credits against the upgrade charge
            $creditResult = $customer->applyCredits($chargeAmount);
            $covered = (float) $creditResult['covered'];
            $remaining = (float) $creditResult['remaining'];

            // If fully covered by credits, no checkout needed
            if ($remaining <= 0) {
                return null;
            }

            // Create checkout session for the remaining amount
            $checkoutService = app(CheckoutService::class);

            $session = $checkoutService->createSession(
                amount: $remaining,
                currency: $this->currency,
                options: [
                    'description' => "Upgrade charge: Plan swap {$oldPlanId} (difference)",
                    'order_id' => 'UPGRADE-' . \Illuminate\Support\Str::ulid()->toString(),
                    'metadata' => [
                        'upgrade_charge' => true,
                        'subscription_id' => $this->id,
                        'old_plan_id' => $oldPlanId,
                        'credits_applied' => $covered,
                        'original_charge_amount' => $chargeAmount,
                    ],
                ]
            );

            // Generate checkout URL for customer redirect
            return $customer->billable->checkoutUrl($remaining, $this->currency, [
                'order_id' => $session->getId(),
                'description' => "Upgrade charge: Plan swap {$oldPlanId}",
                'metadata' => [
                    'upgrade_charge' => true,
                    'subscription_id' => $this->id,
                    'credits_applied' => $covered,
                ],
            ]);
        } catch (\Throwable $e) {
            report("Failed to create upgrade charge checkout: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Record a credit entry for a plan swap.
     *
     * Uses database-level atomic operations to prevent race conditions
     * on the running balance. Records both the credit amount and the
     * new plan's price for reconciliation.
     *
     * @param string $oldPlanId The plan being swapped away from
     * @param string $newPlanId The plan being swapped to
     * @param float $proratedAmount The prorated remaining value to credit
     * @param float $oldPrice The full price of the old plan
     * @param float $newPrice The full price of the new plan
     * @return void
     */
    protected function recordSwapCredit(
        string $oldPlanId,
        string $newPlanId,
        float $proratedAmount,
        float $oldPrice,
        float $newPrice,
    ): void {
        $creditModel = config('cashier-nowpayments.model.credit', Credit::class);

        // Compute balance atomically: SELECT SUM(amount) + new_amount in one query
        $currentBalance = (string) $this->credits()->sum('amount');
        $balanceBefore = $currentBalance !== '0' ? $currentBalance : '0';

        // Use number_format for precise bcmath string conversion
        $proratedStr = number_format($proratedAmount, 8, '.', '');
        $balanceAfter = bcadd($balanceBefore, $proratedStr, 8);

        $difference = bcsub(
            number_format($oldPrice, 8, '.', ''),
            number_format($newPrice, 8, '.', ''),
            8,
        );

        /** @var Credit $credit */
        $credit = new $creditModel();

        $credit->fill([
            'customer_id' => $this->customer_id,
            'subscription_id' => $this->id,
            'type' => 'swap',
            'amount' => $proratedAmount,
            'currency' => $this->currency,
            'balance_before' => $balanceBefore,
            'balance_after' => $balanceAfter,
            'old_plan_id' => $oldPlanId,
            'new_plan_id' => $newPlanId,
            'description' => "Prorated credit from plan swap: {$oldPlanId} → {$newPlanId}",
            'metadata' => [
                'old_price' => $oldPrice,
                'new_price' => $newPrice,
                'prorated_amount' => $proratedAmount,
                'difference' => $difference,
                'swap_type' => $difference > 0 ? 'downgrade' : ($difference < 0 ? 'upgrade' : 'lateral'),
            ],
            'expires_at' => config('cashier-nowpayments.proration.credits_expire', true)
                ? $this->renews_at
                : null,
        ]);

        $credit->save();
    }

    /**
     * Increment the quantity of the subscription.
     *
     * @param int $count The number of units to increment (default: 1)
     * @return $this
     */
    public function incrementQuantity(int $count = 1): self
    {
        $this->updateQuantity($this->quantity + $count);

        return $this;
    }

    /**
     * Decrement the quantity of the subscription.
     *
     * @param int $count The number of units to decrement (default: 1)
     * @return $this
     */
    public function decrementQuantity(int $count = 1): self
    {
        $this->updateQuantity($this->quantity - $count);

        return $this;
    }

    /**
     * Update the quantity of the subscription.
     *
     * Note: This updates the local record only. NOWPayments subscriptions
     * do not support quantity adjustments via API. If you need to change
     * the billed amount, use swap() to change to a different plan.
     *
     * @param int $quantity The new quantity
     * @return $this
     * @throws \InvalidArgumentException If quantity is less than 1
     */
    public function updateQuantity(int $quantity): self
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Subscription quantity must be at least 1.');
        }

        $this->quantity = $quantity;
        $this->save();

        return $this;
    }

    /**
     * Get the customer model class.
     *
     * @return class-string<Customer>
     */
    protected function getCustomerModel(): string
    {
        return config('cashier-nowpayments.model.customer', Customer::class);
    }

    /**
     * Get the subscription item model class.
     *
     * @return class-string<SubscriptionItem>
     */
    protected function getSubscriptionItemModel(): string
    {
        return config('cashier-nowpayments.model.subscription_item', SubscriptionItem::class);
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
     * Get the credit model class.
     *
     * @return class-string<Credit>
     */
    protected function getCreditModel(): string
    {
        return config('cashier-nowpayments.model.credit', Credit::class);
    }

    /**
     * Get the plan model class.
     *
     * @return class-string<Plan>
     */
    protected function getPlanModel(): string
    {
        return config('cashier-nowpayments.model.plan', Plan::class);
    }
}

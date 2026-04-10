<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use SerenityTechnologies\CashierNowPayments\Events\SubscriptionCreated;
use SerenityTechnologies\CashierNowPayments\Models\Customer;
use SerenityTechnologies\CashierNowPayments\Models\Subscription;
use SerenityTechnologies\CashierNowPayments\Models\SubscriptionItem;
use SerenityTechnologies\NowPayments\DTOs\Request\PlanRequest;
use SerenityTechnologies\NowPayments\DTOs\Request\SubscriptionRequest;
use SerenityTechnologies\NowPayments\Exceptions\NowPaymentsException;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class SubscriptionBuilder
{
    /**
     * The billable model.
     */
    protected Model $billable;

    /**
     * The customer model.
     */
    protected Customer $customer;

    /**
     * Set the subscription type.
     */
    protected string $type;

    /**
     * The NOWPayments plan ID.
     */
    protected int|string $planId;

    /**
     * Subscription quantity.
     */
    protected int $quantity = 1;

    /**
     * Trial days.
     */
    protected ?int $trialDays = null;

    /**
     * Trial end date.
     */
    protected ?Carbon $trialEndsAt = null;

    /**
     * Additional metadata.
     */
    protected array $metadata = [];

    /**
     * Sub-partner ID (alternative to email for NOWPayments API).
     */
    protected ?int $subPartnerId = null;

    /**
     * Create a new subscription builder instance.
     */
    public function __construct(Model $billable, Customer $customer, string $type, int|string $planId)
    {
        $this->billable = $billable;
        $this->customer = $customer;
        $this->type = $type;
        $this->planId = is_int($planId) ? (string) $planId : $planId;
    }

    /**
     * Set the subscription quantity.
     */
    public function quantity(int $quantity): self
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Subscription quantity must be at least 1.');
        }

        $this->quantity = $quantity;

        return $this;
    }

    /**
     * Set the trial period in days.
     */
    public function withTrialDays(int $days): self
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Trial days must be at least 1.');
        }

        $this->trialDays = $days;
        $this->trialEndsAt = null;

        return $this;
    }

    /**
     * Set the trial end date.
     */
    public function withTrialUntil(Carbon $trialEndsAt): self
    {
        $this->trialEndsAt = $trialEndsAt;
        $this->trialDays = null;

        return $this;
    }

    /**
     * Set additional metadata.
     */
    public function withMetadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    /**
     * Set the sub-partner ID (alternative to providing email).
     *
     * NOWPayments requires at least one of email or sub_partner_id.
     * Use this when you don't have a subscriber email but have a
     * sub-partner account.
     */
    public function withSubPartnerId(int $subPartnerId): self
    {
        $this->subPartnerId = $subPartnerId;

        return $this;
    }

    /**
     * Create the subscription.
     * @throws NowPaymentsException
     */
    public function create(): Subscription
    {
        $plan = $this->getPlan();

        // NOWPayments requires at least one of email or sub_partner_id
        $email = $this->resolveSubscriberEmail();

        if ($email === null && $this->subPartnerId === null) {
            throw new \InvalidArgumentException(
                'NOWPayments requires a valid email or sub_partner_id to create a subscription. '
                . 'Use ->withSubPartnerId() or ensure your billable model has an email address.'
            );
        }

        $subscriptionRequest = new SubscriptionRequest(
            subscriptionPlanId: (int) $this->planId,
            subPartnerId: $this->subPartnerId,
            email: $email,
        );

        $response = NowPayments::createSubscription($subscriptionRequest);

        $subscription = $this->persistSubscription($response, $plan);

        SubscriptionCreated::dispatch($this->billable, $this->customer, $subscription, $response);

        return $subscription;
    }

    /**
     * Resolve the subscriber email for the NOWPayments API.
     *
     * NOWPayments requires at least one of email or sub_partner_id when
     * creating a subscription. We pull the email from the billable model
     * or the customer record as a fallback.
     */
    protected function resolveSubscriberEmail(): ?string
    {
        // Try billable model first
        if (method_exists($this->billable, 'getEmailForPasswordReset')) {
            $email = $this->billable->email ?? null;
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        // Try customer record
        if ($this->customer->email !== null && filter_var($this->customer->email, FILTER_VALIDATE_EMAIL)) {
            return $this->customer->email;
        }

        // Fall back to billable's getBillableEmail if available
        if (method_exists($this->billable, 'getBillableEmail')) {
            $email = $this->billable->getBillableEmail();
            if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    /**
     * Get the subscription plan.
     *
     * @throws \InvalidArgumentException|NowPaymentsException If the plan does not exist.
     */
    protected function getPlan(): \SerenityTechnologies\NowPayments\DTOs\Response\PlanResponse
    {
        try {
            return NowPayments::getPlan((int) $this->planId);
        } catch (NowPaymentsException $e) {
            // If plan not found, throw descriptive error instead of creating one with 0 amount
            if (str_contains($e->getMessage(), 'not found') || $e->getCode() === 404) {
                throw new \InvalidArgumentException(
                    "Plan '{$this->planId}' does not exist in NOWPayments. Create it via \$user->newPlan()->create() first."
                );
            }
            // Re-throw other exceptions (network errors, auth failures, etc.)
            throw $e;
        }
    }

    /**
     * Persist the subscription to the database.
     */
    protected function persistSubscription(
        \SerenityTechnologies\NowPayments\DTOs\Response\SubscriptionResponse $response,
        \SerenityTechnologies\NowPayments\DTOs\Response\PlanResponse $plan
    ): Subscription {
        $subscriptionModel = config('cashier-nowpayments.model.subscription', Subscription::class);

        /** @var Subscription $subscription */
        $subscription = new $subscriptionModel();

        $trialEndsAt = $this->trialEndsAt;
        if ($this->trialDays !== null) {
            $trialEndsAt = now()->addDays($this->trialDays);
        }

        $subscription->fill([
            'customer_id' => $this->customer->id,
            'type' => $this->type,
            'nowpayments_plan_id' => (string) $this->planId,
            'nowpayments_subscription_id' => $response->id,
            'status' => $response->status ?? 'waiting_pay',
            'currency' => $plan->currency ?? config('cashier-nowpayments.currency', 'usd'),
            'total_price' => $plan->price ?? 0,
            'quantity' => $this->quantity,
            'trial_ends_at' => $trialEndsAt,
            'metadata' => $this->metadata,
        ]);

        $subscription->save();

        // Create subscription item
        $itemModel = config('cashier-nowpayments.model.subscription_item', SubscriptionItem::class);

        /** @var SubscriptionItem $item */
        $item = new $itemModel();

        $item->fill([
            'subscription_id' => $subscription->id,
            'nowpayments_plan_id' => (string) $this->planId,
            'description' => $plan->name ?? null,
            'amount' => $plan->price ?? 0,
            'quantity' => $this->quantity,
        ]);

        $item->save();

        return $subscription;
    }
}

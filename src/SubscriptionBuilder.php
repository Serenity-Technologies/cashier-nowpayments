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
    protected string $planId;

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
     * Create a new subscription builder instance.
     */
    public function __construct(Model $billable, Customer $customer, string $type, string $planId)
    {
        $this->billable = $billable;
        $this->customer = $customer;
        $this->type = $type;
        $this->planId = $planId;
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
     * Create the subscription.
     * @throws NowPaymentsException
     */
    public function create(): Subscription
    {
        $plan = $this->getPlan();

        $subscriptionRequest = new SubscriptionRequest(
            subscriptionPlanId: (int) $this->planId,
        );

        $response = NowPayments::createSubscription($subscriptionRequest);

        $subscription = $this->persistSubscription($response, $plan);

        SubscriptionCreated::dispatch($this->billable, $this->customer, $subscription, $response);

        return $subscription;
    }

    /**
     * Get the subscription plan.
     *
     * @throws \InvalidArgumentException If the plan does not exist.
     */
    protected function getPlan(): \SerenityTechnologies\NowPayments\DTOs\Response\PlanResponse
    {
        try {
            return NowPayments::getPlan($this->planId);
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

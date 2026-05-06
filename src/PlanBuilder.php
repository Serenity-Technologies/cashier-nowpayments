<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments;

use SerenityTechnologies\CashierNowPayments\Models\Plan;
use SerenityTechnologies\NowPayments\DTOs\Request\PlanRequest;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

/**
 * Fluent builder for creating and managing subscription plans.
 *
 * Plans are global catalog items — not attached to any specific billable model.
 * Use this builder to create plans on NOWPayments and persist them locally.
 *
 * Usage:
 *   PlanBuilder::make('monthly-pro')
 *       ->withName('Monthly Pro Plan')
 *       ->withAmount(29.99)
 *       ->create();
 *
 * Or statically via the Plan model:
 *   Plan::create('monthly-pro', amount: 29.99);
 */
class PlanBuilder
{
    /**
     * Plan ID.
     */
    protected string $planId;

    /**
     * Plan name.
     */
    protected string $name;

    /**
     * Plan amount.
     */
    protected float $amount = 0;

    /**
     * Plan currency.
     */
    protected string $currency;

    /**
     * Billing interval in days.
     */
    protected int $intervalDays = 30;

    /**
     * Success URL.
     */
    protected ?string $successUrl = null;

    /**
     * Cancel URL.
     */
    protected ?string $cancelUrl = null;

    /**
     * Additional metadata.
     */
    protected array $metadata = [];

    /**
     * Create a new plan builder instance.
     */
    public function __construct(string $planId)
    {
        $this->planId = $planId;
        $this->name = $planId;
        $this->currency = config('cashier-nowpayments.currency', 'usd');
    }

    /**
     * Create a new plan builder instance (static factory).
     */
    public static function make(string $planId): self
    {
        return new self($planId);
    }

    /**
     * Set the plan name.
     */
    public function withName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Set the plan amount.
     */
    public function withAmount(float $amount): self
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * Set the plan currency.
     */
    public function withCurrency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    /**
     * Set the billing interval in days.
     */
    public function withIntervalDays(int $days): self
    {
        $this->intervalDays = $days;

        return $this;
    }

    /**
     * Set the success URL.
     */
    public function withSuccessUrl(string $url): self
    {
        $this->successUrl = $url;

        return $this;
    }

    /**
     * Set the cancel URL.
     */
    public function withCancelUrl(string $url): self
    {
        $this->cancelUrl = $url;

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
     * Create the plan via NOWPayments API and persist it locally.
     */
    public function create(): \SerenityTechnologies\NowPayments\DTOs\Response\PlanResponse
    {
        $request = new PlanRequest(
            title: $this->name,
            intervalDay: $this->intervalDays,
            amount: $this->amount,
            currency: $this->currency,
            successUrl: $this->successUrl,
            cancelUrl: $this->cancelUrl,
        );

        $response = NowPayments::createPlan($request);

        // Persist the plan locally for tracking
        $this->persistPlan($response);

        return $response;
    }

    /**
     * Persist the plan to the local database.
     */
    protected function persistPlan(\SerenityTechnologies\NowPayments\DTOs\Response\PlanResponse $response): void
    {
        $planModel = config('cashier-nowpayments.model.plan', Plan::class);

        /** @var Plan|null $existingPlan */
        $existingPlan = $planModel::where('nowpayments_plan_id', $response->id)->first();

        if ($existingPlan !== null) {
            $existingPlan->update([
                'name' => $response->title ?? $this->name,
                'amount' => $response->amount ?? $this->amount,
                'currency' => $response->currency ?? $this->currency,
                'interval_days' => $response->interval_days ?? $this->intervalDays,
                'status' => $response->status ?? 'active',
                'success_url' => $this->successUrl,
                'cancel_url' => $this->cancelUrl,
            ]);

            return;
        }

        /** @var Plan $plan */
        $plan = new $planModel();

        $plan->fill([
            'nowpayments_plan_id' => $response->id,
            'name' => $response->title ?? $this->name,
            'amount' => $response->amount ?? $this->amount,
            'currency' => $response->currency ?? $this->currency,
            'interval_days' => $response->interval_days ?? $this->intervalDays,
            'status' => $response->status ?? 'active',
            'success_url' => $this->successUrl,
            'cancel_url' => $this->cancelUrl,
            'metadata' => $this->metadata,
        ]);

        $plan->save();
    }
}

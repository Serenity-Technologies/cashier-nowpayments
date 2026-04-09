<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use SerenityTechnologies\NowPayments\Facades\NowPayments;

trait ManagesPlans
{
    /**
     * List subscription plans.
     */
    public static function listPlans(array $filters = []): \SerenityTechnologies\NowPayments\DTOs\Response\PlanListResponse
    {
        return NowPayments::listPlans($filters);
    }

    /**
     * Update a subscription plan.
     */
    public static function updatePlan(string $planId, array $data): \SerenityTechnologies\NowPayments\DTOs\Response\PlanResponse
    {
        return NowPayments::updatePlan($planId, $data);
    }
}

<?php
/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

use SerenityTechnologies\NowPayments\DTOs\Request\UpdatePlanRequest;
use SerenityTechnologies\NowPayments\Exceptions\NowPaymentsException;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

/**
 * Provides static methods for listing and updating subscription plans.
 *
 * Plans are global catalog items — not attached to any specific user.
 * These methods can be called directly on any class using this trait.
 */
trait ManagesPlans
{
    /**
     * List subscription plans from the NOWPayments API.
     *
     * @param array $filters Optional filters to apply
     * @return \SerenityTechnologies\NowPayments\DTOs\Response\PlanListResponse
     */
    public static function listPlans(array $filters = []): \SerenityTechnologies\NowPayments\DTOs\Response\PlanListResponse
    {
        return NowPayments::listPlans($filters);
    }

    /**
     * Update a subscription plan on the NOWPayments API.
     *
     * @param string $planId The plan ID to update
     * @param UpdatePlanRequest $data The update request data
     * @return \SerenityTechnologies\NowPayments\DTOs\Response\PlanResponse
     * @throws NowPaymentsException
     */
    public static function updatePlan(string $planId, UpdatePlanRequest $data): \SerenityTechnologies\NowPayments\DTOs\Response\PlanResponse
    {
        return NowPayments::updatePlan($planId, $data);
    }
}

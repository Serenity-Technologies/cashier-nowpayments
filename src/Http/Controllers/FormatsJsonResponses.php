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

use Illuminate\Http\JsonResponse;

/**
 * Provides standardized JSON response helpers for API controllers.
 */
trait FormatsJsonResponses
{
    /**
     * Return a standardized error JSON response.
     */
    protected function errorResponse(string $message, int $status = 500): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
        ], $status);
    }

    /**
     * Return a standardized success JSON response.
     */
    protected function successResponse(array $data = []): JsonResponse
    {
        return response()->json(array_merge(['success' => true], $data));
    }
}

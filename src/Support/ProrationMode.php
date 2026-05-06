<?php

declare(strict_types=1);

/**
 * @author Kwadwo Kyeremeh <kyerematics@gmail.com>
 * @copyright 2026 Serenity Technologies
 * @license MIT License
 * @package serenity_technologies/cashier-nowpayments
 * @version 1.2.9
 */


namespace SerenityTechnologies\CashierNowPayments\Support;

/**
 * Enum defining proration modes for subscription plan swaps.
 *
 * @package SerenityTechnologies\CashierNowPayments\Support
 */
final class ProrationMode
{
    /**
     * Issue prorated credit for unused time on the old plan.
     *
     * The customer receives a credit for the remaining days in the billing
     * cycle, which can be applied against future charges.
     */
    public const CREDIT = 'credit';

    /**
     * Charge or credit the difference immediately.
     *
     * For upgrades (more expensive plan), the customer is charged the
     * prorated difference immediately. For downgrades, a credit is issued.
     */
    public const IMMEDIATE = 'immediate';

    /**
     * No proration - charge full amount at next renewal.
     *
     * The customer keeps access until the end of the current billing
     * period and is charged the full new plan amount at renewal.
     */
    public const END_OF_PERIOD = 'end_of_period';

    /**
     * No proration, no credits issued.
     *
     * Similar to end_of_period but explicitly disables all credit
     * calculations and ledger entries.
     */
    public const NONE = 'none';

    /**
     * Get all valid proration modes.
     *
     * @return string[]
     */
    public static function all(): array
    {
        return [
            self::CREDIT,
            self::IMMEDIATE,
            self::END_OF_PERIOD,
            self::NONE,
        ];
    }

    /**
     * Validate that a mode is valid.
     *
     * @param string $mode
     * @return void
     * @throws \InvalidArgumentException
     */
    public static function validate(string $mode): void
    {
        if (!in_array($mode, self::all(), true)) {
            throw new \InvalidArgumentException(
                "Invalid proration mode: {$mode}. Valid modes are: " . implode(', ', self::all())
            );
        }
    }
}

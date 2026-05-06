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

use Illuminate\Database\Eloquent\Relations\HasMany;
use SerenityTechnologies\CashierNowPayments\Models\Payout;
use SerenityTechnologies\CashierNowPayments\PayoutBuilder;
use SerenityTechnologies\NowPayments\DTOs\Request\PayoutAddressRequest;
use SerenityTechnologies\NowPayments\Exceptions\NowPaymentsException;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

trait ManagesPayouts
{
    /**
     * Begin creating a new payout.
     */
    public function payout(): PayoutBuilder
    {
        $customer = $this->createOrGetCustomer();

        return new PayoutBuilder($this, $customer);
    }

    /**
     * Get all payouts for the billable model.
     */
    public function payouts(): HasMany
    {
        $customer = $this->createOrGetCustomer();

        return $customer->payouts();
    }

    /**
     * Get payout history from NOWPayments API.
     *
     * Note: NOWPayments API does not support filtering by customer/order ID
     * for payouts. Results are scoped locally to the billable model's
     * payouts after retrieval if no filters are provided.
     */
    public function remotePayouts(array $filters = []): \SerenityTechnologies\NowPayments\DTOs\Response\PayoutListResponse
    {
        $response = NowPayments::listPayouts($filters);

        // If the response contains payouts, filter locally by this billable's
        // customer payouts when no explicit customer filter is provided.
        // This prevents data leakage in multi-tenant setups.
        if (isset($response->data) && is_array($response->data) && !isset($filters['customer_id'])) {
            $customer = $this->createOrGetCustomer();
            $localPayoutIds = $this->payouts()->pluck('nowpayments_payout_id')->toArray();

            $response->data = array_filter($response->data, function ($payout) use ($localPayoutIds) {
                $payoutId = $payout->id ?? $payout->batch_withdrawal_id ?? null;
                return $payoutId !== null && in_array($payoutId, $localPayoutIds, true);
            });
        }

        return $response;
    }

    /**
     * Validate a payout address.
     * @throws NowPaymentsException
     */
    public function validatePayoutAddress(string $address, string $currency, ?string $extraId = null): bool
    {
        $request = new PayoutAddressRequest(
            address: $address,
            currency: $currency,
            extraId: $extraId,
        );

        return NowPayments::validateAddress($request);
    }

    /**
     * Get minimum withdrawal amount for a coin.
     */
    public static function minimumWithdrawalAmount(string $coin): \SerenityTechnologies\NowPayments\DTOs\Response\MinWithdrawalAmountResponse
    {
        return NowPayments::getMinWithdrawalAmount($coin);
    }

    /**
     * Get payout fee estimate.
     * @throws NowPaymentsException
     */
    public static function payoutFeeEstimate(): \SerenityTechnologies\NowPayments\DTOs\Response\FeeEstimateResponse
    {
        return NowPayments::getPayoutFeeEstimate();
    }
}

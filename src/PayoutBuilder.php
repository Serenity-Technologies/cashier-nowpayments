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

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use SerenityTechnologies\CashierNowPayments\Events\PayoutCreated;
use SerenityTechnologies\CashierNowPayments\Models\Customer;
use SerenityTechnologies\CashierNowPayments\Models\Payout;
use SerenityTechnologies\NowPayments\DTOs\Request\PayoutRequest;
use SerenityTechnologies\NowPayments\DTOs\Request\PayoutWithdrawalItem;
use SerenityTechnologies\NowPayments\Facades\NowPayments;

class PayoutBuilder
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
     * Payout withdrawals.
     */
    protected array $withdrawals = [];

    /**
     * Payout description.
     */
    protected ?string $description = null;

    /**
     * Schedule execution time.
     */
    protected ?Carbon $executeAt = null;

    /**
     * Additional metadata.
     */
    protected array $metadata = [];

    /**
     * Create a new payout builder instance.
     */
    public function __construct(Model $billable, Customer $customer)
    {
        $this->billable = $billable;
        $this->customer = $customer;
    }

    /**
     * Add a withdrawal to the payout.
     */
    public function to(string $address, string $currency, float $amount, ?string $extraId = null): self
    {
        $this->withdrawals[] = new PayoutWithdrawalItem(
            address: $address,
            currency: $currency,
            amount: $amount,
            extraId: $extraId
        );

        return $this;
    }

    /**
     * Set the payout description.
     */
    public function withDescription(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Schedule the payout for later execution.
     */
    public function scheduledFor(Carbon $dateTime): self
    {
        $this->executeAt = $dateTime;

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
     * Create the payout via NOWPayments API.
     */
    public function create(): \SerenityTechnologies\NowPayments\DTOs\Response\PayoutResponse
    {
        if (empty($this->withdrawals)) {
            throw new \InvalidArgumentException('At least one withdrawal is required.');
        }

        $request = new PayoutRequest(
            withdrawals: $this->withdrawals,
            executeAt: $this->executeAt?->toIso8601String(),
            payoutDescription: $this->description,
            ipnCallbackUrl: route('cashier-nowpayments.webhook'),
        );

        $response = NowPayments::createPayout($request);

        PayoutCreated::dispatch($this->billable, $this->customer, $response);

        return $response;
    }

    /**
     * Create and persist the payout.
     */
    public function send(): Payout
    {
        $response = $this->create();

        return $this->persistPayout($response);
    }

    /**
     * Persist the payout to the database.
     */
    protected function persistPayout(\SerenityTechnologies\NowPayments\DTOs\Response\PayoutResponse $response): Payout
    {
        if (empty($response->withdrawals)) {
            throw new \RuntimeException('Payout response contains no withdrawals.');
        }

        $payoutModel = config('cashier-nowpayments.model.payout', Payout::class);

        /** @var Payout $payout */
        $payout = new $payoutModel();

        // Get first withdrawal for primary storage
        $withdrawal = $response->withdrawals[0];

        $payout->fill([
            'customer_id' => $this->customer->id,
            'billable_id' => $this->billable->getKey(),
            'billable_type' => $this->billable->getMorphClass(),
            'nowpayments_payout_id' => $response->id ?? $response->batchWithdrawalId ?? null,
            'batch_withdrawal_id' => $response->batchWithdrawalId ?? null,
            'status' => strtolower($response->status ?? 'creating'),
            'currency' => $withdrawal->currency ?? null,
            'amount' => $withdrawal->amount ?? 0,
            'address' => $withdrawal->address ?? null,
            'extra_id' => $withdrawal->extraId ?? null,
            'hash' => $response->hash ?? null,
            'error' => $response->error ?? null,
            'ipn_callback_url' => route('cashier-nowpayments.webhook'),
            'execute_at' => $this->executeAt,
            'metadata' => $this->metadata,
        ]);

        $payout->save();

        // Persist individual withdrawals if there are multiple
        if (count($response->withdrawals) > 1) {
            $this->persistWithdrawals($payout, $response->withdrawals);
        }

        return $payout;
    }

    /**
     * Persist individual withdrawal records for batch payouts.
     *
     * @param Payout $payout
     * @param array $withdrawals
     */
    protected function persistWithdrawals(Payout $payout, array $withdrawals): void
    {
        $withdrawalModelClass = \SerenityTechnologies\CashierNowPayments\Models\PayoutWithdrawal::class;

        foreach ($withdrawals as $withdrawal) {
            /** @var \SerenityTechnologies\CashierNowPayments\Models\PayoutWithdrawal $withdrawalRecord */
            $withdrawalRecord = new $withdrawalModelClass();

            $withdrawalRecord->fill([
                'payout_id' => $payout->id,
                'currency' => $withdrawal->currency ?? null,
                'amount' => $withdrawal->amount ?? 0,
                'address' => $withdrawal->address ?? null,
                'extra_id' => $withdrawal->extraId ?? null,
                'status' => strtolower($payout->status),
            ]);

            $withdrawalRecord->save();
        }
    }
}

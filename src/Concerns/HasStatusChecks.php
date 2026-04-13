<?php

declare(strict_types=1);

namespace SerenityTechnologies\CashierNowPayments\Concerns;

/**
 * Provides status-based query scopes and boolean checks.
 *
 * Models using this trait must define protected status array properties:
 * - `$successfulStatuses` — statuses considered successful
 * - `$pendingStatuses` — statuses considered pending
 * - `$failedStatuses` — statuses considered failed
 */
trait HasStatusChecks
{
    /**
     * Scope a query to only include successful records.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     */
    public function scopeSuccessful($query): void
    {
        $query->whereIn('status', $this->successfulStatuses);
    }

    /**
     * Scope a query to only include pending records.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     */
    public function scopePending($query): void
    {
        $query->whereIn('status', $this->pendingStatuses);
    }

    /**
     * Scope a query to only include failed records.
     *
     * @param \Illuminate\Database\Eloquent\Builder<self> $query
     */
    public function scopeFailed($query): void
    {
        $query->whereIn('status', $this->failedStatuses);
    }

    /**
     * Determine if the record is in a successful state.
     */
    public function isSuccessful(): bool
    {
        return in_array($this->status, $this->successfulStatuses, true);
    }

    /**
     * Determine if the record is in a pending state.
     */
    public function isPending(): bool
    {
        return in_array($this->status, $this->pendingStatuses, true);
    }

    /**
     * Determine if the record is in a failed state.
     */
    public function isFailed(): bool
    {
        return in_array($this->status, $this->failedStatuses, true);
    }
}

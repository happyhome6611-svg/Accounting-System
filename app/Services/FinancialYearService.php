<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinancialYearService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(Company $entity, array $data, User $user): FinancialYear
    {
        $overlaps = $entity->financialYears()->whereDate('starts_on', '<=', $data['ends_on'])->whereDate('ends_on', '>=', $data['starts_on'])->exists();
        if ($overlaps) {
            throw ValidationException::withMessages(['starts_on' => 'Financial Years cannot overlap for the same accounting entity.']);
        }

        return DB::transaction(function () use ($entity, $data, $user) {
            $year = $entity->financialYears()->create($data + ['status' => 'open', 'is_current' => false, 'created_by' => $user->id, 'updated_by' => $user->id]);
            $start = CarbonImmutable::parse($data['starts_on']);
            $end = CarbonImmutable::parse($data['ends_on']);
            for ($cursor = $start->startOfMonth(); $cursor->lte($end); $cursor = $cursor->addMonth()) {
                $year->periods()->create(['company_id' => $entity->id, 'name' => $cursor->format('M Y'), 'starts_on' => $cursor->max($start), 'ends_on' => $cursor->endOfMonth()->min($end), 'status' => 'open', 'created_by' => $user->id, 'updated_by' => $user->id]);
            }
            $this->audit->log('financial_year.created', $year, $entity->id, $user->id, null, $year->toArray());

            return $year->load('periods');
        });
    }

    public function close(FinancialYear $year, string $reason, User $user): FinancialYear
    {
        if ($year->status !== 'closing') {
            throw ValidationException::withMessages(['status' => 'A Financial Year must enter Closing before it can be Closed.']);
        }
        $this->ensureEnded($year);
        $this->ensureAllPeriodsClosed($year);
        $this->ensureEarlierYearsComplete($year);

        return DB::transaction(function () use ($year, $reason, $user) {
            $year->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->log('financial_year.closed', $year, $year->company_id, $user->id, ['status' => 'closing'], ['status' => 'closed', 'reason' => $reason]);

            return $year;
        });
    }

    public function beginClosing(FinancialYear $year, string $reason, User $user): FinancialYear
    {
        if ($year->status !== 'open') {
            throw ValidationException::withMessages(['status' => 'Only an open Financial Year can enter Closing.']);
        }
        $this->ensureEnded($year);
        $this->ensureAllPeriodsClosed($year);
        $this->ensureEarlierYearsComplete($year);

        return DB::transaction(function () use ($year, $reason, $user) {
            $year->update(['status' => 'closing', 'updated_by' => $user->id]);
            $this->audit->log('financial_year.closing', $year, $year->company_id, $user->id, ['status' => 'open'], ['status' => 'closing', 'reason' => $reason]);

            return $year;
        });
    }

    public function cancelClosing(FinancialYear $year, string $reason, User $user): FinancialYear
    {
        if ($year->status !== 'closing') {
            throw ValidationException::withMessages(['status' => 'Only a Financial Year in Closing can have closing cancelled.']);
        }

        return DB::transaction(function () use ($year, $reason, $user) {
            $year->update(['status' => 'open', 'reopened_at' => now(), 'reopened_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->log('financial_year.closing_cancelled', $year, $year->company_id, $user->id, ['status' => 'closing'], ['status' => 'open', 'reason' => $reason]);

            return $year;
        });
    }

    public function reopen(FinancialYear $year, string $reason, User $user): FinancialYear
    {
        if ($year->status !== 'closed') {
            throw ValidationException::withMessages(['status' => $year->status === 'filed' ? 'A filed Financial Year cannot be reopened; use an amendment/prior-period adjustment.' : 'Only a closed Financial Year can be reopened.']);
        }

        return DB::transaction(function () use ($year, $reason, $user) {
            $year->update(['status' => 'open', 'reopened_at' => now(), 'reopened_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->log('financial_year.reopened', $year, $year->company_id, $user->id, ['status' => 'closed'], ['status' => 'open', 'reason' => $reason]);

            return $year;
        });
    }

    public function markFiled(FinancialYear $year, string $reference, User $user): FinancialYear
    {
        if ($year->status !== 'closed') {
            throw ValidationException::withMessages(['status' => 'Only a closed Financial Year can be marked Filed.']);
        }
        $this->ensureEnded($year);
        $this->ensureEarlierYearsComplete($year);

        return DB::transaction(function () use ($year, $reference, $user) {
            $year->update(['status' => 'filed', 'filed_at' => now(), 'filed_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->log('financial_year.filed', $year, $year->company_id, $user->id, ['status' => 'closed'], ['status' => 'filed', 'reference' => $reference]);

            return $year;
        });
    }

    private function ensureEnded(FinancialYear $year): void
    {
        if (! $year->ends_on->isBefore(today())) {
            throw ValidationException::withMessages(['status' => "Financial Year {$year->name} has not ended and cannot enter closing."]);
        }
    }

    private function ensureAllPeriodsClosed(FinancialYear $year): void
    {
        if ($year->periods()->where('status', '!=', 'closed')->exists()) {
            throw ValidationException::withMessages(['periods' => 'All accounting periods must be closed before Financial Year closing can begin.']);
        }
    }

    private function ensureEarlierYearsComplete(FinancialYear $year): void
    {
        $unfinishedEarlierYear = FinancialYear::query()
            ->where('company_id', $year->company_id)
            ->whereDate('ends_on', '<', $year->starts_on)
            ->whereNotIn('status', ['closed', 'filed'])
            ->orderBy('starts_on')
            ->first();

        if ($unfinishedEarlierYear) {
            throw ValidationException::withMessages(['status' => "Earlier Financial Year {$unfinishedEarlierYear->name} must be Closed before {$year->name} can proceed."]);
        }
    }
}

<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class FinancialYearResolver
{
    public function resolve(Company $entity, string $date, ?int $financialYearId = null, ?int $periodId = null, bool $requireOpen = true): AccountingPeriod
    {
        $date = CarbonImmutable::parse($date);
        $years = $entity->financialYears()->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date);
        if ($financialYearId) {
            $years->whereKey($financialYearId);
        }
        $matches = $years->get();
        if ($matches->isEmpty()) {
            throw ValidationException::withMessages(['financial_year_id' => 'No Financial Year exists for '.$date->format('d M Y').'.']);
        }
        if ($matches->count() !== 1) {
            throw ValidationException::withMessages(['financial_year_id' => 'Multiple Financial Years contain this date; select one explicitly.']);
        }
        $year = $matches->first();
        if ($requireOpen && $year->status !== 'open') {
            throw ValidationException::withMessages(['financial_year_id' => "This transaction date belongs to Financial Year {$year->name}, which is ".ucfirst($year->status).'. Create a Prior-Period Adjustment or obtain an authorised reopen.']);
        }
        $periods = $year->periods()->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date);
        if ($periodId) {
            $periods->whereKey($periodId);
        }
        $periodMatches = $periods->get();
        if ($periodMatches->count() !== 1) {
            throw ValidationException::withMessages(['accounting_period_id' => $periodMatches->isEmpty() ? 'No Accounting Period contains the transaction date in the selected Financial Year.' : 'Multiple Accounting Periods contain the transaction date.']);
        }
        $period = $periodMatches->first();
        if ($requireOpen && $period->status !== 'open') {
            throw ValidationException::withMessages(['accounting_period_id' => "This transaction date belongs to {$period->name}, but that Accounting Period is Closed."]);
        }

        return $period;
    }
}

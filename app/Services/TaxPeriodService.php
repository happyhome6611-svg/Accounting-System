<?php

namespace App\Services;

use App\Models\Company;
use App\Models\TaxPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaxPeriodService
{
    public function __construct(private AccountingLockService $authorization, private TaxReportService $reports, private AuditLogger $audit) {}

    public function prepare(Company $company, TaxPeriod $period, User $user): void
    {
        $this->authorization->authorize($company, $user);
        DB::transaction(function () use ($company, $period, $user) {
            $period = TaxPeriod::where('company_id', $company->id)->lockForUpdate()->findOrFail($period->id);
            if (! in_array($period->status, ['open', 'amended'], true)) {
                throw ValidationException::withMessages(['tax_period' => 'Only an Open or Amended Tax Period can be prepared.']);
            }
            $before = $period->toArray();
            $period->update(['status' => 'prepared', 'prepared_snapshot' => $this->reports->summary($company, ['tax_period_id' => $period->id]), 'prepared_by' => $user->id, 'prepared_at' => now(), 'updated_by' => $user->id]);
            $this->audit->log('tax_period.prepared', $period, $company->id, $user->id, $before, $period->fresh()->toArray());
        });
    }

    public function file(Company $company, TaxPeriod $period, User $user): void
    {
        $this->authorization->authorize($company, $user);
        DB::transaction(function () use ($company, $period, $user) {
            $period = TaxPeriod::where('company_id', $company->id)->lockForUpdate()->findOrFail($period->id);
            if ($period->status !== 'prepared') {
                throw ValidationException::withMessages(['tax_period' => 'Prepare the Tax Period before marking it Filed.']);
            }
            $period->update(['status' => 'filed', 'filed_by' => $user->id, 'filed_at' => now(), 'updated_by' => $user->id]);
            $this->audit->log('tax_period.filed', $period, $company->id, $user->id);
        });
    }
}

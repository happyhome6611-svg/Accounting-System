<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingPeriodService
{
    public function __construct(private AuditLogger $audit) {}

    public function close(AccountingPeriod $period, string $reason, User $user): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $reason, $user) {
            $year = $period->financialYear()->firstOrFail();
            if ($period->status !== 'open') {
                throw ValidationException::withMessages(['period' => 'Only an open period can be closed.']);
            }
            if ($year->status !== 'open') {
                throw ValidationException::withMessages(['period' => "Accounting Period {$period->name} cannot be closed while Financial Year {$year->name} is {$year->status}."]);
            }
            if ($period->ends_on->isAfter(today())) {
                throw ValidationException::withMessages(['period' => "Accounting Period {$period->name} has not ended and cannot be closed."]);
            }
            $period->update(['status' => 'closed', 'locked_at' => now(), 'updated_by' => $user->id]);
            $this->audit->log('accounting_period.closed', $period, $period->company_id, $user->id, ['status' => 'open'], ['status' => 'closed', 'reason' => $reason]);

            return $period;
        });
    }

    public function reopen(AccountingPeriod $period, string $reason, User $user): AccountingPeriod
    {
        $year = $period->financialYear()->firstOrFail();
        if ($period->status !== 'closed') {
            throw ValidationException::withMessages(['period' => 'Only a closed Accounting Period can be reopened.']);
        }
        if ($year->status !== 'open') {
            throw ValidationException::withMessages(['period' => "Accounting Period {$period->name} cannot be reopened while Financial Year {$year->name} is {$year->status}."]);
        }

        return DB::transaction(function () use ($period, $reason, $user) {
            $period->update(['status' => 'open', 'locked_at' => null, 'reopened_at' => now(), 'reopened_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->log('accounting_period.reopened', $period, $period->company_id, $user->id, ['status' => 'closed'], ['status' => 'open', 'reason' => $reason]);

            return $period;
        });
    }
}

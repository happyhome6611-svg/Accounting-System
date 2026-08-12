<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingPeriodService
{
    public function __construct(private AuditLogger $audit) {}

    public function close(AccountingPeriod $period, User $user): AccountingPeriod
    {
        return DB::transaction(function () use ($period, $user) {
            if ($period->status !== 'open') {
                throw ValidationException::withMessages(['period' => 'Only an open period can be closed.']);
            }$period->update(['status' => 'closed', 'locked_at' => now(), 'updated_by' => $user->id]);
            $this->audit->log('accounting_period.closed', $period, $period->company_id, $user->id, ['status' => 'open'], ['status' => 'closed']);

            return $period;
        });
    }
}

<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AccountingLockService
{
    public function assertPostingAllowed(Company $company, string $date, User $user, string $journalType = 'standard', ?string $reason = null): bool
    {
        $locks = Company::whereKey($company->id)->firstOrFail(['bookkeeping_lock_date', 'adviser_lock_date']);
        $lockDate = collect([$locks->bookkeeping_lock_date, $locks->adviser_lock_date])
            ->filter()
            ->map(fn ($value) => CarbonImmutable::parse($value)->toDateString())
            ->max();
        if (! $lockDate || $date > $lockDate) {
            return false;
        }
        $overrideType = in_array($journalType, ['adjusting', 'prior_period_adjustment', 'reversing', 'closing'], true);
        if (! $overrideType || ! $this->isAccountant($company, $user) || mb_strlen(trim((string) $reason)) < 10) {
            throw ValidationException::withMessages(['transaction_date' => 'Posting date is on or before an Accounting Lock Date. An authorised adjustment with a reason is required.']);
        }

        return true;
    }

    public function update(Company $company, array $data, User $user, AuditLogger $audit): void
    {
        $this->authorize($company, $user);
        DB::transaction(function () use ($company, $data, $user, $audit) {
            $company = Company::lockForUpdate()->findOrFail($company->id);
            $before = $company->only(['bookkeeping_lock_date', 'adviser_lock_date']);
            $company->update($data + ['updated_by' => $user->id]);
            $audit->log('accounting_lock.updated', $company, $company->id, $user->id, $before, $company->fresh()->only(['bookkeeping_lock_date', 'adviser_lock_date']));
        });
    }

    public function authorize(Company $company, User $user): void
    {
        abort_unless($this->isAccountant($company, $user), 403);
    }

    public function isAccountant(Company $company, User $user): bool
    {
        return $user->companies()->whereKey($company->id)->wherePivotIn('role', ['owner', 'admin', 'accountant', 'adviser'])->exists();
    }
}

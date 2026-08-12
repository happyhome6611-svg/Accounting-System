<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompanyMaintenanceService
{
    public function __construct(private CompanyDeletionService $activity, private AuditLogger $audit) {}

    public function update(Company $company, array $data, User $user): Company
    {
        $this->owner($company, $user);
        if (! $this->activity->isEligible($company)) {
            foreach (['country_id', 'base_currency_id'] as $field) {
                if ((int) $data[$field] !== (int) $company->{$field}) {
                    throw ValidationException::withMessages([$field => 'This accounting-critical setting cannot be changed after business or accounting activity exists.']);
                }
            }
        }

        return DB::transaction(function () use ($company, $data, $user) {
            $old = $company->toArray();
            $company->update([...$data, 'updated_by' => $user->id]);
            $this->audit->log('company.updated', $company, $company->id, $user->id, $old, $company->fresh()->toArray());

            return $company->fresh();
        });
    }

    public function setActive(Company $company, bool $active, User $user): Company
    {
        $this->owner($company, $user);

        return DB::transaction(function () use ($company, $active, $user) {
            $company->update(['is_active' => $active, 'updated_by' => $user->id]);
            $this->audit->log($active ? 'company.reactivated' : 'company.deactivated', $company, $company->id, $user->id, null, ['is_active' => $active]);

            return $company;
        });
    }

    private function owner(Company $company, User $user): void
    {
        abort_unless($user->companies()->whereKey($company->id)->value('company_user.role') === 'owner', 403);
    }
}

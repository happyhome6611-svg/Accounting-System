<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompanyMaintenanceService
{
    public function __construct(private EntityActivityService $activity, private AuditLogger $audit) {}

    public function update(Company $company, array $data, User $user): Company
    {
        $this->owner($company, $user);

        return DB::transaction(function () use ($company, $data, $user) {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            if (! $this->activity->isUnused($locked)) {
                foreach (['country_id', 'base_currency_id'] as $field) {
                    if ((int) $data[$field] !== (int) $locked->{$field}) {
                        $message = 'Country / Tax Jurisdiction and Base Currency cannot be changed after accounting or business activity exists.';
                        throw ValidationException::withMessages([$field => $message]);
                    }
                }
            }
            $old = $locked->toArray();
            $locked->update([...$data, 'updated_by' => $user->id]);
            if ((int) $old['country_id'] !== (int) $locked->country_id) {
                $locked->taxYears()->update(['country_id' => $locked->country_id, 'updated_by' => $user->id]);
            }
            $this->audit->log('company.updated', $locked, $locked->id, $user->id, $old, $locked->fresh()->toArray());

            return $locked->fresh();
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

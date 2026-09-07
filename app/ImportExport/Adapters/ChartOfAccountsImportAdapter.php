<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChartOfAccountsImportAdapter extends AbstractAdapter
{
    public function __construct(private AuditLogger $audit) {}

    public function type(): string
    {
        return 'chart_of_accounts';
    }

    public function fields(): array
    {
        return ['code' => ['label' => 'Account Code', 'required' => true, 'aliases' => ['account_code', 'number']], 'name' => ['label' => 'Account Name', 'required' => true, 'aliases' => ['account_name']], 'type' => ['label' => 'Account Type', 'required' => true, 'aliases' => ['class', 'account_class']], 'normal_balance' => ['label' => 'Normal Balance', 'required' => false, 'aliases' => ['balance_side']], 'parent_code' => ['label' => 'Parent Account Code', 'required' => false, 'aliases' => ['parent']], 'active' => ['label' => 'Active', 'required' => false, 'aliases' => ['status']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        if (! in_array($values['type'] ?? null, ['asset', 'liability', 'equity', 'income', 'expense'], true)) {
            $errors[] = 'Account Type is invalid.';
        }
        if (! empty($values['parent_code']) && ! $company->accounts()->where('code', $values['parent_code'])->exists()) {
            $errors[] = 'Parent Account Code was not found.';
        }
        $duplicate = $company->accounts()->withTrashed()->where('code', $values['code'] ?? '')->exists() ? 'exact_duplicate' : 'not_duplicate';

        return $this->result($errors, [], $duplicate);
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        if ($company->accounts()->withTrashed()->where('code', $values['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Account Code already exists.']);
        }

        return DB::transaction(function () use ($company, $values, $user) {
            $type = $values['type'];
            $account = $company->accounts()->create(['code' => $values['code'], 'name' => $values['name'], 'type' => $type, 'normal_balance' => $values['normal_balance'] ?: (in_array($type, ['asset', 'expense'], true) ? 'debit' : 'credit'), 'parent_id' => empty($values['parent_code']) ? null : $company->accounts()->where('code', $values['parent_code'])->value('id'), 'is_system' => false, 'is_active' => $this->bool($values['active'] ?? null), 'created_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->log('account.imported', $account, $company->id, $user->id, null, $account->toArray());

            return $account;
        });
    }
}

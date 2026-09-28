<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\OpeningBalanceStaging;
use App\Models\User;
use App\Services\FinancialYearResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class OpeningBalanceImportAdapter extends AbstractAdapter
{
    public function __construct(private FinancialYearResolver $years) {}

    public function type(): string
    {
        return 'opening_balances';
    }

    public function fields(): array
    {
        return ['opening_ref' => ['label' => 'Opening Balance Reference', 'required' => true, 'aliases' => ['reference']], 'date' => ['label' => 'Date', 'required' => true, 'aliases' => []], 'account' => ['label' => 'Account Code', 'required' => true, 'aliases' => ['account_code']], 'debit' => ['label' => 'Debit', 'required' => false, 'aliases' => []], 'credit' => ['label' => 'Credit', 'required' => false, 'aliases' => []], 'description' => ['label' => 'Description', 'required' => true, 'aliases' => ['memo']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        if (! $this->account($company, $values['account'] ?? null)) {
            $errors[] = 'Account Code is invalid or inactive.';
        }
        $debit = (string) ($values['debit'] ?: 0);
        $credit = (string) ($values['credit'] ?: 0);
        if (! is_numeric($debit) || ! is_numeric($credit) || ((bccomp($debit, '0', 4) > 0) === (bccomp($credit, '0', 4) > 0))) {
            $errors[] = 'Each opening-balance line needs one positive Debit or Credit.';
        }

        return $this->result($errors);
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        throw new \LogicException('Opening Balance posting is intentionally not enabled in v0.8.');
    }

    public function importGroup(Company $company, array $rows, array $options, User $user): Model
    {
        $first = $rows[0];
        $period = $this->years->resolve($company, $first['date'], null, null, false);
        $branchId = $company->supportsBranches() ? (($options['default_branch_id'] ?? null) ?: $company->branches()->where('is_main_branch', true)->value('id')) : null;
        $batchId = (int) ($options['import_batch_id'] ?? 0);
        ImportBatch::where('company_id', $company->id)->findOrFail($batchId);

        return DB::transaction(function () use ($company, $rows, $first, $period, $branchId, $batchId, $user) {
            $staging = OpeningBalanceStaging::create(['company_id' => $company->id, 'branch_id' => $branchId, 'financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'import_batch_id' => $batchId, 'balance_date' => $first['date'], 'opening_reference' => $first['opening_ref'], 'status' => 'staged', 'created_by' => $user->id]);
            foreach ($rows as $row) {
                $staging->lines()->create(['company_id' => $company->id, 'account_id' => $this->account($company, $row['account']), 'description' => $row['description'], 'debit' => $row['debit'] ?: '0', 'credit' => $row['credit'] ?: '0']);
            }

            return $staging;
        });
    }
}

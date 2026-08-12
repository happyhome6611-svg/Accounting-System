<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class CompanyCreator
{
    public function create(array $data, User $user): Company
    {
        return DB::transaction(function () use ($data, $user) {
            $company = Company::create([...$data, 'accounting_configuration' => [], 'tax_configuration' => [], 'created_by' => $user->id, 'updated_by' => $user->id]);
            $company->users()->attach($user->id, ['role' => 'owner']);
            $start = CarbonImmutable::parse($data['financial_year_start']);
            $end = CarbonImmutable::parse($data['financial_year_end']);
            $year = $company->financialYears()->create(['name' => $start->format('Y').'-'.$end->format('Y'), 'starts_on' => $start, 'ends_on' => $end, 'is_current' => true, 'created_by' => $user->id, 'updated_by' => $user->id]);
            $cursor = $start->startOfMonth();
            while ($cursor->lte($end)) {
                $periodEnd = $cursor->endOfMonth()->min($end);
                $year->periods()->create(['company_id' => $company->id, 'name' => $cursor->format('M Y'), 'starts_on' => $cursor->max($start), 'ends_on' => $periodEnd, 'created_by' => $user->id, 'updated_by' => $user->id]);
                $cursor = $cursor->addMonth()->startOfMonth();
            }foreach ($this->defaultAccounts() as $account) {
                $company->accounts()->create([...$account, 'created_by' => $user->id, 'updated_by' => $user->id]);
            }

return $company->load(['country', 'baseCurrency', 'financialYears']);
        });
    }

    private function defaultAccounts(): array
    {
        return [['code' => '1000', 'name' => 'Cash and Cash Equivalents', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => true], ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'is_system' => true], ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'is_system' => true], ['code' => '3000', 'name' => 'Owner Equity', 'type' => 'equity', 'normal_balance' => 'credit', 'is_system' => true], ['code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_system' => true], ['code' => '5000', 'name' => 'Operating Expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'is_system' => true]];
    }
}

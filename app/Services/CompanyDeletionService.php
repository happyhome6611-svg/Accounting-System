<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CompanyDeletionService
{
    private const BLOCKERS = [
        'journal_entries' => 'journal entries',
        'customers' => 'customers',
        'items' => 'products or services',
        'sales_quotations' => 'sales quotations',
        'sales_orders' => 'sales orders',
        'sales_invoices' => 'sales invoices',
        'sales_credit_notes' => 'sales credit notes',
        'customer_receipts' => 'customer receipts',
        'suppliers' => 'suppliers',
        'purchase_orders' => 'purchase orders',
        'supplier_bills' => 'supplier bills',
        'supplier_credits' => 'supplier credits',
        'supplier_payments' => 'supplier payments',
    ];

    public function blockers(Company $company): array
    {
        return collect(self::BLOCKERS)
            ->filter(fn (string $label, string $table) => DB::table($table)->where('company_id', $company->id)->exists())
            ->values()->all();
    }

    public function isEligible(Company $company): bool
    {
        return $this->blockers($company) === [];
    }

    public function delete(Company $company, User $user, string $confirmation): void
    {
        $role = $user->companies()->whereKey($company->id)->value('company_user.role');
        abort_unless($role === 'owner', 403, 'Only a company owner can permanently delete a company.');

        if (! hash_equals($company->name, $confirmation)) {
            throw ValidationException::withMessages(['confirmation_name' => 'Enter the exact company name to confirm permanent deletion.']);
        }

        DB::transaction(function () use ($company) {
            $locked = Company::withTrashed()->lockForUpdate()->findOrFail($company->id);
            $blockers = $this->blockers($locked);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['company' => 'This company cannot be deleted because it contains '.implode(', ', $blockers).'.']);
            }

            DB::table('audit_logs')->where('company_id', $locked->id)->delete();
            DB::table('tax_filing_periods')->where('company_id', $locked->id)->delete();
            DB::table('tax_obligations')->where('company_id', $locked->id)->delete();
            DB::table('tax_years')->where('company_id', $locked->id)->delete();
            DB::table('prior_period_adjustments')->where('company_id', $locked->id)->delete();
            DB::table('carry_forwards')->where('company_id', $locked->id)->delete();
            DB::table('document_sequences')->where('company_id', $locked->id)->delete();
            DB::table('accounting_periods')->where('company_id', $locked->id)->delete();
            DB::table('financial_years')->where('company_id', $locked->id)->delete();
            DB::table('accounts')->where('company_id', $locked->id)->delete();
            DB::table('branches')->where('company_id', $locked->id)->delete();
            DB::table('company_user')->where('company_id', $locked->id)->delete();
            DB::table('companies')->where('id', $locked->id)->delete();
        });
    }
}

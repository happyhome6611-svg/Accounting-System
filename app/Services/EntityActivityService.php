<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class EntityActivityService
{
    private const TABLES = [
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
        'bank_accounts' => 'bank and cash accounts',
        'tax_obligations' => 'tax obligations',
        'tax_filing_periods' => 'tax filing periods',
        'carry_forwards' => 'carry forwards',
        'prior_period_adjustments' => 'prior-period adjustments',
        'period_close_checklist_items' => 'period-end checklists',
        'adjustment_reversal_schedules' => 'adjustment reversal schedules',
        'year_end_closures' => 'year-end closures',
    ];

    public function blockers(Company $company): array
    {
        $blockers = collect(self::TABLES)
            ->filter(fn (string $label, string $table) => DB::table($table)->where('company_id', $company->id)->exists())
            ->values();

        if ($company->branches()->count() > ($company->supportsBranches() ? 1 : 0)) {
            $blockers->push('additional branches');
        }

        if ($company->taxYears()->where('status', '!=', 'open')->exists()) {
            $blockers->push('tax-year history');
        }

        return $blockers->all();
    }

    public function isUnused(Company $company): bool
    {
        return $this->blockers($company) === [];
    }
}

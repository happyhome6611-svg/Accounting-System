<?php

namespace App\Services;

use App\Models\Company;
use App\Models\TaxPeriod;
use Illuminate\Support\Facades\DB;

final class TaxReportService
{
    public function register(Company $company, array $filters = [])
    {
        return $company->transactionTaxLines()->when($filters['tax_period_id'] ?? null, fn ($q, $id) => $q->where('tax_period_id', $id))->when($filters['tax_code_id'] ?? null, fn ($q, $id) => $q->where('tax_code_id', $id))->when($filters['treatment'] ?? null, fn ($q, $value) => $q->where('treatment_snapshot', $value))->orderBy('transaction_date')->get();
    }

    public function summary(Company $company, array $filters = []): array
    {
        $query = DB::table('transaction_tax_lines')->where('company_id', $company->id)->when($filters['tax_period_id'] ?? null, fn ($q, $id) => $q->where('tax_period_id', $id));
        $output = (clone $query)->where('direction', 'output')->sum('tax_amount');
        $input = (clone $query)->where('direction', 'input')->sum('tax_amount');
        $adjustments = (clone $query)->where('direction', 'adjustment')->sum('tax_amount');
        $period = isset($filters['tax_period_id']) ? TaxPeriod::where('company_id', $company->id)->find($filters['tax_period_id']) : null;
        $journalLines = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)->where('journal_entries.status', 'posted')
            ->when($period, fn ($q) => $q->whereBetween('journal_entries.transaction_date', [$period->starts_on, $period->ends_on]));
        $outputGl = $company->taxSetting?->output_tax_account_id
            ? (clone $journalLines)->where('journal_lines.account_id', $company->taxSetting->output_tax_account_id)->selectRaw('COALESCE(SUM(credit - debit), 0) AS balance')->value('balance')
            : null;
        $inputGl = $company->taxSetting?->input_tax_account_id
            ? (clone $journalLines)->where('journal_lines.account_id', $company->taxSetting->input_tax_account_id)->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')->value('balance')
            : null;

        return ['output' => bcadd((string) $output, '0', 4), 'input' => bcadd((string) $input, '0', 4), 'adjustments' => bcadd((string) $adjustments, '0', 4), 'net' => bcadd(bcsub((string) $output, (string) $input, 4), (string) $adjustments, 4), 'output_gl' => $outputGl === null ? null : bcadd((string) $outputGl, '0', 4), 'input_gl' => $inputGl === null ? null : bcadd((string) $inputGl, '0', 4), 'output_difference' => $outputGl === null ? null : bcsub((string) $output, (string) $outputGl, 4), 'input_difference' => $inputGl === null ? null : bcsub((string) $input, (string) $inputGl, 4)];
    }
}

<?php

namespace App\Services;

use App\Models\Company;
use App\Models\TaxPeriod;
use Illuminate\Support\Facades\DB;

final class TaxReportService
{
    public function register(Company $company, array $filters = [])
    {
        return $company->transactionTaxLines()->with(['registration', 'period', 'source'])->when($filters['tax_registration_id'] ?? null, fn ($q, $id) => $q->where('tax_registration_id', $id))->when($filters['tax_period_id'] ?? null, fn ($q, $id) => $q->where('tax_period_id', $id))->when($filters['tax_code_id'] ?? null, fn ($q, $id) => $q->where('tax_code_id', $id))->when($filters['treatment'] ?? null, fn ($q, $value) => $q->where('treatment_snapshot', $value))->when($filters['source_type'] ?? null, fn ($q, $value) => $q->where('source_type', $value))->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '>=', $date))->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('transaction_date', '<=', $date))->orderBy('transaction_date')->get();
    }

    public function breakdown(Company $company, string $direction, array $filters = [])
    {
        return DB::table('transaction_tax_lines')->where('company_id', $company->id)->where('direction', $direction)
            ->when($filters['tax_period_id'] ?? null, fn ($q, $id) => $q->where('tax_period_id', $id))
            ->groupBy('tax_code_snapshot', 'treatment_snapshot')->orderBy('tax_code_snapshot')
            ->get(['tax_code_snapshot', 'treatment_snapshot', DB::raw('SUM(net_amount) AS net_amount'), DB::raw('SUM(tax_amount) AS tax_amount'), DB::raw('SUM(gross_amount) AS gross_amount')]);
    }

    public function summary(Company $company, array $filters = []): array
    {
        $query = DB::table('transaction_tax_lines')->where('company_id', $company->id)->when($filters['tax_period_id'] ?? null, fn ($q, $id) => $q->where('tax_period_id', $id));
        $output = (clone $query)->where('direction', 'output')->sum('tax_amount');
        $input = (clone $query)->where('direction', 'input')->sum('tax_amount');
        $adjustmentQuery = DB::table('tax_adjustments')->where('company_id', $company->id)->where('status', 'posted')->when($filters['tax_period_id'] ?? null, fn ($q, $id) => $q->where('tax_period_id', $id));
        $outputAdjustments = (clone $adjustmentQuery)->where('direction', 'output')->sum('amount');
        $inputAdjustments = (clone $adjustmentQuery)->where('direction', 'input')->sum('amount');
        $adjustments = bcsub((string) $outputAdjustments, (string) $inputAdjustments, 4);
        $period = isset($filters['tax_period_id']) ? TaxPeriod::where('company_id', $company->id)->find($filters['tax_period_id']) : null;
        $journalLines = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.company_id', $company->id)->where('journal_entries.status', 'posted')
            ->when($period, fn ($q) => $q->whereBetween('journal_entries.transaction_date', [$period->starts_on, $period->ends_on]));
        $settings = $company->taxSetting()->first();
        $outputGl = $settings?->output_tax_account_id
            ? (clone $journalLines)->where('journal_lines.account_id', $settings->output_tax_account_id)->selectRaw('COALESCE(SUM(credit - debit), 0) AS balance')->value('balance')
            : null;
        $inputGl = $settings?->input_tax_account_id
            ? (clone $journalLines)->where('journal_lines.account_id', $settings->input_tax_account_id)->selectRaw('COALESCE(SUM(debit - credit), 0) AS balance')->value('balance')
            : null;

        $expectedOutput = bcadd((string) $output, (string) $outputAdjustments, 4);
        $expectedInput = bcadd((string) $input, (string) $inputAdjustments, 4);

        return ['output' => bcadd((string) $output, '0', 4), 'input' => bcadd((string) $input, '0', 4), 'adjustments' => $adjustments, 'net' => bcadd(bcsub((string) $output, (string) $input, 4), $adjustments, 4), 'output_gl' => $outputGl === null ? null : bcadd((string) $outputGl, '0', 4), 'input_gl' => $inputGl === null ? null : bcadd((string) $inputGl, '0', 4), 'output_difference' => $outputGl === null ? null : bcsub($expectedOutput, (string) $outputGl, 4), 'input_difference' => $inputGl === null ? null : bcsub($expectedInput, (string) $inputGl, 4)];
    }
}

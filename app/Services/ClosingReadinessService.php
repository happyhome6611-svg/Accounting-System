<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\PeriodCloseChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ClosingReadinessService
{
    public const ITEMS = [
        'draft_journals' => ['All draft journals reviewed', true],
        'bank_reconciliations' => ['Bank reconciliations completed', true],
        'trial_balance' => ['Trial Balance balanced', true],
        'ar_review' => ['Accounts Receivable reviewed', false],
        'ap_review' => ['Accounts Payable reviewed', false],
        'suspense_review' => ['Suspense accounts reviewed', false],
        'adjustments_review' => ['Adjustments reviewed', false],
        'prior_period_review' => ['Prior-period adjustments reviewed', false],
        'bank_cash_review' => ['Bank and cash review completed', false],
        'supporting_notes' => ['Notes and supporting documentation completed', false],
    ];

    public function initialize(AccountingPeriod $period, User $user): void
    {
        foreach (self::ITEMS as $key => [$label, $system]) {
            PeriodCloseChecklistItem::firstOrCreate(['accounting_period_id' => $period->id, 'key' => $key], ['company_id' => $period->company_id, 'financial_year_id' => $period->financial_year_id, 'label' => $label, 'is_mandatory' => true, 'is_system_check' => $system, 'updated_by' => $user->id]);
        }
    }

    public function blockers(AccountingPeriod $period): array
    {
        $company = $period->financialYear->company;
        $blockers = [];
        $drafts = $company->journals()->where('accounting_period_id', $period->id)->where('status', 'draft')->count();
        if ($drafts) {
            $blockers[] = "{$drafts} draft journal(s) remain.";
        }
        $trial = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.company_id', $company->id)->where('j.accounting_period_id', $period->id)->whereIn('j.status', ['posted', 'reversed'])->selectRaw('COALESCE(SUM(l.debit),0) debit, COALESCE(SUM(l.credit),0) credit')->first();
        if (bccomp((string) $trial->debit, (string) $trial->credit, 4) !== 0) {
            $blockers[] = 'Trial Balance debit and credit totals differ.';
        }
        $unreconciled = $company->bankAccounts()->where('type', 'bank')->whereHas('imports', fn ($query) => $query->whereHas('rows', fn ($rows) => $rows->whereDate('transaction_date', '<=', $period->ends_on)))->whereDoesntHave('reconciliations', fn ($query) => $query->where('status', 'completed')->whereDate('statement_end_date', '>=', $period->ends_on))->pluck('name');
        foreach ($unreconciled as $name) {
            $blockers[] = "Bank account {$name} is unreconciled.";
        }
        $manual = $period->checklistItems()->where('is_mandatory', true)->where('is_system_check', false)->whereNotIn('status', ['completed', 'not_applicable'])->pluck('label');
        foreach ($manual as $label) {
            $blockers[] = "Checklist item \"{$label}\" is incomplete.";
        }

        return $blockers;
    }

    public function summary(AccountingPeriod $period): array
    {
        $blockers = $this->blockers($period);

        return ['ready' => $blockers === [], 'blockers' => $blockers, 'draft_adjustments' => $period->financialYear->company->journals()->where('accounting_period_id', $period->id)->where('journal_type', 'adjusting')->where('status', 'draft')->count()];
    }
}

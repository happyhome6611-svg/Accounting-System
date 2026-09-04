<?php

namespace App\Services;

use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\User;
use App\Models\YearEndClosure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class YearEndClosingService
{
    public function __construct(private AccountingLockService $locks, private JournalService $journals, private FinancialYearService $years, private AuditLogger $audit) {}

    public function close(Company $company, FinancialYear $year, int $equityAccountId, string $notes, User $user): YearEndClosure
    {
        $this->locks->authorize($company, $user);
        abort_unless($year->company_id === $company->id, 404);

        return DB::transaction(function () use ($company, $year, $equityAccountId, $notes, $user) {
            $year = FinancialYear::where('company_id', $company->id)->lockForUpdate()->findOrFail($year->id);
            if ($existing = YearEndClosure::where('financial_year_id', $year->id)->first()) {
                return $existing;
            }
            if ($year->status !== 'closing' || $year->periods()->where('status', '!=', 'closed')->exists()) {
                throw ValidationException::withMessages(['financial_year' => 'Financial Year must be in Closing with every period Closed.']);
            }
            if ($company->journals()->where('financial_year_id', $year->id)->where('status', 'draft')->exists()) {
                throw ValidationException::withMessages(['financial_year' => 'Draft journals must be resolved before Year-End Close.']);
            }
            if (DB::table('adjustment_reversal_schedules')->where('company_id', $company->id)->where('status', 'scheduled')->whereDate('reversal_date', '<=', $year->ends_on)->exists()) {
                throw ValidationException::withMessages(['financial_year' => 'Scheduled adjustment reversals remain unresolved.']);
            }
            $equity = $company->accounts()->where('type', 'equity')->where('is_active', true)->findOrFail($equityAccountId);
            $balances = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('j.company_id', $company->id)->where('j.financial_year_id', $year->id)->whereIn('j.status', ['posted', 'reversed'])->whereIn('a.type', ['revenue', 'expense'])->groupBy('a.id', 'a.name', 'a.type')->select('a.id', 'a.name', 'a.type', DB::raw('SUM(l.debit) debit'), DB::raw('SUM(l.credit) credit'))->get();
            $lines = [];
            $net = '0.0000';
            foreach ($balances as $balance) {
                $signed = bcsub((string) $balance->credit, (string) $balance->debit, 4);
                if (bccomp($signed, '0', 4) === 0) {
                    continue;
                }
                $net = bcadd($net, $signed, 4);
                $lines[] = ['account_id' => $balance->id, 'description' => 'Close '.$balance->name, 'debit' => bccomp($signed, '0', 4) > 0 ? $signed : '0', 'credit' => bccomp($signed, '0', 4) < 0 ? bcmul($signed, '-1', 4) : '0'];
            }
            if (! $lines || bccomp($net, '0', 4) === 0) {
                throw ValidationException::withMessages(['financial_year' => 'No non-zero Profit or Loss remains to close.']);
            }
            $lines[] = ['account_id' => $equity->id, 'description' => 'Transfer current-year result', 'debit' => bccomp($net, '0', 4) < 0 ? bcmul($net, '-1', 4) : '0', 'credit' => bccomp($net, '0', 4) > 0 ? $net : '0'];
            $branchId = $company->supportsBranches() ? $company->branches()->where('is_main_branch', true)->value('id') : null;
            $journal = $this->journals->create($company, ['journal_type' => 'closing', 'branch_id' => $branchId, 'financial_year_id' => $year->id, 'transaction_date' => $year->ends_on->toDateString(), 'reference' => 'YEAR-END-'.$year->id, 'description' => 'Year-End Close '.$year->name, 'reason' => $notes, 'lines' => $lines], $user);
            $this->journals->post($journal, $user);
            $closure = YearEndClosure::create(['company_id' => $company->id, 'financial_year_id' => $year->id, 'retained_earnings_account_id' => $equity->id, 'closing_journal_id' => $journal->id, 'net_result' => $net, 'status' => 'completed', 'notes' => $notes, 'completed_by' => $user->id, 'completed_at' => now()]);
            $company->update(['retained_earnings_account_id' => $equity->id, 'updated_by' => $user->id]);
            $this->years->close($year, $notes, $user);
            $this->audit->log('year_end_close.completed', $closure, $company->id, $user->id);

            return $closure;
        });
    }
}

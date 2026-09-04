<?php

namespace App\Http\Controllers;

use App\Models\AccountingPeriod;
use App\Models\AdjustmentReversalSchedule;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Models\PriorPeriodAdjustment;
use App\Models\YearEndClosure;
use App\Services\AccountingLockService;
use App\Services\AccountingReportService;
use App\Services\AuditLogger;
use App\Services\ClosingReadinessService;
use App\Services\CountryJurisdictionService;
use App\Services\JournalService;
use App\Services\PeriodEndService;
use App\Services\YearEndClosingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PeriodEndController extends Controller
{
    public function index(Request $request, CountryJurisdictionService $jurisdictions)
    {
        return view('period-end.index', ['countries' => $jurisdictions->countriesFor($request->user(), false)]);
    }

    public function country(Request $request, string $country, CountryJurisdictionService $jurisdictions)
    {
        $country = $jurisdictions->country($country);

        return view('period-end.country', ['country' => $country, 'companies' => $jurisdictions->entities($request->user(), $country)]);
    }

    public function entity(Request $request, string $country, Company $company)
    {
        $company = $this->entityContext($request, $country, $company);

        return view('period-end.entity', ['company' => $company, 'years' => $company->financialYears()->with('periods')->orderByDesc('starts_on')->get()]);
    }

    public function workspace(Request $request, string $country, Company $company, FinancialYear $year, AccountingPeriod $period, ClosingReadinessService $readiness)
    {
        $company = $this->context($request, $country, $company, $year, $period);
        $readiness->initialize($period, $request->user());
        $period->load('checklistItems');
        $summary = $readiness->summary($period);
        $accounts = $company->accounts()->where('is_active', true)->orderBy('code')->get();
        $adjustments = $company->journals()->where('accounting_period_id', $period->id)->whereIn('journal_type', ['adjusting', 'prior_period_adjustment'])->latest()->get();
        $priorAdjustments = PriorPeriodAdjustment::where('company_id', $company->id)->where('adjustment_financial_year_id', $year->id)->latest()->get();
        $bankReview = $company->bankAccounts()->where('type', 'bank')->with(['reconciliations' => fn ($query) => $query->latest('statement_end_date')])->get();
        $ar = $company->salesInvoices()->whereIn('status', ['posted', 'partially_paid'])->get()->reduce(fn ($total, $invoice) => bcadd($total, $invoice->amount_due, 4), '0.0000');
        $ap = $company->supplierBills()->whereIn('status', ['posted', 'partially_paid'])->get()->reduce(fn ($total, $bill) => bcadd($total, $bill->amount_due, 4), '0.0000');
        $suspenseAccount = $company->accounts()
            ->whereRaw('LOWER(name) LIKE ?', ['%suspense%'])
            ->first();
        $suspenseBalance = $suspenseAccount ? (string) \DB::table('journal_lines as line')->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')->where('journal.accounting_period_id', $period->id)->where('line.account_id', $suspenseAccount->id)->whereIn('journal.status', ['posted', 'reversed'])->selectRaw('COALESCE(SUM(line.debit-line.credit),0) balance')->value('balance') : '0.0000';

        return view('period-end.workspace', compact('company', 'year', 'period', 'summary', 'accounts', 'adjustments', 'priorAdjustments', 'bankReview', 'ar', 'ap', 'suspenseBalance'));
    }

    public function adjustment(Request $request, string $country, Company $company, FinancialYear $year, AccountingPeriod $period, PeriodEndService $service, JournalService $journals)
    {
        $company = $this->context($request, $country, $company, $year, $period);
        $data = $request->validate(['transaction_date' => 'required|date', 'reference' => 'nullable|string|max:255', 'description' => 'required|string|max:2000', 'reason' => 'required|string|min:10', 'supporting_notes' => 'nullable|string', 'branch_id' => 'nullable|integer', 'post_now' => 'nullable|boolean', 'reversal_date' => 'nullable|date|after:transaction_date', 'lines' => 'required|array|min:2', 'lines.*.account_id' => 'required|integer', 'lines.*.description' => 'required|string', 'lines.*.debit' => 'required|decimal:0,4|min:0', 'lines.*.credit' => 'required|decimal:0,4|min:0']);
        if (($data['reversal_date'] ?? null) && ! $request->boolean('post_now')) {
            return back()->withErrors(['reversal_date' => 'Post the adjustment before scheduling its reversal.'])->withInput();
        }
        $journal = $service->adjustment($company, $data + ['financial_year_id' => $year->id, 'accounting_period_id' => $period->id], $request->user());
        if ($request->boolean('post_now')) {
            $journals->post($journal, $request->user());
        }
        if ($data['reversal_date'] ?? null) {
            $service->scheduleReversal($company, $journal->fresh(), $data['reversal_date'], $request->user());
        }

        return back()->with('success', 'Adjustment journal created.');
    }

    public function checklist(Request $request, string $country, Company $company, FinancialYear $year, AccountingPeriod $period, int $item, PeriodEndService $service)
    {
        $company = $this->context($request, $country, $company, $year, $period);
        $service->checklist($company, $period, $item, $request->validate(['status' => ['required', Rule::in(['not_started', 'in_progress', 'completed', 'not_applicable'])], 'notes' => 'nullable|string']), $request->user());

        return back();
    }

    public function locks(Request $request, string $country, Company $company, AccountingLockService $locks, AuditLogger $audit)
    {
        $company = $this->entityContext($request, $country, $company);
        $locks->update($company, $request->validate(['bookkeeping_lock_date' => 'nullable|date', 'adviser_lock_date' => 'nullable|date']), $request->user(), $audit);

        return back()->with('success', 'Accounting lock controls updated.');
    }

    public function closePeriod(Request $request, string $country, Company $company, FinancialYear $year, AccountingPeriod $period, PeriodEndService $service)
    {
        $company = $this->context($request, $country, $company, $year, $period);
        $service->close($company, $period, $request->validate(['reason' => 'required|string|min:10'])['reason'], $request->user());

        return back()->with('success', 'Accounting Period closed.');
    }

    public function reopenPeriod(Request $request, string $country, Company $company, FinancialYear $year, AccountingPeriod $period, PeriodEndService $service)
    {
        $company = $this->context($request, $country, $company, $year, $period);
        $service->reopen($company, $period, $request->validate(['reason' => 'required|string|min:10'])['reason'], $request->user());

        return back()->with('success', 'Accounting Period reopened.');
    }

    public function year(Request $request, string $country, Company $company, FinancialYear $year, AccountingReportService $reports)
    {
        $company = $this->yearContext($request, $country, $company, $year);
        $closure = YearEndClosure::where('financial_year_id', $year->id)->with('closingJournal')->first();
        $equityAccounts = $company->accounts()->where('type', 'equity')->orderBy('code')->get();
        $profitAndLoss = $reports->profitAndLoss($company, $year->starts_on->toDateString(), $year->ends_on->toDateString(), financialYearId: $year->id);
        $balanceSheet = $reports->balanceSheet($company, $year->ends_on->toDateString());

        return view('period-end.year', compact('company', 'year', 'closure', 'equityAccounts', 'profitAndLoss', 'balanceSheet'));
    }

    public function closeYear(Request $request, string $country, Company $company, FinancialYear $year, YearEndClosingService $service)
    {
        $company = $this->yearContext($request, $country, $company, $year);
        $data = $request->validate(['retained_earnings_account_id' => 'required|integer', 'notes' => 'required|string|min:10', 'confirmation' => 'required|in:CLOSE YEAR']);
        $service->close($company, $year, $data['retained_earnings_account_id'], $data['notes'], $request->user());

        return back()->with('success', 'Year-End Close completed.');
    }

    public function postReversal(Request $request, string $country, Company $company, AdjustmentReversalSchedule $schedule, PeriodEndService $service)
    {
        $company = $this->entityContext($request, $country, $company);
        $service->postReversal($company, $schedule, $request->user());

        return back()->with('success', 'Scheduled reversal posted.');
    }

    private function entityContext(Request $request, string $country, Company $company): Company
    {
        $country = app(CountryJurisdictionService::class)->country($country);

        return app(CountryJurisdictionService::class)->entity($request->user(), $country, $company->id)->load('country');
    }

    private function yearContext(Request $request, string $country, Company $company, FinancialYear $year): Company
    {
        $company = $this->entityContext($request, $country, $company);
        abort_unless($year->company_id === $company->id, 404);

        return $company;
    }

    private function context(Request $request, string $country, Company $company, FinancialYear $year, AccountingPeriod $period): Company
    {
        $company = $this->yearContext($request, $country, $company, $year);
        abort_unless($period->company_id === $company->id && $period->financial_year_id === $year->id, 404);

        return $company;
    }
}

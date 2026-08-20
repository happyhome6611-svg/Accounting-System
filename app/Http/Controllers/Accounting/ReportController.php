<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\AccountingReportService;
use App\Services\MoneyFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private AccountingReportService $reports, private MoneyFormatter $money) {}

    private function company(Request $r)
    {
        return $r->user()->companies()->findOrFail($r->integer('company_id'));
    }

    public function index(Request $r)
    {
        return view('reports.index', ['companies' => $r->user()->companies()->with(['branches' => fn ($q) => $q->where('is_active', true)->orderBy('code'), 'accounts' => fn ($q) => $q->where('is_active', true)->orderBy('code'), 'financialYears' => fn ($q) => $q->orderByDesc('starts_on')])->get()]);
    }

    public function ledger(Request $r)
    {
        $c = $this->company($r)->load('baseCurrency');
        $account = $c->accounts()->findOrFail($r->integer('account_id'));
        $branchId = $this->branchId($r, $c);
        $rows = $this->reports->generalLedger($c, $r->integer('account_id'), $r->from, $r->to, $branchId, $this->financialYearId($r, $c));

        return view('reports.ledger', compact('c', 'rows', 'account') + $this->presentation($r, $c));
    }

    public function trial(Request $r)
    {
        $c = $this->company($r)->load('baseCurrency');
        $report = $this->reports->trialBalance($c, $r->to, $this->branchId($r, $c), $this->financialYearId($r, $c));

        return view('reports.trial', compact('c', 'report') + $this->presentation($r, $c));
    }

    public function profitLoss(Request $r)
    {
        $c = $this->company($r)->load('baseCurrency');
        $report = $this->reports->profitAndLoss($c, $r->from, $r->to, $this->branchId($r, $c), $this->financialYearId($r, $c));

        return view('reports.profit-loss', compact('c', 'report') + $this->presentation($r, $c));
    }

    public function balanceSheet(Request $r)
    {
        $c = $this->company($r)->load('baseCurrency');
        $report = $this->reports->balanceSheet($c, $r->to, $this->branchId($r, $c), $this->financialYearId($r, $c));

        return view('reports.balance-sheet', compact('c', 'report') + $this->presentation($r, $c));
    }

    private function presentation(Request $request, $company): array
    {
        $requestedYear = $request->input('financial_year_id');
        $financialYear = $requestedYear === 'all' ? null : ($request->filled('financial_year_id') ? $company->financialYears()->findOrFail($request->integer('financial_year_id')) : $this->defaultFinancialYear($request, $company));
        $effectiveYearFilter = $requestedYear === 'all' ? 'all' : $financialYear?->id;
        $filters = array_filter(['company_id' => $company->id, 'branch_id' => $request->branch_id, 'financial_year_id' => $effectiveYearFilter, 'from' => $request->from, 'to' => $request->to, 'account_id' => $request->account_id], fn ($value) => $value !== null && $value !== '');
        $formatDate = fn (?string $date) => $date ? CarbonImmutable::parse($date)->format('d M Y') : null;
        $period = ($formatDate($request->from) ?? $financialYear?->starts_on->format('d M Y') ?? 'Beginning').' – '.($formatDate($request->to) ?? $financialYear?->ends_on->format('d M Y') ?? 'Present');

        $branch = $company->supportsBranches() && $request->filled('branch_id') ? $company->branches()->findOrFail($request->integer('branch_id')) : null;
        $branchLabel = $company->supportsBranches() ? ($branch?->name ?? 'All branches (consolidated)') : 'Not applicable';
        $yearLabel = $requestedYear === 'all' ? 'All Financial Years' : ($financialYear?->name ?? 'No current Financial Year configured');

        return ['filters' => $filters, 'period' => $period, 'money' => $this->money, 'currency' => $company->baseCurrency, 'branchLabel' => $branchLabel, 'financialYearLabel' => $yearLabel];
    }

    private function branchId(Request $request, $company): ?int
    {
        return $company->supportsBranches() && $request->filled('branch_id') ? $company->branches()->findOrFail($request->integer('branch_id'))->id : null;
    }

    private function financialYearId(Request $request, $company): ?int
    {
        if ($request->input('financial_year_id') === 'all') {
            return null;
        }
        if ($request->filled('financial_year_id')) {
            return $company->financialYears()->findOrFail($request->integer('financial_year_id'))->id;
        }

        return $this->defaultFinancialYear($request, $company)?->id ?? -1;
    }

    private function defaultFinancialYear(Request $request, $company)
    {
        $contextDate = $request->input('to') ?: $request->input('from') ?: today();

        return $company->financialYears()->whereDate('starts_on', '<=', $contextDate)->whereDate('ends_on', '>=', $contextDate)->first();
    }
}

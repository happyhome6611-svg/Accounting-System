<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\AccountingReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private AccountingReportService $reports) {}

    private function company(Request $r)
    {
        return $r->user()->companies()->findOrFail($r->integer('company_id'));
    }

    public function index(Request $r)
    {
        return view('reports.index', ['companies' => $r->user()->companies()->with(['accounts' => fn ($q) => $q->where('is_active', true)->orderBy('code')])->get()]);
    }

    public function ledger(Request $r)
    {
        $c = $this->company($r);
        $rows = $this->reports->generalLedger($c, $r->integer('account_id'), $r->from, $r->to);

        return view('reports.ledger', compact('c', 'rows'));
    }

    public function trial(Request $r)
    {
        $c = $this->company($r);
        $report = $this->reports->trialBalance($c, $r->to);

        return view('reports.trial', compact('c', 'report'));
    }

    public function profitLoss(Request $r)
    {
        $c = $this->company($r);
        $report = $this->reports->profitAndLoss($c, $r->from, $r->to);

        return view('reports.profit-loss', compact('c', 'report'));
    }

    public function balanceSheet(Request $r)
    {
        $c = $this->company($r);
        $report = $this->reports->balanceSheet($c, $r->to);

        return view('reports.balance-sheet', compact('c', 'report'));
    }
}

<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\JournalService;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    private function company(Request $r): Company
    {
        return $r->user()->companies()->findOrFail($r->integer('company_id') ?: $r->route('company'));
    }

    public function index(Request $r)
    {
        $companies = $r->user()->companies()->get();
        $company = $r->company_id ? $this->company($r) : $companies->first();

        return view('accounting.index', ['companies' => $companies, 'company' => $company, 'journals' => $company?->journals()->latest()->get() ?? collect()]);
    }

    public function create(Request $r)
    {
        $company = $this->company($r);

        return view('accounting.create', ['company' => $company, 'years' => $company->financialYears()->with('periods')->get(), 'accounts' => $company->accounts()->where('is_active', true)->orderBy('code')->get()]);
    }

    public function store(Request $r, JournalService $service)
    {
        $company = $this->company($r);
        $data = $r->validate(['financial_year_id' => 'required|integer', 'accounting_period_id' => 'required|integer', 'transaction_date' => 'required|date', 'reference' => 'nullable|string|max:255', 'description' => 'required|string', 'lines' => 'required|array|min:2', 'lines.*.account_id' => 'required|integer', 'lines.*.description' => 'nullable|string', 'lines.*.debit' => 'required|numeric|min:0', 'lines.*.credit' => 'required|numeric|min:0']);
        $journal = $service->create($company, $data, $r->user());

        return redirect()->route('journals.show', [$company, $journal]);
    }

    public function show(Request $r, Company $company, JournalEntry $journal)
    {
        $company = $r->user()->companies()->findOrFail($company->id);
        abort_unless($journal->company_id === $company->id, 404);

        return view('accounting.show', compact('company', 'journal') + ['journal' => $journal->load('lines.account', 'period')]);
    }

    public function post(Request $r, Company $company, JournalEntry $journal, JournalService $s)
    {
        $this->show($r, $company, $journal);
        $s->post($journal, $r->user());

        return back()->with('success', 'Journal posted.');
    }

    public function reverse(Request $r, Company $company, JournalEntry $journal, JournalService $s)
    {
        $this->show($r, $company, $journal);
        $data = $r->validate(['accounting_period_id' => 'required|integer', 'transaction_date' => 'required|date']);
        $reversal = $s->reverse($journal, $r->user(), $data['accounting_period_id'], $data['transaction_date']);

        return redirect()->route('journals.show',[$company, $reversal]);
    }
}

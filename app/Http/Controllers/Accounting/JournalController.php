<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Services\JournalService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

        return view('accounting.create', ['company' => $company, 'branches' => $company->branches()->where('is_active', true)->orderByDesc('is_main_branch')->get(), 'years' => $company->financialYears()->with('periods')->get(), 'accounts' => $company->accounts()->where('is_active', true)->orderBy('code')->get()]);
    }

    public function store(Request $r, JournalService $service)
    {
        $company = $this->company($r);
        $data = $r->validate(['branch_id' => 'nullable|integer', 'financial_year_id' => 'nullable|integer', 'accounting_period_id' => 'nullable|integer', 'transaction_date' => 'required|date', 'reference' => 'nullable|string|max:255', 'description' => 'required|string', 'lines' => 'required|array|min:2', 'lines.*.account_id' => 'required|integer', 'lines.*.description' => 'nullable|string', 'lines.*.debit' => 'required|numeric|min:0', 'lines.*.credit' => 'required|numeric|min:0']);
        $journal = $service->create($company, $data, $r->user());

        return redirect()->route('journals.show', [$company, $journal]);
    }

    public function edit(Request $r, Company $company, JournalEntry $journal)
    {
        $company = $r->user()->companies()->findOrFail($company->id);
        abort_unless($journal->company_id === $company->id, 404);
        abort_unless($journal->status === 'draft' && ! $journal->reversal_of_id, 403);

        return view('accounting.edit', $this->formData($company) + ['journal' => $journal->load('lines')]);
    }

    public function update(Request $r, Company $company, JournalEntry $journal, JournalService $service)
    {
        $company = $r->user()->companies()->findOrFail($company->id);
        abort_unless($journal->company_id === $company->id, 404);
        $service->update($journal, $r->validate($this->rules()), $r->user());

        return redirect()->route('journals.show', [$company, $journal])->with('success', 'Draft journal updated.');
    }

    public function destroy(Request $r, Company $company, JournalEntry $journal, JournalService $service)
    {
        $company = $r->user()->companies()->findOrFail($company->id);
        abort_unless($journal->company_id === $company->id, 404);
        $data = $r->validate(['confirmation' => 'required|string']);
        if (! hash_equals($journal->journal_number, $data['confirmation'])) {
            throw ValidationException::withMessages(['confirmation' => 'Enter the exact journal number to confirm permanent deletion.']);
        }
        $service->deleteDraft($journal, $r->user());

        return redirect()->route('accounting', ['company_id' => $company->id])->with('success', 'Draft journal permanently deleted.');
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

        return redirect()->route('journals.show', [$company, $reversal]);
    }

    private function rules(): array
    {
        return ['branch_id' => 'nullable|integer', 'financial_year_id' => 'nullable|integer', 'accounting_period_id' => 'nullable|integer', 'transaction_date' => 'required|date', 'reference' => 'nullable|string|max:255', 'description' => 'required|string', 'lines' => 'required|array|min:2', 'lines.*.account_id' => 'required|integer', 'lines.*.description' => 'nullable|string', 'lines.*.debit' => 'required|numeric|min:0', 'lines.*.credit' => 'required|numeric|min:0'];
    }

    private function formData(Company $company): array
    {
        return ['company' => $company->load('baseCurrency'), 'branches' => $company->branches()->where('is_active', true)->orderByDesc('is_main_branch')->get(), 'years' => $company->financialYears()->with('periods')->get(), 'accounts' => $company->accounts()->where('is_active', true)->orderBy('code')->get()];
    }
}

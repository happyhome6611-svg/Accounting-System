<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\BankReconciliation;
use App\Models\BankStatementTransaction;
use App\Models\Company;
use App\Models\JournalLine;
use App\Services\BankingService;
use App\Services\CountryJurisdictionService;
use App\Services\MoneyFormatter;
use Illuminate\Http\Request;

class BankingController extends Controller
{
    public function __construct(private CountryJurisdictionService $jurisdictions) {}

    public function index(Request $r)
    {
        $countries = $this->jurisdictions->countriesFor($r->user(), false);

        return view('banking.index', compact('countries'));
    }

    public function country(Request $r, string $country)
    {
        $country = $this->jurisdictions->country($country);
        $companies = $this->jurisdictions->entities($r->user(), $country)->load(['country', 'baseCurrency']);

        return view('banking.country', compact('country', 'companies'));
    }

    public function accounts(Request $r, string $country, Company $company)
    {
        $company = $this->company($r, $country, $company);

        return view('banking.accounts', ['company' => $company, 'accounts' => $company->bankAccounts()->with('ledgerAccount')->get(), 'ledgerAccounts' => $company->accounts()->where('type', 'asset')->where('is_active', true)->get()]);
    }

    public function storeAccount(Request $r, string $country, Company $company, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        $s->createAccount($company, $r->validate($this->accountRules()), $r->user());

        return back()->with('success', 'Bank/Cash account created.');
    }

    public function updateAccount(Request $r, string $country, Company $company, BankAccount $account, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        $s->updateAccount($company, $account, $r->validate($this->accountRules()), $r->user());

        return back()->with('success', 'Account updated.');
    }

    public function status(Request $r, string $country, Company $company, BankAccount $account, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        $s->setActive($company, $account, $r->boolean('is_active'), $r->user());

        return back();
    }

    public function destroy(Request $r, string $country, Company $company, BankAccount $account, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        $s->deleteAccount($company, $account, $r->user());

        return back();
    }

    public function register(Request $r, string $country, Company $company, BankAccount $account, BankingService $s, MoneyFormatter $money)
    {
        $company = $this->company($r, $country, $company);
        abort_unless($account->company_id === $company->id, 404);
        $rows = $s->register($company, $account, $r->from, $r->to, $r->integer('branch_id') ?: null, $r->integer('financial_year_id') ?: null);

        return view('banking.register', compact('company', 'account', 'rows', 'money'));
    }

    public function createTransaction(Request $r, string $country, Company $company)
    {
        $company = $this->company($r, $country, $company);

        return view('banking.transaction', ['company' => $company, 'bankAccounts' => $company->bankAccounts()->where('is_active', true)->get(), 'accounts' => $company->accounts()->where('is_active', true)->get(), 'branches' => $company->branches()->where('is_active', true)->get()]);
    }

    public function storeTransaction(Request $r, string $country, Company $company, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        $tx = $s->transact($company, $r->validate(['type' => 'required|in:transfer,bank_fee,interest_received,interest_paid,direct_expense,direct_income', 'bank_account_id' => 'required|integer', 'destination_bank_account_id' => 'nullable|integer', 'counterparty_account_id' => 'nullable|integer', 'branch_id' => 'nullable|integer', 'financial_year_id' => 'nullable|integer', 'accounting_period_id' => 'nullable|integer', 'transaction_date' => 'required|date', 'amount' => 'required|decimal:0,4|gt:0', 'reference' => 'nullable|string|max:255', 'description' => 'required|string|max:2000']), $r->user());

        return redirect()->route('banking.register', [$country, $company, $tx->bank_account_id]);
    }

    public function imports(Request $r, string $country, Company $company, BankAccount $account)
    {
        $company = $this->company($r, $country, $company);
        abort_unless($account->company_id === $company->id, 404);

        return view('banking.imports', compact('company', 'account'));
    }

    public function preview(Request $r, string $country, Company $company, BankAccount $account)
    {
        $company = $this->company($r, $country, $company);
        abort_unless($account->company_id === $company->id, 404);
        $file = $r->validate(['statement' => 'required|file|mimes:csv,txt|max:2048'])['statement'];
        $csv = file_get_contents($file->getRealPath());
        $r->session()->put("bank-import.{$account->id}", ['name' => $file->getClientOriginalName(), 'csv' => $csv]);
        $lines = array_slice(preg_split('/\R/', trim($csv)), 0, 21);

        return view('banking.preview', compact('company', 'account', 'lines'));
    }

    public function confirmImport(Request $r, string $country, Company $company, BankAccount $account, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        abort_unless($account->company_id === $company->id, 404);
        $payload = $r->session()->pull("bank-import.{$account->id}");
        abort_unless($payload, 422);
        $batch = $s->import($company, $account, $payload['name'], $payload['csv'], $r->user(), $r->boolean('override'));

        return redirect()->route('banking.imports', [$country, $company, $account])->with('success', "Imported {$batch->imported_count} rows; {$batch->duplicate_count} duplicates; {$batch->error_count} errors.");
    }

    public function matching(Request $r, string $country, Company $company, BankAccount $account, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        abort_unless($account->company_id === $company->id, 404);
        $rows = BankStatementTransaction::where('company_id', $company->id)->where('bank_account_id', $account->id)->where('status', 'unmatched')->get();
        $book = $s->register($company, $account);

        $accounts = $company->accounts()->where('is_active', true)->whereIn('type', ['expense', 'revenue', 'income', 'equity', 'asset'])->get();
        $branches = $company->branches()->where('is_active', true)->get();

        return view('banking.matching', compact('company', 'account', 'rows', 'book', 'accounts', 'branches'));
    }

    public function match(Request $r, string $country, Company $company, BankAccount $account, BankStatementTransaction $row, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        abort_unless($account->company_id === $company->id && $row->bank_account_id === $account->id, 404);
        $line = JournalLine::findOrFail($r->validate(['journal_line_id' => 'required|integer'])['journal_line_id']);
        $s->match($company, $row, $line, $r->user());

        return back();
    }

    public function createFromStatement(Request $r, string $country, Company $company, BankAccount $account, BankStatementTransaction $row, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        abort_unless($account->company_id === $company->id && $row->bank_account_id === $account->id, 404);
        $s->createFromStatement($company, $row, $r->validate(['type' => 'required|in:bank_fee,interest_received,interest_paid,direct_expense,direct_income', 'counterparty_account_id' => 'required|integer', 'branch_id' => 'nullable|integer']), $r->user());

        return back()->with('success', 'Accounting transaction created and matched.');
    }

    public function reconciliations(Request $r, string $country, Company $company, BankAccount $account)
    {
        $company = $this->company($r, $country, $company);
        abort_unless($account->company_id === $company->id, 404);
        $reconciliations = BankReconciliation::where('company_id', $company->id)->where('bank_account_id', $account->id)->latest('statement_end_date')->get();

        return view('banking.reconciliations', compact('company', 'account', 'reconciliations'));
    }

    public function storeReconciliation(Request $r, string $country, Company $company, BankAccount $account, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        abort_unless($account->company_id === $company->id, 404);
        $s->reconcile($company, $account, $r->validate(['statement_start_date' => 'nullable|date', 'statement_end_date' => 'required|date', 'statement_closing_balance' => 'required|decimal:0,4', 'notes' => 'nullable|string']), $r->user());

        return back();
    }

    public function completeReconciliation(Request $r, string $country, Company $company, BankAccount $account, BankReconciliation $reconciliation, BankingService $s)
    {
        $company = $this->company($r, $country, $company);
        $s->complete($company, $account, $reconciliation, $r->user());

        return back();
    }

    private function company(Request $r, string $country, Company $c): Company
    {
        $country = $this->jurisdictions->country($country);

        return $this->jurisdictions->entity($r->user(), $country, $c->id)->load('country');
    }

    private function accountRules(): array
    {
        return ['name' => 'required|string|max:255', 'type' => 'required|in:bank,cash,credit_card,other_cash_equivalent', 'ledger_account_id' => 'required|integer', 'currency_id' => 'required|integer', 'bank_name' => 'nullable|string|max:255', 'account_identifier' => 'nullable|string|max:255', 'bank_branch' => 'nullable|string|max:255', 'opening_date' => 'nullable|date', 'notes' => 'nullable|string', 'is_active' => 'nullable|boolean'];
    }
}

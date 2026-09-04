<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\CustomerReceipt;
use App\Models\JournalLine;
use App\Models\SupplierPayment;
use App\Models\User;
use App\Services\BankingService;
use App\Services\CompanyCreator;
use App\Services\JournalService;
use App\Services\PurchaseService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class BankingCashManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private BankingService $banking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['entity_type' => 'company', 'name' => 'Bank Test', 'legal_name' => 'Bank Test Ltd', 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
        $this->banking = app(BankingService::class);
    }

    public function test_bank_cash_account_master_validation_locking_and_safe_delete(): void
    {
        $bank = $this->bank('Operating Bank', 'bank', $this->company->accounts()->where('code', '1000')->value('id'));
        $cashLedger = $this->ledger('1010', 'Petty Cash', 'asset');
        $cash = $this->bank('Petty Cash', 'cash', $cashLedger->id);
        $this->banking->updateAccount($this->company, $bank, [...$this->accountData('Operating Bank Updated', 'bank', $bank->ledger_account_id), 'bank_name' => 'Arua Bank'], $this->user);
        $this->assertSame('Arua Bank', $bank->fresh()->bank_name);
        $this->banking->setActive($this->company, $cash, false, $this->user);
        $this->assertFalse($cash->fresh()->is_active);
        $this->banking->setActive($this->company, $cash, true, $this->user);
        $unusedLedger = $this->ledger('1020', 'Unused Cash', 'asset');
        $unused = $this->bank('Unused', 'cash', $unusedLedger->id);
        $this->banking->deleteAccount($this->company, $unused, $this->user);
        $this->assertDatabaseMissing('bank_accounts', ['id' => $unused->id]);
        $this->assertThrows(fn () => $this->bank('AR Invalid', 'bank', $this->company->accounts()->where('code', '1100')->value('id')));
        $this->assertThrows(fn () => $this->banking->createAccount($this->company, [...$this->accountData('FX', 'bank', $cashLedger->id), 'currency_id' => Currency::where('code', 'USD')->value('id')], $this->user), ValidationException::class);
        $this->bankTx('bank_fee', $bank, '25', $this->company->accounts()->where('code', '5000')->value('id'));
        $this->assertThrows(fn () => $this->banking->deleteAccount($this->company, $bank, $this->user), ValidationException::class);
        $this->assertThrows(fn () => $this->banking->updateAccount($this->company, $bank, $this->accountData('Forged', 'bank', $cashLedger->id), $this->user), ValidationException::class);
    }

    public function test_transfer_fee_interest_direct_entries_and_register_use_one_balanced_journal(): void
    {
        $from = $this->bank('Operating', 'bank', $this->company->accounts()->where('code', '1000')->value('id'));
        $to = $this->bank('Savings', 'bank', $this->ledger('1010', 'Savings', 'asset')->id);
        $transfer = $this->banking->transact($this->company, ['type' => 'transfer', 'bank_account_id' => $from->id, 'destination_bank_account_id' => $to->id, 'branch_id' => $this->branch(), 'transaction_date' => '2026-09-04', 'amount' => '1000', 'description' => 'Transfer'], $this->user);
        $fee = $this->bankTx('bank_fee', $from, '25', $this->company->accounts()->where('code', '5000')->value('id'));
        $income = $this->bankTx('interest_received', $from, '10', $this->company->accounts()->where('code', '4000')->value('id'));
        $expense = $this->bankTx('direct_expense', $from, '75', $this->company->accounts()->where('code', '5000')->value('id'));
        $directIncome = $this->bankTx('direct_income', $from, '100', $this->company->accounts()->where('code', '4000')->value('id'));
        foreach ([$transfer, $fee, $income, $expense, $directIncome] as $tx) {
            $this->assertSame(0, bccomp((string) \DB::table('journal_lines')->where('journal_entry_id', $tx->journal_entry_id)->selectRaw('sum(debit-credit) balance')->value('balance'), '0', 4));
        }
        $this->assertDatabaseCount('journal_entries', 5);
        $this->assertSame('-990.0000', $this->banking->register($this->company, $from)->last()->balance);
        $this->assertSame('1000.0000', $this->banking->register($this->company, $to)->last()->balance);
        $this->assertThrows(fn () => $this->banking->transact($this->company, ['type' => 'transfer', 'bank_account_id' => $from->id, 'destination_bank_account_id' => $from->id, 'branch_id' => $this->branch(), 'transaction_date' => '2026-09-04', 'amount' => '1', 'description' => 'Bad'], $this->user), ValidationException::class);
        $this->assertThrows(fn () => $transfer->update(['amount' => '2']), LogicException::class);
    }

    public function test_csv_import_matching_duplicate_protection_and_reconciliation(): void
    {
        $bank = $this->bank('Operating', 'bank', $this->company->accounts()->where('code', '1000')->value('id'));
        $tx = $this->bankTx('direct_income', $bank, '100', $this->company->accounts()->where('code', '4000')->value('id'));
        $csv = "date,description,reference,amount\n2026-09-04,Other income,INC,100.00\n2026-09-03,Bank fee,FEE,-25.00\nbad,Invalid,BAD,x\n";
        $batch = $this->banking->import($this->company, $bank, 'statement.csv', $csv, $this->user);
        $this->assertSame(2, $batch->imported_count);
        $this->assertSame(1, $batch->error_count);
        $this->assertDatabaseCount('journal_entries', 1);
        $duplicate = $this->banking->import($this->company, $bank, 'statement.csv', $csv, $this->user);
        $this->assertSame(2, $duplicate->duplicate_count);
        $row = $batch->rows->firstWhere('reference', 'INC');
        $line = \DB::table('journal_lines')->where('journal_entry_id', $tx->journal_entry_id)->where('account_id', $bank->ledger_account_id)->first();
        $this->banking->match($this->company, $row, JournalLine::find($line->id), $this->user);
        $this->assertSame('matched', $row->fresh()->status);
        $this->assertThrows(fn () => $this->banking->match($this->company, $row, JournalLine::find($line->id), $this->user));
        $feeRow = $batch->rows->firstWhere('reference', 'FEE');
        $fee = $this->banking->createFromStatement($this->company, $feeRow, ['type' => 'bank_fee', 'counterparty_account_id' => $this->company->accounts()->where('code', '5000')->value('id'), 'branch_id' => $this->branch()], $this->user);
        $this->assertSame('matched', $feeRow->fresh()->status);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $fee->journal_entry_id, 'account_id' => $bank->ledger_account_id, 'credit' => '25.0000']);
        $reconciliation = $this->banking->reconcile($this->company, $bank, ['statement_end_date' => '2026-09-04', 'statement_closing_balance' => '75'], $this->user);
        $this->assertSame('0.0000', $reconciliation->difference);
        $this->banking->complete($this->company, $bank, $reconciliation, $this->user);
        $this->assertSame('completed', $reconciliation->fresh()->status);
        $this->assertThrows(fn () => $reconciliation->fresh()->update(['notes' => 'forged']), LogicException::class);
    }

    public function test_routes_render_and_cross_entity_tampering_is_rejected(): void
    {
        $bank = $this->bank('Operating', 'bank', $this->company->accounts()->where('code', '1000')->value('id'));
        $this->actingAs($this->user)->get(route('banking'))->assertOk()->assertSee('Banking & Cash Management', false);
        foreach ([route('banking.accounts', [$this->company->country, $this->company]), route('banking.register', [$this->company->country, $this->company, $bank]), route('banking.transactions.create', [$this->company->country, $this->company]), route('banking.imports', [$this->company->country, $this->company, $bank]), route('banking.matching', [$this->company->country, $this->company, $bank]), route('banking.reconciliations', [$this->company->country, $this->company, $bank])] as $url) {
            $this->get($url)->assertOk()->assertDontSee('@yield');
        }
    }

    public function test_individual_bank_account_and_branchless_transaction_are_supported(): void
    {
        $individual = app(CompanyCreator::class)->create(['entity_type' => 'individual', 'name' => 'Personal Books', 'individual_name' => 'Personal Books', 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
        $bank = $this->banking->createAccount($individual, ['name' => 'Personal Bank', 'type' => 'bank', 'ledger_account_id' => $individual->accounts()->where('code', '1000')->value('id'), 'currency_id' => $individual->base_currency_id, 'is_active' => true], $this->user);
        $transaction = $this->banking->transact($individual, ['type' => 'direct_income', 'bank_account_id' => $bank->id, 'counterparty_account_id' => $individual->accounts()->where('code', '4000')->value('id'), 'transaction_date' => '2026-09-04', 'amount' => '25', 'description' => 'Personal income'], $this->user);
        $this->assertNull($transaction->branch_id);
        $this->assertSame('25.0000', $this->banking->register($individual, $bank)->last()->balance);
    }

    public function test_banking_jurisdiction_landing_entities_and_forged_routes_are_scoped(): void
    {
        $india = $this->jurisdictionEntity('India Books', 'IN', 'INR');
        $australia = $this->jurisdictionEntity('Australia Books', 'AU', 'AUD');
        $individual = $this->jurisdictionEntity('NZ Individual', 'NZ', 'NZD', 'individual');
        $nz = Country::where('code', 'NZ')->firstOrFail();
        $in = Country::where('code', 'IN')->firstOrFail();
        $au = Country::where('code', 'AU')->firstOrFail();

        $this->actingAs($this->user)->get(route('banking'))->assertOk()->assertSee('New Zealand')->assertSee('2 accessible accounting entities')->assertSee('India')->assertSee('Australia')->assertDontSee('Singapore');
        $this->get(route('banking.country', $nz->code))->assertOk()->assertSee($this->company->name)->assertSee($individual->name)->assertDontSee($india->name)->assertDontSee($australia->name);
        $this->get(route('banking.country', $in->code))->assertOk()->assertSee($india->name)->assertDontSee($this->company->name)->assertDontSee($australia->name);
        $this->get(route('banking.country', $au->code))->assertOk()->assertSee($australia->name)->assertDontSee($india->name)->assertDontSee($this->company->name);
        $this->get(route('banking.accounts', [$in->code, $this->company]))->assertNotFound();
        $this->get(route('banking.accounts', [$nz->code, $india]))->assertNotFound();
    }

    public function test_receipt_payment_manual_journal_and_banking_activity_share_one_ledger_register(): void
    {
        $bank = $this->bank('Operating Bank', 'bank', $this->company->accounts()->where('code', '1000')->value('id'));
        $branch = $this->branch();
        $year = $this->company->financialYears()->where('is_current', true)->firstOrFail();
        $period = $year->periods()->whereDate('starts_on', '<=', '2026-09-04')->whereDate('ends_on', '>=', '2026-09-04')->firstOrFail();
        $customer = $this->company->customers()->create(['code' => 'CUST-ACCEPT', 'name' => 'Acceptance Customer', 'currency_id' => $this->company->base_currency_id, 'receivable_account_id' => $this->company->accounts()->where('code', '1100')->value('id'), 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $supplier = $this->company->suppliers()->create(['code' => 'SUP-ACCEPT', 'name' => 'Acceptance Supplier', 'currency_id' => $this->company->base_currency_id, 'payable_account_id' => $this->company->accounts()->where('type', 'liability')->value('id'), 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);

        $receipt = CustomerReceipt::create(['company_id' => $this->company->id, 'customer_id' => $customer->id, 'branch_id' => $branch, 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'receipt_number' => 'REC-ACCEPT', 'receipt_date' => '2026-09-01', 'amount' => '1200', 'payment_method' => 'transfer', 'receiving_account_id' => $bank->ledger_account_id, 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        app(SalesService::class)->postReceipt($receipt, $this->user);
        $payment = SupplierPayment::create(['company_id' => $this->company->id, 'supplier_id' => $supplier->id, 'branch_id' => $branch, 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'currency_id' => $this->company->base_currency_id, 'payment_number' => 'SPAY-ACCEPT', 'payment_date' => '2026-09-02', 'payment_account_id' => $bank->ledger_account_id, 'amount' => '450', 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        app(PurchaseService::class)->post($this->company, 'payments', $payment, $this->user);
        $fee = $this->bankTx('bank_fee', $bank, '25', $this->company->accounts()->where('code', '5000')->value('id'));
        $interest = $this->bankTx('interest_received', $bank, '10', $this->company->accounts()->where('code', '4000')->value('id'));
        $expense = $this->bankTx('direct_expense', $bank, '75', $this->company->accounts()->where('code', '5000')->value('id'));
        $income = $this->bankTx('direct_income', $bank, '100', $this->company->accounts()->where('code', '4000')->value('id'));
        $journals = app(JournalService::class);
        $manual = $journals->create($this->company, ['branch_id' => $branch, 'transaction_date' => '2026-09-04', 'reference' => 'MANUAL-BANK', 'description' => 'Manual bank deposit', 'lines' => [['account_id' => $bank->ledger_account_id, 'description' => 'Deposit', 'debit' => '40', 'credit' => '0'], ['account_id' => $this->company->accounts()->where('code', '4000')->value('id'), 'description' => 'Deposit', 'debit' => '0', 'credit' => '40']]], $this->user);
        $journals->post($manual, $this->user);

        $rows = $this->banking->register($this->company, $bank);
        $this->assertSame(['Customer Receipt', 'Supplier Payment', 'Bank Fee', 'Interest Received', 'Direct Expense', 'Direct Income', 'Manual Journal'], $rows->pluck('source_type')->all());
        $this->assertSame('800.0000', $rows->last()->balance);
        $this->assertSame(0, bccomp((string) $rows->first()->money_in, '1200', 4));
        $this->assertSame(0, bccomp((string) $rows->get(1)->money_out, '450', 4));
        $this->assertDatabaseCount('journal_entries', 7);
        foreach ([$receipt->fresh()->journal_entry_id, $payment->fresh()->journal_entry_id, $fee->journal_entry_id, $interest->journal_entry_id, $expense->journal_entry_id, $income->journal_entry_id, $manual->id] as $journalId) {
            $this->assertSame(0, bccomp((string) \DB::table('journal_lines')->where('journal_entry_id', $journalId)->selectRaw('SUM(debit-credit) balance')->value('balance'), '0', 4));
        }
    }

    public function test_csv_variants_invalid_formats_and_statement_evidence_do_not_change_ledger(): void
    {
        $bank = $this->bank('Operating Bank', 'bank', $this->company->accounts()->where('code', '1000')->value('id'));
        $signed = "date,description,reference,amount\n2026-08-01,Customer payment,INV1001,1200.00\n2026-08-03,Monthly fee,FEE-AUG,-25.00\n";
        $split = "date,description,reference,debit,credit\n2026-08-05,Supplier payment,SUP500,450.00,0\n2026-08-06,Bank interest,INT-AUG,0,10.00\n2026-08-07,Both invalid,BAD,2,2\nmalformed,row\n";

        $first = $this->banking->import($this->company, $bank, 'signed.csv', $signed, $this->user);
        $second = $this->banking->import($this->company, $bank, 'split.csv', $split, $this->user);
        $this->assertSame([2, 0, 0], [$first->imported_count, $first->duplicate_count, $first->error_count]);
        $this->assertSame([2, 0, 2], [$second->imported_count, $second->duplicate_count, $second->error_count]);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertCount(0, $this->banking->register($this->company, $bank));
        $this->assertSame('unmatched', $first->rows->first()->status);
        $duplicate = $this->banking->import($this->company, $bank, 'signed-again.csv', $signed, $this->user);
        $this->assertSame(2, $duplicate->duplicate_count);
        $override = $this->banking->import($this->company, $bank, 'signed-override.csv', $signed, $this->user, true);
        $this->assertSame(2, $override->imported_count);
        $this->assertDatabaseCount('journal_entries', 0);
        $this->assertThrows(fn () => $this->banking->import($this->company, $bank, 'missing.csv', "description,reference\nBad,BAD", $this->user), ValidationException::class);
    }

    public function test_register_match_and_reconciliation_reject_forged_context_and_draft_accounting(): void
    {
        $bank = $this->bank('Operating Bank', 'bank', $this->company->accounts()->where('code', '1000')->value('id'));
        $other = $this->entity('Other Entity');
        $otherBank = $this->banking->createAccount($other, ['name' => 'Other Bank', 'type' => 'bank', 'ledger_account_id' => $other->accounts()->where('code', '1000')->value('id'), 'currency_id' => $other->base_currency_id, 'is_active' => true], $this->user);
        $this->assertThrows(fn () => $this->banking->register($this->company, $otherBank));
        $this->assertThrows(fn () => $this->banking->register($this->company, $bank, branch: $other->branches()->value('id')));
        $this->assertThrows(fn () => $this->banking->register($this->company, $bank, year: $other->financialYears()->value('id')));

        $batch = $this->banking->import($this->company, $bank, 'match.csv', "date,description,reference,amount\n2026-09-04,Draft candidate,DRAFT,50", $this->user);
        $draft = app(JournalService::class)->create($this->company, ['branch_id' => $this->branch(), 'transaction_date' => '2026-09-04', 'description' => 'Draft candidate', 'lines' => [['account_id' => $bank->ledger_account_id, 'description' => 'Draft', 'debit' => '50', 'credit' => '0'], ['account_id' => $this->company->accounts()->where('code', '4000')->value('id'), 'description' => 'Draft', 'debit' => '0', 'credit' => '50']]], $this->user);
        $this->assertThrows(fn () => $this->banking->match($this->company, $batch->rows->first(), $draft->lines()->where('account_id', $bank->ledger_account_id)->firstOrFail(), $this->user));
        $this->assertSame('unmatched', $batch->rows->first()->fresh()->status);

        $reconciliation = $this->banking->reconcile($this->company, $bank, ['statement_end_date' => '2026-09-04', 'statement_closing_balance' => '1'], $this->user);
        $this->assertThrows(fn () => $this->banking->complete($this->company, $bank, $reconciliation, $this->user), ValidationException::class);
        $otherReconciliation = $this->banking->reconcile($other, $otherBank, ['statement_end_date' => '2026-09-04', 'statement_closing_balance' => '0'], $this->user);
        $this->assertThrows(fn () => $this->banking->complete($this->company, $bank, $otherReconciliation, $this->user));
    }

    public function test_banking_posting_rejects_invalid_amount_account_branch_and_financial_context(): void
    {
        $bank = $this->bank('Operating Bank', 'bank', $this->company->accounts()->where('code', '1000')->value('id'));
        $other = $this->entity('Other Entity');
        $otherBank = $this->banking->createAccount($other, ['name' => 'Other Bank', 'type' => 'bank', 'ledger_account_id' => $other->accounts()->where('code', '1000')->value('id'), 'currency_id' => $other->base_currency_id, 'is_active' => true], $this->user);
        $data = ['type' => 'transfer', 'bank_account_id' => $bank->id, 'destination_bank_account_id' => $otherBank->id, 'branch_id' => $this->branch(), 'transaction_date' => '2026-09-04', 'amount' => '1000', 'description' => 'Forged transfer'];
        $this->assertThrows(fn () => $this->banking->transact($this->company, $data, $this->user));
        foreach (['0', '-1'] as $amount) {
            $this->assertThrows(fn () => $this->banking->transact($this->company, [...$data, 'destination_bank_account_id' => $bank->id, 'amount' => $amount], $this->user), ValidationException::class);
        }
        $bank->update(['is_active' => false]);
        $this->assertThrows(fn () => $this->bankTx('bank_fee', $bank, '25', $this->company->accounts()->where('code', '5000')->value('id')));
        $bank->update(['is_active' => true]);
        $branch = $this->company->branches()->firstOrFail();
        $branch->update(['is_active' => false]);
        $this->assertThrows(fn () => $this->bankTx('bank_fee', $bank, '25', $this->company->accounts()->where('code', '5000')->value('id')));
        $branch->update(['is_active' => true]);
        $period = $this->company->financialYears()->where('is_current', true)->firstOrFail()->periods()->whereDate('starts_on', '<=', '2026-09-04')->whereDate('ends_on', '>=', '2026-09-04')->firstOrFail();
        $period->update(['status' => 'closed']);
        $this->assertThrows(fn () => $this->bankTx('bank_fee', $bank, '25', $this->company->accounts()->where('code', '5000')->value('id')), ValidationException::class);
        $period->update(['status' => 'open']);
        $this->assertThrows(fn () => $this->banking->transact($this->company, [...$data, 'type' => 'bank_fee', 'destination_bank_account_id' => null, 'counterparty_account_id' => $this->company->accounts()->where('code', '5000')->value('id'), 'transaction_date' => '2028-01-01'], $this->user), ValidationException::class);
        $this->assertDatabaseCount('banking_transactions', 0);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    private function bank(string $name, string $type, int $ledger): BankAccount
    {
        return $this->banking->createAccount($this->company, $this->accountData($name, $type, $ledger), $this->user);
    }

    private function accountData(string $name, string $type, int $ledger): array
    {
        return ['name' => $name, 'type' => $type, 'ledger_account_id' => $ledger, 'currency_id' => $this->company->base_currency_id, 'bank_name' => null, 'account_identifier' => null, 'bank_branch' => null, 'opening_date' => '2026-04-01', 'notes' => null, 'is_active' => true];
    }

    private function ledger(string $code, string $name, string $type)
    {
        return $this->company->accounts()->create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $type === 'asset' ? 'debit' : 'credit', 'is_active' => true, 'is_system' => false, 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
    }

    private function branch(): int
    {
        return $this->company->branches()->value('id');
    }

    private function bankTx(string $type, BankAccount $bank, string $amount, int $counter)
    {
        return $this->banking->transact($this->company, ['type' => $type, 'bank_account_id' => $bank->id, 'counterparty_account_id' => $counter, 'branch_id' => $this->branch(), 'transaction_date' => '2026-09-04', 'amount' => $amount, 'reference' => strtoupper($type), 'description' => str_replace('_', ' ', $type)], $this->user);
    }

    private function entity(string $name): Company
    {
        return app(CompanyCreator::class)->create(['entity_type' => 'company', 'name' => $name, 'legal_name' => $name, 'country_id' => Country::where('code', 'IN')->value('id'), 'base_currency_id' => Currency::where('code', 'INR')->value('id'), 'timezone' => 'Asia/Kolkata', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
    }

    private function jurisdictionEntity(string $name, string $country, string $currency, string $type = 'company'): Company
    {
        return app(CompanyCreator::class)->create(['entity_type' => $type, 'name' => $name, 'legal_name' => $type === 'company' ? $name : null, 'individual_name' => $type === 'individual' ? $name : null, 'country_id' => Country::where('code', $country)->value('id'), 'base_currency_id' => Currency::where('code', $currency)->value('id'), 'timezone' => 'UTC', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
    }
}

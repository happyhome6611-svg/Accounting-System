<?php

namespace Tests\Feature;

use App\ImportExport\ImportUndoService;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EntityImportBatch;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\BankStatementImportUndoService;
use App\Services\CompanyCreator;
use App\Services\EntityImportUndoService;
use App\Services\JournalService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportUndoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['name' => 'Undo Books', 'legal_name' => 'Undo Books', 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
    }

    public function test_unused_imported_customer_is_undone_and_fingerprint_is_released(): void
    {
        $customer = app(SalesService::class)->createCustomer($this->company, ['code' => 'UNDO-C', 'name' => 'Undo Customer', 'type' => 'business', 'country_id' => $this->company->country_id, 'currency_id' => $this->company->base_currency_id, 'tax_identifiers' => [], 'payment_terms_days' => 0, 'credit_limit' => 0, 'receivable_account_id' => $this->company->accounts()->where('code', '1100')->value('id'), 'is_active' => true], $this->user);
        $batch = $this->batch('customers', $customer);

        $this->actingAs($this->user)->get(route('import-export.batches.undo.show', [$this->company->country->code, $this->company, $batch]))->assertOk()->assertSee('SAFE_TO_REMOVE')->assertSee('Type UNDO');
        $this->post(route('import-export.batches.undo', [$this->company->country->code, $this->company, $batch]), ['understood' => 1, 'confirmation' => 'UNDO'])->assertRedirect();

        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
        $this->assertDatabaseHas('import_batches', ['id' => $batch->id, 'undo_status' => 'undone']);
        $this->assertDatabaseHas('import_rows', ['import_batch_id' => $batch->id, 'validation_status' => 'skipped']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'import.undone', 'auditable_id' => $batch->id]);
        app(ImportUndoService::class)->undo($this->company, $batch->fresh(), $this->user, 'UNDO');
        $this->assertSame(1, $this->company->importBatches()->where('undo_status', 'undone')->count());
    }

    public function test_used_customer_blocks_entire_batch_and_cross_entity_access_is_rejected(): void
    {
        $customer = app(SalesService::class)->createCustomer($this->company, ['code' => 'USED-C', 'name' => 'Used Customer', 'type' => 'business', 'country_id' => $this->company->country_id, 'currency_id' => $this->company->base_currency_id, 'tax_identifiers' => [], 'payment_terms_days' => 0, 'credit_limit' => 0, 'receivable_account_id' => $this->company->accounts()->where('code', '1100')->value('id'), 'is_active' => true], $this->user);
        $this->company->salesInvoices()->create(['customer_id' => $customer->id, 'branch_id' => $this->company->branches()->value('id'), 'currency_id' => $this->company->base_currency_id, 'financial_year_id' => $this->company->financialYears()->value('id'), 'accounting_period_id' => $this->company->financialYears()->first()->periods()->value('id'), 'invoice_number' => 'INV-USED', 'invoice_date' => '2026-04-01', 'due_date' => '2026-04-30', 'subtotal' => 1, 'tax_amount' => 0, 'total' => 1, 'amount_paid' => 0, 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $batch = $this->batch('customers', $customer);
        $analysis = app(ImportUndoService::class)->analysis($this->company, $batch, $this->user);
        $this->assertSame(1, $analysis['blocked']);
        try {
            app(ImportUndoService::class)->undo($this->company, $batch, $this->user, 'UNDO');
            $this->fail('Undo should be blocked.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('customers', ['id' => $customer->id]);
        }

        $other = app(CompanyCreator::class)->create(['name' => 'Other Books', 'legal_name' => 'Other Books', 'country_id' => $this->company->country_id, 'base_currency_id' => $this->company->base_currency_id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
        $this->actingAs($this->user)->get(route('import-export.batches.undo.show', [$other->country->code, $other, $batch]))->assertNotFound();
    }

    public function test_draft_and_posted_manual_journal_undo_obey_accounting_controls_and_are_idempotent(): void
    {
        $period = $this->company->financialYears()->first()->periods()->first();
        $accounts = $this->company->accounts;
        $data = ['transaction_date' => '2026-04-02', 'description' => 'Imported journal', 'lines' => [['account_id' => $accounts->firstWhere('code', '1000')->id, 'description' => 'Cash', 'debit' => 10, 'credit' => 0], ['account_id' => $accounts->firstWhere('code', '4000')->id, 'description' => 'Revenue', 'debit' => 0, 'credit' => 10]]];
        $draft = app(JournalService::class)->create($this->company, $data, $this->user);
        $draftBatch = $this->batch('manual_journals', $draft, 2);
        app(ImportUndoService::class)->undo($this->company, $draftBatch, $this->user, 'UNDO');
        $this->assertDatabaseMissing('journal_entries', ['id' => $draft->id]);

        $posted = app(JournalService::class)->create($this->company, $data, $this->user);
        app(JournalService::class)->post($posted, $this->user);
        $postedBatch = $this->batch('manual_journals', $posted, 2);
        app(ImportUndoService::class)->undo($this->company, $postedBatch, $this->user, 'UNDO', '2026-04-03');
        $this->assertDatabaseHas('journal_entries', ['id' => $posted->id, 'status' => 'reversed']);
        $this->assertDatabaseHas('journal_entries', ['reversal_of_id' => $posted->id, 'status' => 'posted', 'accounting_period_id' => $period->id]);
        app(ImportUndoService::class)->undo($this->company, $postedBatch->fresh(), $this->user, 'UNDO', '2026-04-03');
        $this->assertSame(1, $posted->reversal()->count());
    }

    public function test_opening_balance_staging_and_zero_record_attempt_have_distinct_actions(): void
    {
        $staging = $this->batch('opening_balances', null, 2, 'ready');
        $empty = $this->batch('supplier_bills', null, 0, 'completed');
        $empty->update(['total_rows' => 381, 'duplicate_rows' => 381]);

        $workspace = $this->actingAs($this->user)->get(route('import-export.workspace', [$this->company->country->code, $this->company]))->assertOk();
        $workspace->assertSee('Duplicate Only')->assertSee('Delete Import Attempt')->assertSee('Undo Import');
        app(ImportUndoService::class)->undo($this->company, $staging, $this->user, 'UNDO');
        $this->assertSame(0, $staging->rows()->whereNull('undone_at')->count());
        $this->delete(route('import-export.batches.delete-attempt', [$this->company->country->code, $this->company, $empty]))->assertRedirect();
        $this->assertSoftDeleted('import_batches', ['id' => $empty->id]);
    }

    public function test_unmatched_bank_evidence_can_be_undone_but_matched_evidence_blocks_atomic_undo(): void
    {
        $account = $this->company->bankAccounts()->create(['ledger_account_id' => $this->company->accounts()->where('code', '1000')->value('id'), 'currency_id' => $this->company->base_currency_id, 'name' => 'Test Bank', 'type' => 'bank', 'opening_date' => '2026-04-01', 'is_active' => true, 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $batch = BankStatementImport::create(['company_id' => $this->company->id, 'bank_account_id' => $account->id, 'file_name' => 'statement.csv', 'file_hash' => str_repeat('a', 64), 'row_count' => 1, 'imported_count' => 1, 'status' => 'imported', 'imported_by' => $this->user->id, 'imported_at' => now()]);
        BankStatementTransaction::create(['company_id' => $this->company->id, 'bank_account_id' => $account->id, 'bank_statement_import_id' => $batch->id, 'transaction_date' => '2026-04-01', 'description' => 'Evidence', 'money_in' => 10, 'money_out' => 0, 'fingerprint' => str_repeat('b', 64)]);
        app(BankStatementImportUndoService::class)->undo($this->company, $batch, $this->user, 'UNDO');
        $this->assertDatabaseMissing('bank_statement_transactions', ['bank_statement_import_id' => $batch->id]);
        app(BankStatementImportUndoService::class)->undo($this->company, $batch->fresh(), $this->user, 'UNDO');
        $this->assertSame('undone', $batch->fresh()->undo_status);

        $blocked = BankStatementImport::create(['company_id' => $this->company->id, 'bank_account_id' => $account->id, 'file_name' => 'matched.csv', 'file_hash' => str_repeat('c', 64), 'row_count' => 1, 'imported_count' => 1, 'status' => 'imported', 'imported_by' => $this->user->id, 'imported_at' => now()]);
        BankStatementTransaction::create(['company_id' => $this->company->id, 'bank_account_id' => $account->id, 'bank_statement_import_id' => $blocked->id, 'transaction_date' => '2026-04-02', 'description' => 'Matched evidence', 'money_in' => 10, 'money_out' => 0, 'fingerprint' => str_repeat('d', 64), 'status' => 'matched']);
        $this->expectException(ValidationException::class);
        app(BankStatementImportUndoService::class)->undo($this->company, $blocked, $this->user, 'UNDO');
    }

    public function test_fresh_imported_entity_can_be_undone_but_meaningful_activity_blocks_it(): void
    {
        $fresh = app(CompanyCreator::class)->create(['name' => 'Imported Fresh Entity', 'legal_name' => 'Imported Fresh Entity', 'country_id' => $this->company->country_id, 'base_currency_id' => $this->company->base_currency_id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
        $batch = EntityImportBatch::create(['country_id' => $fresh->country_id, 'uploaded_by' => $this->user->id, 'original_filename' => 'entity.csv', 'stored_path' => 'testing/entity.csv', 'file_format' => 'csv', 'file_fingerprint' => str_repeat('e', 64), 'status' => 'completed', 'result_company_id' => $fresh->id, 'confirmed_at' => now()]);
        app(EntityImportUndoService::class)->undo($batch, $this->user, 'UNDO');
        $this->assertDatabaseMissing('companies', ['id' => $fresh->id]);
        $this->assertDatabaseHas('entity_import_batches', ['id' => $batch->id, 'undo_status' => 'undone', 'result_company_id' => null]);

        $used = app(CompanyCreator::class)->create(['name' => 'Imported Used Entity', 'legal_name' => 'Imported Used Entity', 'country_id' => $this->company->country_id, 'base_currency_id' => $this->company->base_currency_id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
        $used->customers()->create(['code' => 'USED', 'name' => 'Meaningful Activity', 'type' => 'business', 'country_id' => $used->country_id, 'currency_id' => $used->base_currency_id, 'tax_identifiers' => [], 'payment_terms_days' => 0, 'credit_limit' => 0, 'receivable_account_id' => $used->accounts()->where('code', '1100')->value('id'), 'is_active' => true, 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $usedBatch = EntityImportBatch::create(['country_id' => $used->country_id, 'uploaded_by' => $this->user->id, 'original_filename' => 'used.csv', 'stored_path' => 'testing/used.csv', 'file_format' => 'csv', 'file_fingerprint' => str_repeat('f', 64), 'status' => 'completed', 'result_company_id' => $used->id, 'confirmed_at' => now()]);
        try {
            app(EntityImportUndoService::class)->undo($usedBatch, $this->user, 'UNDO');
            $this->fail('Used entity undo should be blocked.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('companies', ['id' => $used->id]);
            $this->assertDatabaseHas('entity_import_batches', ['id' => $usedBatch->id, 'undo_status' => 'blocked']);
        }
    }

    private function batch(string $type, mixed $model, int $rows = 1, string $status = 'completed'): ImportBatch
    {
        $batch = $this->company->importBatches()->create(['country_id' => $this->company->country_id, 'data_type' => $type, 'original_filename' => $type.'.csv', 'stored_path' => 'testing/'.$type.'.csv', 'file_format' => 'csv', 'file_fingerprint' => hash('sha256', $type.microtime(true)), 'status' => $status, 'total_rows' => $rows, 'imported_rows' => $model ? $rows : 0, 'uploaded_by' => $this->user->id, 'uploaded_at' => now()]);
        for ($i = 1; $i <= $rows; $i++) {
            $batch->rows()->create(['source_row_number' => $i, 'raw_values' => ['row' => $i], 'mapped_values' => ['row' => $i], 'row_fingerprint' => hash('sha256', $type.$i.microtime(true)), 'validation_status' => $model ? 'imported' : 'valid', 'result_model' => $model ? $model::class : null, 'result_id' => $model?->id]);
        }

        return $batch;
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\CustomerReceipt;
use App\Models\SalesCreditNote;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\DocumentNumberService;
use App\Services\ReceivablesReportService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class SalesReceivablesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private SalesService $sales;

    private $customer;

    private $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['name' => 'Arua Sales', 'legal_name' => 'Arua Sales Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
        $this->period = $this->company->financialYears->first()->periods()->first();
        $this->sales = app(SalesService::class);
        $this->customer = $this->sales->createCustomer($this->company, ['code' => 'CUS-01', 'name' => 'Customer One', 'currency_id' => $this->company->base_currency_id, 'receivable_account_id' => $this->company->accounts()->where('code', '1100')->value('id'), 'payment_terms_days' => 30, 'credit_limit' => 10000, 'is_active' => true], $this->user);
    }

    private function lines(): array
    {
        return [['revenue_account_id' => $this->company->accounts()->where('type', 'revenue')->value('id'), 'description' => 'Consulting', 'quantity' => '2', 'unit_price' => '50', 'discount' => '0']];
    }

    private function invoice(string $date = '2026-01-02')
    {
        return $this->sales->createInvoice($this->company, ['customer_id' => $this->customer->id, 'accounting_period_id' => $this->period->id, 'invoice_date' => $date, 'due_date' => '2026-01-31', 'lines' => $this->lines()], $this->user);
    }

    public function test_customer_item_creation_duplicate_code_and_company_isolation(): void
    {
        $item = $this->sales->createItem($this->company, ['code' => 'SVC-01', 'name' => 'Consulting', 'type' => 'service', 'unit' => 'hour', 'sales_price' => '50', 'revenue_account_id' => $this->company->accounts()->where('type', 'revenue')->value('id'), 'is_active' => true], $this->user);
        $this->assertSame('50.0000', $item->sales_price);
        $this->assertThrows(fn () => $this->sales->createCustomer($this->company, ['code' => 'CUS-01', 'name' => 'Duplicate', 'currency_id' => $this->company->base_currency_id, 'receivable_account_id' => $this->customer->receivable_account_id], $this->user), QueryException::class);
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('sales.customers', $this->company))->assertNotFound();
    }

    public function test_inactive_customer_cannot_be_invoiced(): void
    {
        $this->customer->update(['is_active' => false]);
        $this->assertThrows(fn () => $this->invoice(), ValidationException::class);
    }

    public function test_quotation_and_order_do_not_create_journals(): void
    {
        $this->sales->createQuotation($this->company, ['customer_id' => $this->customer->id, 'quotation_date' => '2026-01-01', 'expiry_date' => '2026-02-01', 'status' => 'draft', 'lines' => $this->lines()], $this->user);
        $this->sales->createOrder($this->company, ['customer_id' => $this->customer->id, 'order_date' => '2026-01-01', 'status' => 'draft', 'lines' => $this->lines()], $this->user);
        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_invoice_posts_balanced_receivable_and_revenue_and_is_immutable(): void
    {
        $invoice = $this->sales->postInvoice($this->invoice(), $this->user)->fresh();
        $this->assertSame('posted', $invoice->status);
        $this->assertNotNull($invoice->journal_entry_id);
        $journal = $invoice->journal()->with('lines')->first();
        $this->assertEquals(100, $journal->lines->sum('debit'));
        $this->assertEquals(100, $journal->lines->sum('credit'));
        $this->assertThrows(fn () => $invoice->update(['notes' => 'changed']), LogicException::class);
        $this->assertThrows(fn () => $invoice->delete(), LogicException::class);
    }

    public function test_invoice_posting_to_closed_period_is_rejected(): void
    {
        $invoice = $this->invoice();
        $this->period->update(['status' => 'closed']);
        $this->assertThrows(fn () => $this->sales->postInvoice($invoice, $this->user), ValidationException::class);
    }

    public function test_credit_note_posts_reversing_receivable_entry(): void
    {
        $invoice = $this->sales->postInvoice($this->invoice(), $this->user);
        $note = SalesCreditNote::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'sales_invoice_id' => $invoice->id, 'accounting_period_id' => $this->period->id, 'credit_note_number' => 'CN-000001', 'credit_note_date' => '2026-01-03', 'status' => 'draft', 'total' => '25', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $note->lines()->create(['revenue_account_id' => $this->company->accounts()->where('type', 'revenue')->value('id'), 'description' => 'Credit', 'quantity' => 1, 'unit_price' => 25, 'line_amount' => 25]);
        $note = $this->sales->postCreditNote($note, $this->user)->fresh();
        $this->assertSame('posted', $note->status);
        $this->assertEquals(25, $note->journal->lines()->sum('debit'));
    }

    public function test_full_partial_multiple_allocation_and_overallocation(): void
    {
        $first = $this->sales->postInvoice($this->invoice(), $this->user);
        $second = $this->sales->postInvoice($this->invoice('2026-01-03'), $this->user);
        $receipt = CustomerReceipt::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'accounting_period_id' => $this->period->id, 'receipt_number' => 'REC-000001', 'receipt_date' => '2026-01-04', 'amount' => 150, 'payment_method' => 'transfer', 'receiving_account_id' => $this->company->accounts()->where('code', '1000')->value('id'), 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $receipt->allocations()->createMany([['sales_invoice_id' => $first->id, 'amount' => 100], ['sales_invoice_id' => $second->id, 'amount' => 50]]);
        $this->sales->postReceipt($receipt, $this->user);
        $this->assertSame('paid', $first->fresh()->status);
        $this->assertSame('partially_paid', $second->fresh()->status);
        $this->assertSame('50.0000', $second->fresh()->amount_due);
        $bad = CustomerReceipt::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'accounting_period_id' => $this->period->id, 'receipt_number' => 'REC-000002', 'receipt_date' => '2026-01-05', 'amount' => 10, 'payment_method' => 'cash', 'receiving_account_id' => $this->company->accounts()->where('code', '1000')->value('id'), 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $bad->allocations()->create(['sales_invoice_id' => $second->id, 'amount' => 20]);
        $this->assertThrows(fn () => $this->sales->postReceipt($bad, $this->user), ValidationException::class);
    }

    public function test_ar_statement_aging_and_document_number_uniqueness(): void
    {
        $invoice = $this->sales->postInvoice($this->invoice(), $this->user);
        $reports = app(ReceivablesReportService::class);
        $this->assertSame('100.0000', $reports->outstanding($this->company)->first()->outstanding);
        $this->assertSame('100.0000', $reports->statement($this->company, $this->customer)['closing']);
        $this->assertSame('100.0000', $reports->aging($this->company, '2026-03-15')['totals']['31–60'] ?? '0.0000');
        $numbers = app(DocumentNumberService::class);
        $generated = collect(range(1, 10))->map(fn () => $numbers->next($this->company, 'test_sequence', 'TST'));
        $this->assertCount(10, $generated->unique());
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('sales.invoices', $this->company))->assertNotFound();
        $this->assertNotNull($invoice);
    }
}

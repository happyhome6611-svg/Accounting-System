<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\TransactionTaxLine;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\JournalService;
use App\Services\PurchaseService;
use App\Services\SalesService;
use App\Services\SalesWorkflowService;
use App\Services\SupplierMaintenanceService;
use App\Services\TaxAdjustmentService;
use App\Services\TaxCalculationService;
use App\Services\TaxConfigurationService;
use App\Services\TaxPeriodService;
use App\Services\TaxReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxEngineAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private TaxConfigurationService $configuration;

    private object $registration;

    private array $codes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['entity_type' => 'company', 'name' => 'Acceptance Tax Books', 'legal_name' => 'Acceptance Tax Books Ltd', 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2027-01-01', 'financial_year_end' => '2027-12-31'], $this->user);
        $this->taxAccount('1150', 'Input Tax Recoverable', 'asset', 'debit');
        $this->taxAccount('2100', 'Output Tax Payable', 'liability', 'credit');
        $this->configuration = app(TaxConfigurationService::class);
        $this->registration = $this->configuration->registration($this->company, ['tax_type' => 'GST', 'name' => 'Generic Indirect Tax', 'registration_number' => 'ACCEPT-1', 'registration_name' => 'Acceptance Tax Books', 'effective_from' => '2027-01-01', 'effective_to' => '2027-12-31', 'filing_frequency' => 'quarterly', 'accounting_basis' => 'accrual', 'status' => 'active'], $this->user);
        foreach (['STANDARD' => 'taxable', 'ZERO' => 'zero_rated', 'EXEMPT' => 'exempt', 'OUT' => 'out_of_scope'] as $code => $treatment) {
            $this->codes[$code] = $this->configuration->code($this->company, ['tax_registration_id' => $this->registration->id, 'tax_type' => 'GST', 'code' => $code, 'name' => $code, 'treatment' => $treatment, 'recoverability_type' => 'full', 'effective_from' => '2027-01-01', 'is_active' => true], $this->user);
        }
        $this->configuration->rate($this->company, $this->codes['STANDARD'], ['rate' => '10', 'effective_from' => '2027-01-01', 'effective_to' => '2027-06-30', 'is_active' => true], $this->user);
        $this->configuration->rate($this->company, $this->codes['STANDARD'], ['rate' => '12', 'effective_from' => '2027-07-01', 'effective_to' => '2027-12-31', 'is_active' => true], $this->user);
        $this->configuration->generatePeriods($this->company, $this->registration, $this->user);
        $this->configuration->settings($this->company, ['output_tax_account_id' => $this->account('2100'), 'input_tax_account_id' => $this->account('1150'), 'default_sales_tax_code_id' => $this->codes['STANDARD']->id, 'default_purchase_tax_code_id' => $this->codes['ZERO']->id, 'rounding_method' => 'per_line'], $this->user);
    }

    public function test_purchase_mixed_tax_credit_and_payment_post_exactly_once(): void
    {
        $supplier = app(SupplierMaintenanceService::class)->create($this->company, ['code' => 'TAX-S', 'name' => 'Tax Supplier', 'type' => 'business', 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 30, 'credit_limit' => 0, 'payable_account_id' => $this->account('2000'), 'is_active' => true], $this->user);
        $service = app(PurchaseService::class);
        $common = ['supplier_id' => $supplier->id, 'branch_id' => $this->company->branches()->value('id'), 'financial_year_id' => null, 'accounting_period_id' => null, 'bill_date' => '2027-06-30', 'due_date' => '2027-07-30', 'lines' => [
            ['expense_account_id' => $this->account('5000'), 'description' => 'Taxable', 'quantity' => '1', 'unit_price' => '200', 'discount' => '0', 'tax_code_id' => $this->codes['STANDARD']->id],
            ['expense_account_id' => $this->account('5000'), 'description' => 'Zero', 'quantity' => '1', 'unit_price' => '50', 'discount' => '0', 'tax_code_id' => $this->codes['ZERO']->id],
        ]];
        $bill = $service->create($this->company, 'bills', $common, $this->user);
        $this->assertSame('250.0000', $bill->subtotal);
        $this->assertSame('20.0000', $bill->tax_amount);
        $this->assertSame('270.0000', $bill->total);
        $service->post($this->company, 'bills', $bill, $this->user);
        $this->assertJournalLine($bill->fresh()->journal_entry_id, '5000', '250.0000', '0.0000');
        $this->assertJournalLine($bill->fresh()->journal_entry_id, '1150', '20.0000', '0.0000');
        $this->assertJournalLine($bill->fresh()->journal_entry_id, '2000', '0.0000', '270.0000');
        $this->assertSame(2, TransactionTaxLine::where('source_id', $bill->id)->count());

        $payment = $service->create($this->company, 'payments', ['supplier_id' => $supplier->id, 'branch_id' => $bill->branch_id, 'financial_year_id' => null, 'accounting_period_id' => null, 'payment_date' => '2027-06-30', 'payment_account_id' => $this->account('1000'), 'amount' => '270', 'allocations' => [['supplier_bill_id' => $bill->id, 'amount' => '270']]], $this->user);
        $service->post($this->company, 'payments', $payment, $this->user);
        $this->assertSame(2, TransactionTaxLine::where('company_id', $this->company->id)->count());
        $this->assertDatabaseMissing('journal_lines', ['journal_entry_id' => $payment->fresh()->journal_entry_id, 'description' => $payment->payment_number.' input tax']);
        $this->assertThrows(fn () => $service->post($this->company, 'payments', $payment->fresh(), $this->user), ValidationException::class);
    }

    public function test_receipt_and_credits_do_not_duplicate_tax(): void
    {
        $sales = app(SalesService::class);
        $workflow = app(SalesWorkflowService::class);
        $customer = $sales->createCustomer($this->company, ['code' => 'TAX-C', 'name' => 'Tax Customer', 'type' => 'business', 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 0, 'credit_limit' => 1000, 'receivable_account_id' => $this->account('1100')], $this->user);
        $invoiceData = ['customer_id' => $customer->id, 'branch_id' => $this->company->branches()->value('id'), 'invoice_date' => '2027-06-30', 'due_date' => '2027-07-30', 'lines' => [['revenue_account_id' => $this->account('4000'), 'description' => 'Taxable sale', 'quantity' => '1', 'unit_price' => '100', 'discount' => '0', 'tax_code_id' => $this->codes['STANDARD']->id]]];
        $invoice = $sales->createInvoice($this->company, $invoiceData, $this->user);
        $sales->postInvoice($invoice, $this->user);
        $receipt = $workflow->create($this->company, 'receipts', ['customer_id' => $customer->id, 'branch_id' => $invoice->branch_id, 'receipt_date' => '2027-06-30', 'amount' => '110', 'payment_method' => 'Bank Transfer', 'receiving_account_id' => $this->account('1000'), 'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => '110']]], $this->user);
        $sales->postReceipt($receipt, $this->user);
        $this->assertSame(1, TransactionTaxLine::where('company_id', $this->company->id)->count());
        $this->assertJournalLine($receipt->fresh()->journal_entry_id, '1000', '110.0000', '0.0000');
        $this->assertJournalLine($receipt->fresh()->journal_entry_id, '1100', '0.0000', '110.0000');

        $creditInvoice = $sales->createInvoice($this->company, $invoiceData, $this->user);
        $sales->postInvoice($creditInvoice, $this->user);
        $credit = $workflow->create($this->company, 'credit-notes', ['customer_id' => $customer->id, 'branch_id' => $creditInvoice->branch_id, 'sales_invoice_id' => $creditInvoice->id, 'credit_note_date' => '2027-06-30', 'notes' => 'Full taxable reversal', 'lines' => [['revenue_account_id' => $this->account('4000'), 'description' => 'Taxable credit', 'quantity' => '1', 'unit_price' => '100', 'tax_code_id' => $this->codes['STANDARD']->id]]], $this->user);
        $sales->postCreditNote($credit, $this->user);
        $this->assertJournalLine($credit->fresh()->journal_entry_id, '4000', '100.0000', '0.0000');
        $this->assertJournalLine($credit->fresh()->journal_entry_id, '2100', '10.0000', '0.0000');
        $this->assertJournalLine($credit->fresh()->journal_entry_id, '1100', '0.0000', '110.0000');
        $this->assertDatabaseHas('transaction_tax_lines', ['source_id' => $credit->id, 'tax_amount' => '-10.0000']);

        $supplier = app(SupplierMaintenanceService::class)->create($this->company, ['code' => 'CREDIT-S', 'name' => 'Credit Supplier', 'type' => 'business', 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 30, 'credit_limit' => 0, 'payable_account_id' => $this->account('2000'), 'is_active' => true], $this->user);
        $purchases = app(PurchaseService::class);
        $bill = $purchases->create($this->company, 'bills', ['supplier_id' => $supplier->id, 'branch_id' => $invoice->branch_id, 'bill_date' => '2027-06-30', 'due_date' => '2027-07-30', 'lines' => [['expense_account_id' => $this->account('5000'), 'description' => 'Taxable bill', 'quantity' => '1', 'unit_price' => '100', 'tax_code_id' => $this->codes['STANDARD']->id]]], $this->user);
        $purchases->post($this->company, 'bills', $bill, $this->user);
        $supplierCredit = $purchases->create($this->company, 'credits', ['supplier_id' => $supplier->id, 'branch_id' => $invoice->branch_id, 'supplier_bill_id' => $bill->id, 'credit_date' => '2027-06-30', 'lines' => [['expense_account_id' => $this->account('5000'), 'description' => 'Taxable supplier credit', 'quantity' => '1', 'unit_price' => '100', 'tax_code_id' => $this->codes['STANDARD']->id]]], $this->user);
        $purchases->post($this->company, 'credits', $supplierCredit, $this->user);
        $this->assertJournalLine($supplierCredit->fresh()->journal_entry_id, '1150', '0.0000', '10.0000');
        $this->assertDatabaseHas('transaction_tax_lines', ['source_id' => $supplierCredit->id, 'tax_amount' => '-10.0000']);
        $this->assertSame('110.0000', $bill->fresh()->total);
        $snapshotCount = TransactionTaxLine::count();
        $usedRate = $this->codes['STANDARD']->rates()->whereDate('effective_from', '2027-01-01')->firstOrFail();
        $this->assertThrows(fn () => $this->configuration->updateRate($this->company, $this->codes['STANDARD'], $usedRate, ['rate' => '11.000000', 'effective_from' => '2027-01-01', 'effective_to' => '2027-06-30', 'is_active' => true], $this->user), ValidationException::class);
        $this->configuration->updateRegistration($this->company, $this->registration, [...$this->registration->only(['tax_type', 'name', 'registration_number', 'registration_name', 'effective_from', 'effective_to', 'filing_frequency', 'accounting_basis', 'notes']), 'status' => 'inactive'], $this->user);
        $this->assertSame($snapshotCount, TransactionTaxLine::count());
        $this->assertDatabaseHas('transaction_tax_lines', ['source_id' => $invoice->id, 'rate_snapshot' => '10.000000', 'tax_amount' => '10.0000']);
        $this->assertThrows(fn () => app(TaxCalculationService::class)->calculate($this->company, $this->codes['STANDARD']->id, '2027-06-30', '100'));
    }

    public function test_registration_code_lifecycle_boundaries_gap_and_historical_snapshots_are_safe(): void
    {
        $calculator = app(TaxCalculationService::class);
        $this->assertThrows(fn () => $calculator->calculate($this->company, $this->codes['STANDARD']->id, '2026-12-31', '100'));
        $this->assertSame('10.0000', $calculator->calculate($this->company, $this->codes['STANDARD']->id, '2027-06-30', '100')['tax']);
        $this->assertSame('12.0000', $calculator->calculate($this->company, $this->codes['STANDARD']->id, '2027-07-01', '100')['tax']);
        $futureRate = $this->codes['STANDARD']->rates()->whereDate('effective_from', '2027-07-01')->firstOrFail();
        $this->configuration->updateRate($this->company, $this->codes['STANDARD'], $futureRate, ['rate' => '12.000000', 'effective_from' => '2027-07-02', 'effective_to' => '2027-12-31', 'is_active' => true], $this->user);
        $this->assertThrows(fn () => $calculator->calculate($this->company, $this->codes['STANDARD']->id, '2027-07-01', '100'), ValidationException::class);
        $this->configuration->updateRate($this->company, $this->codes['STANDARD'], $futureRate->fresh(), ['rate' => '12.000000', 'effective_from' => '2027-07-01', 'effective_to' => '2027-12-31', 'is_active' => true], $this->user);
        $this->configuration->updateRegistration($this->company, $this->registration, [...$this->registration->only(['tax_type', 'name', 'registration_number', 'registration_name', 'effective_from', 'effective_to', 'filing_frequency', 'accounting_basis', 'status', 'notes']), 'registration_name' => 'Updated Registration'], $this->user);
        $this->assertSame('Updated Registration', $this->registration->fresh()->registration_name);
        $this->configuration->updateCode($this->company, $this->codes['ZERO'], [...$this->codes['ZERO']->only(['tax_registration_id', 'tax_type', 'code', 'name', 'description', 'treatment', 'recoverability_type', 'effective_from', 'effective_to', 'is_active']), 'is_active' => false], $this->user);
        $this->assertThrows(fn () => $calculator->calculate($this->company, $this->codes['ZERO']->id, '2027-06-30', '100'));
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'tax_registration.updated']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'tax_code.updated']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'tax_rate.updated']);
    }

    public function test_defaults_rounding_lock_and_failure_atomicity_are_safe(): void
    {
        $calculator = app(TaxCalculationService::class);
        $inclusive = $calculator->calculate($this->company, $this->codes['STANDARD']->id, '2027-07-01', '100', true);
        $this->assertSame('89.2857', $inclusive['net']);
        $this->assertSame('10.7143', $inclusive['tax']);
        $this->assertSame('100.0000', $inclusive['gross']);

        $sales = app(SalesService::class);
        $customer = $sales->createCustomer($this->company, ['code' => 'DEFAULT-C', 'name' => 'Default Customer', 'type' => 'business', 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 0, 'credit_limit' => 1000, 'receivable_account_id' => $this->account('1100')], $this->user);
        $base = ['customer_id' => $customer->id, 'branch_id' => $this->company->branches()->value('id'), 'invoice_date' => '2027-06-30', 'due_date' => '2027-07-30'];
        $defaulted = $sales->createInvoice($this->company, $base + ['lines' => [['revenue_account_id' => $this->account('4000'), 'description' => 'Entity default', 'quantity' => '1', 'unit_price' => '100']]], $this->user);
        $this->assertSame($this->codes['STANDARD']->id, $defaulted->lines->first()->tax_code_id);
        $this->assertSame('10.0000', $defaulted->tax_amount);
        $explicit = $sales->createInvoice($this->company, $base + ['lines' => [['revenue_account_id' => $this->account('4000'), 'description' => 'Explicit override', 'quantity' => '1', 'unit_price' => '100', 'tax_code_id' => $this->codes['ZERO']->id]]], $this->user);
        $this->assertSame($this->codes['ZERO']->id, $explicit->lines->first()->tax_code_id);
        $this->assertSame('0.0000', $explicit->tax_amount);

        $period = $this->registration->periods()->whereDate('starts_on', '2027-04-01')->firstOrFail();
        app(TaxPeriodService::class)->prepare($this->company, $period, $this->user);
        $journalCount = \DB::table('journal_entries')->count();
        $this->assertThrows(fn () => $sales->postInvoice($defaulted, $this->user), ValidationException::class);
        $this->assertSame('draft', $defaulted->fresh()->status);
        $this->assertSame($journalCount, \DB::table('journal_entries')->count());
        $this->assertDatabaseMissing('transaction_tax_lines', ['source_id' => $defaulted->id]);

        $this->assertThrows(fn () => $this->configuration->settings($this->company, ['input_tax_account_id' => $this->account('1000'), 'rounding_method' => 'per_line'], $this->user), ValidationException::class);
        $this->assertThrows(fn () => $this->configuration->settings($this->company, ['output_tax_account_id' => $this->account('2000'), 'rounding_method' => 'per_line'], $this->user), ValidationException::class);
    }

    public function test_prepared_and_filed_periods_protect_posting_and_status_transitions_are_audited(): void
    {
        $period = $this->registration->periods()->whereDate('starts_on', '2027-01-01')->firstOrFail();
        $this->configuration->generatePeriods($this->company, $this->registration, $this->user);
        $this->assertSame(4, $this->registration->periods()->count());
        $periods = app(TaxPeriodService::class);
        $periods->prepare($this->company, $period, $this->user);
        $this->assertSame('prepared', $period->fresh()->status);
        $this->assertThrows(fn () => app(TaxCalculationService::class)->calculate($this->company, $this->codes['STANDARD']->id, '2027-03-31', '100'), ValidationException::class);
        $periods->file($this->company, $period->fresh(), $this->user);
        $this->assertSame('filed', $period->fresh()->status);
        $this->assertNotNull($period->fresh()->prepared_snapshot);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'tax_period.prepared']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'tax_period.filed']);
        $this->assertThrows(fn () => $periods->file($this->company, $period->fresh(), $this->user), ValidationException::class);
    }

    public function test_adjustment_is_balanced_audited_scoped_and_reconciled_without_auto_balancing(): void
    {
        $period = $this->registration->periods()->whereDate('starts_on', '2027-01-01')->firstOrFail();
        $adjustment = app(TaxAdjustmentService::class)->post($this->company, ['tax_registration_id' => $this->registration->id, 'tax_period_id' => $period->id, 'tax_code_id' => $this->codes['STANDARD']->id, 'branch_id' => $this->company->branches()->value('id'), 'adjustment_date' => '2027-03-31', 'amount' => '5.0000', 'direction' => 'output', 'offset_account_id' => $this->account('5000'), 'reason' => 'Acceptance tax adjustment'], $this->user);
        $balance = \DB::table('journal_lines')->where('journal_entry_id', $adjustment->journal_entry_id)->selectRaw('SUM(debit-credit) balance')->value('balance');
        $this->assertSame(0, bccomp((string) $balance, '0', 4));
        $this->assertDatabaseHas('transaction_tax_lines', ['source_id' => $adjustment->id, 'tax_amount' => '5.0000']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'tax_adjustment.posted']);
        $summary = app(TaxReportService::class)->summary($this->company, ['tax_period_id' => $period->id]);
        $this->assertSame('5.0000', $summary['adjustments']);
        $this->assertSame('5.0000', $summary['net']);
        $this->assertSame('0.0000', $summary['output_difference']);
        $this->assertDatabaseCount('tax_adjustments', 1);
        $snapshotCount = TransactionTaxLine::count();
        $journal = app(JournalService::class)->create($this->company, ['branch_id' => $this->company->branches()->value('id'), 'transaction_date' => '2027-03-31', 'description' => 'Manual tax control test', 'lines' => [['account_id' => $this->account('5000'), 'description' => 'Manual test', 'debit' => '1', 'credit' => '0'], ['account_id' => $this->account('2100'), 'description' => 'Manual test', 'debit' => '0', 'credit' => '1']]], $this->user);
        app(JournalService::class)->post($journal, $this->user);
        $this->assertSame($snapshotCount, TransactionTaxLine::count());
        $this->assertSame('-1.0000', app(TaxReportService::class)->summary($this->company, ['tax_period_id' => $period->id])['output_difference']);
    }

    public function test_cross_jurisdiction_defaults_accounts_routes_and_authorization_are_rejected(): void
    {
        $other = app(CompanyCreator::class)->create(['entity_type' => 'company', 'name' => 'India Books', 'legal_name' => 'India Books Ltd', 'country_id' => Country::where('code', 'IN')->value('id'), 'base_currency_id' => Currency::where('code', 'INR')->value('id'), 'timezone' => 'Asia/Kolkata', 'financial_year_start' => '2027-04-01', 'financial_year_end' => '2028-03-31'], $this->user);
        $this->assertThrows(fn () => $this->configuration->settings($this->company, ['output_tax_account_id' => $other->accounts()->where('type', 'liability')->value('id'), 'rounding_method' => 'per_line'], $this->user));
        $this->assertThrows(fn () => $this->configuration->settings($other, ['default_sales_tax_code_id' => $this->codes['STANDARD']->id, 'rounding_method' => 'per_line'], $this->user));
        $stranger = User::factory()->create();
        $this->assertThrows(fn () => $this->configuration->updateRegistration($this->company, $this->registration, $this->registration->toArray(), $stranger));
        $this->actingAs($this->user)->get(route('tax.workspace', ['IN', $this->company]))->assertNotFound();
        $this->get(route('tax.workspace', ['NZ', $other]))->assertNotFound();
        $foreignRegistration = $this->configuration->registration($other, ['tax_type' => 'GST', 'name' => 'Foreign Tax', 'registration_number' => 'FOREIGN-1', 'registration_name' => 'India Books', 'effective_from' => '2027-04-01', 'effective_to' => '2028-03-31', 'filing_frequency' => 'quarterly', 'accounting_basis' => 'accrual', 'status' => 'active'], $this->user);
        $foreignCode = $this->configuration->code($other, ['tax_registration_id' => $foreignRegistration->id, 'tax_type' => 'GST', 'code' => 'FOREIGN', 'name' => 'Foreign', 'treatment' => 'taxable', 'recoverability_type' => 'full', 'effective_from' => '2027-04-01', 'is_active' => true], $this->user);
        $this->get(route('tax.workspace', ['NZ', $this->company, 'tax_code_id' => $foreignCode->id]))->assertNotFound();
        $this->get(route('tax.workspace', ['NZ', $this->company, 'tax_registration_id' => $foreignRegistration->id]))->assertNotFound();
        $this->assertDatabaseCount('transaction_tax_lines', 0);
    }

    private function account(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    private function assertJournalLine(int $journal, string $accountCode, string $debit, string $credit): void
    {
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journal, 'account_id' => $this->account($accountCode), 'debit' => $debit, 'credit' => $credit]);
    }

    private function taxAccount(string $code, string $name, string $type, string $normalBalance): void
    {
        $this->company->accounts()->create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true, 'is_system' => false, 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
    }
}

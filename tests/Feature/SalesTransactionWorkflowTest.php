<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\SalesService;
use App\Services\SalesWorkflowService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class SalesTransactionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private SalesService $sales;

    private SalesWorkflowService $workflow;

    private $customer;

    private $item;

    private $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['name' => 'Workflow Books', 'legal_name' => 'Workflow Books Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
        $this->period = $this->company->financialYears()->first()->periods()->first();
        $this->sales = app(SalesService::class);
        $this->workflow = app(SalesWorkflowService::class);
        $this->customer = $this->sales->createCustomer($this->company, ['code' => 'C-01', 'name' => 'Workflow Customer', 'currency_id' => $this->company->base_currency_id, 'receivable_account_id' => $this->company->accounts()->where('code', '1100')->value('id'), 'payment_terms_days' => 30, 'credit_limit' => 10000, 'is_active' => true], $this->user);
        $this->item = $this->sales->createItem($this->company, ['code' => 'SVC', 'name' => 'Consulting', 'type' => 'service', 'unit' => 'hour', 'sales_price' => 100, 'revenue_account_id' => $this->company->accounts()->where('type', 'revenue')->value('id'), 'is_active' => true], $this->user);
    }

    public function test_quotation_to_order_to_invoice_preserves_source_branch_and_lines(): void
    {
        $quotation = $this->workflow->create($this->company, 'quotations', $this->quotationData(), $this->user);
        $order = $this->workflow->quotationToOrder($this->company, $quotation, $this->user);
        $this->assertSame($quotation->id, $order->sales_quotation_id);
        $this->assertSame($quotation->branch_id, $order->branch_id);
        $this->assertSame('converted', $quotation->fresh()->status);
        $invoice = $this->workflow->orderToInvoice($this->company, $order, ['accounting_period_id' => $this->period->id, 'invoice_date' => '2026-01-10', 'due_date' => '2026-02-09'], $this->user);
        $this->assertSame($order->id, $invoice->sales_order_id);
        $this->assertSame($order->branch_id, $invoice->branch_id);
        $this->assertSame($order->lines()->first()->description, $invoice->lines()->first()->description);
        $this->sales->postInvoice($invoice, $this->user);
        $this->assertSame($invoice->branch_id, $invoice->fresh()->journal->branch_id);
        $this->assertThrows(fn () => $this->sales->postInvoice($invoice->fresh(), $this->user), ValidationException::class);
    }

    public function test_draft_update_delete_ui_and_cross_company_security(): void
    {
        $quotation = $this->workflow->create($this->company, 'quotations', $this->quotationData(), $this->user);
        $data = $this->quotationData();
        $data['notes'] = 'Corrected';
        $this->assertSame('Corrected', $this->workflow->update($this->company, 'quotations', $quotation, $data, $this->user)->notes);
        $this->actingAs($this->user)->get(route('sales.transactions.index', [$this->company, 'quotations']))->assertOk()->assertSee('New Quotation');
        $this->get(route('sales.transactions.create', [$this->company, 'invoices']))->assertOk()->assertSee('Product / Service')->assertSee('Add Line');
        $other = User::factory()->create();
        $this->actingAs($other)->get(route('sales.transactions.show', [$this->company, 'quotations', $quotation]))->assertNotFound();
        $this->workflow->delete($this->company, $quotation, $this->user);
        $this->assertDatabaseMissing('sales_quotations', ['id' => $quotation->id]);
    }

    public function test_shared_line_editor_uses_sensible_precision_fixed_decimal_totals_and_preserves_multiple_lines(): void
    {
        $data = $this->quotationData();
        $data['lines'][0]['quantity'] = '1.2345';
        $data['lines'][] = ['item_id' => $this->item->id, 'revenue_account_id' => $this->item->revenue_account_id, 'description' => 'Second line', 'quantity' => '2.5000', 'unit_price' => '12.3456', 'discount' => '0.1234'];
        $quotation = $this->workflow->create($this->company, 'quotations', $data, $this->user);
        $this->assertSame('1.2345', $quotation->lines()->first()->quantity);
        $this->assertSame('12.3456', $quotation->lines()->latest('id')->first()->unit_price);
        $this->assertCount(2, $quotation->lines);

        $this->actingAs($this->user)->get(route('sales.transactions.edit', [$this->company, 'quotations', $quotation]))
            ->assertOk()
            ->assertSee('step="0.01"', false)
            ->assertSee('Unit Price ('.$this->company->baseCurrency->code.')')
            ->assertSee('Discount Amount ('.$this->company->baseCurrency->code.')')
            ->assertSee('+ Add Line')
            ->assertSee('lines[1][quantity]', false)
            ->assertSee('scaledDecimal', false)
            ->assertSee('Document Total:', false);

        array_shift($data['lines']);
        $updated = $this->workflow->update($this->company, 'quotations', $quotation, $data, $this->user);
        $this->assertCount(1, $updated->lines);
        $this->assertSame('Second line', $updated->lines->first()->description);
    }

    public function test_partial_and_full_receipts_allocate_and_post_once(): void
    {
        $invoice = $this->sales->postInvoice($this->invoice(), $this->user);
        $partial = $this->workflow->create($this->company, 'receipts', $this->receiptData($invoice, 40), $this->user);
        $this->sales->postReceipt($partial, $this->user);
        $this->assertSame('partially_paid', $invoice->fresh()->status);
        $this->assertSame('40.0000', $invoice->fresh()->amount_paid);
        $full = $this->workflow->create($this->company, 'receipts', $this->receiptData($invoice->fresh(), 60), $this->user);
        $this->sales->postReceipt($full, $this->user);
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame($full->branch_id, $full->fresh()->journal->branch_id);
        $this->assertThrows(fn () => $full->fresh()->update(['reference' => 'changed']), LogicException::class);
    }

    public function test_credit_note_posts_reversal_and_cannot_exceed_remaining_invoice(): void
    {
        $invoice = $this->sales->postInvoice($this->invoice(), $this->user);
        $credit = $this->workflow->create($this->company, 'credit-notes', $this->creditData($invoice, 25), $this->user);
        $this->sales->postCreditNote($credit, $this->user);
        $this->assertSame('posted', $credit->fresh()->status);
        $this->assertSame($credit->branch_id, $credit->fresh()->journal->branch_id);
        $this->assertThrows(fn () => $this->workflow->create($this->company, 'credit-notes', $this->creditData($invoice, 80), $this->user), ValidationException::class);
        $this->assertThrows(fn () => $credit->fresh()->delete(), LogicException::class);
    }

    private function quotationData(): array
    {
        return ['customer_id' => $this->customer->id, 'branch_id' => $this->company->branches()->first()->id, 'quotation_date' => '2026-01-02', 'expiry_date' => '2026-02-02', 'customer_reference' => 'PO-1', 'notes' => 'Test', 'status' => 'draft', 'lines' => $this->lines()];
    }

    private function invoice()
    {
        return $this->workflow->create($this->company, 'invoices', ['customer_id' => $this->customer->id, 'branch_id' => $this->company->branches()->first()->id, 'accounting_period_id' => $this->period->id, 'invoice_date' => '2026-01-05', 'due_date' => '2026-02-05', 'lines' => $this->lines()], $this->user);
    }

    private function receiptData($invoice, float $amount): array
    {
        return ['customer_id' => $this->customer->id, 'branch_id' => $invoice->branch_id, 'accounting_period_id' => $this->period->id, 'receipt_date' => '2026-01-15', 'amount' => $amount, 'payment_method' => 'Bank Transfer', 'reference' => 'PAY', 'receiving_account_id' => $this->company->accounts()->where('code', '1000')->value('id'), 'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => $amount]]];
    }

    private function creditData($invoice, float $amount): array
    {
        return ['customer_id' => $this->customer->id, 'branch_id' => $invoice->branch_id, 'sales_invoice_id' => $invoice->id, 'accounting_period_id' => $this->period->id, 'credit_note_date' => '2026-01-15', 'notes' => 'Adjustment', 'lines' => [['item_id' => $this->item->id, 'revenue_account_id' => $this->item->revenue_account_id, 'description' => 'Credit', 'quantity' => 1, 'unit_price' => $amount]]];
    }

    private function lines(): array
    {
        return [['item_id' => $this->item->id, 'revenue_account_id' => $this->item->revenue_account_id, 'description' => 'Consulting', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]];
    }
}

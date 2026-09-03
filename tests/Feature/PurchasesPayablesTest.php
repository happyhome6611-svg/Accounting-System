<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\DashboardService;
use App\Services\PayablesReportService;
use App\Services\PurchaseService;
use App\Services\SupplierMaintenanceService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PurchasesPayablesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['entity_type' => 'company', 'name' => 'AP Books', 'legal_name' => 'AP Books Ltd', 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
        $this->supplier = $this->supplier();
    }

    public function test_supplier_master_maintenance_security_and_safe_delete(): void
    {
        $service = app(SupplierMaintenanceService::class);
        $service->update($this->company, $this->supplier, [...$this->supplierData(), 'code' => 'SUP-X', 'name' => 'Updated Supplier'], $this->user);
        $this->assertSame('Updated Supplier', $this->supplier->fresh()->name);
        $service->setActive($this->company, $this->supplier, false, $this->user);
        $this->assertFalse($this->supplier->fresh()->is_active);
        $service->setActive($this->company, $this->supplier, true, $this->user);
        $duplicate = $this->supplier('SUP-2', 'Delete Me');
        $service->delete($this->company, $duplicate, 'Delete Me', $this->user);
        $this->assertDatabaseMissing('suppliers', ['id' => $duplicate->id]);
        $this->actingAs($this->user)->post(route('purchases.suppliers.store', $this->company), [...$this->supplierData(), 'code' => 'SUP-X'])->assertSessionHasErrors('code');
        $other = $this->entity('Other');
        $this->get(route('purchases.suppliers.edit', [$other, $this->supplier]))->assertNotFound();
    }

    public function test_purchase_order_draft_edit_delete_and_conversion_has_no_order_journal(): void
    {
        $service = app(PurchaseService::class);
        $order = $service->create($this->company, 'orders', $this->lineData('orders'), $this->user);
        $this->assertSame('PO-000001', $order->purchase_order_number);
        $this->assertDatabaseCount('journal_entries', 0);
        $updated = $service->update($this->company, 'orders', $order, [...$this->lineData('orders'), 'lines' => [$this->line('2', '75', '0')]], $this->user);
        $this->assertSame('150.0000', $updated->total);
        $bill = $service->convertOrder($this->company, $order, ['bill_date' => '2026-09-03', 'due_date' => '2026-10-03', 'accounting_period_id' => null, 'financial_year_id' => null], $this->user);
        $this->assertSame($order->id, $bill->purchase_order_id);
        $this->assertSame('billed', $order->fresh()->status);
        $this->assertDatabaseCount('journal_entries', 0);
        $draft = $service->create($this->company, 'orders', $this->lineData('orders'), $this->user);
        $service->deleteDraft($this->company, $draft, $this->user);
        $this->assertDatabaseMissing('purchase_orders', ['id' => $draft->id]);
    }

    public function test_bill_posts_balanced_expense_and_payable_and_is_immutable(): void
    {
        $service = app(PurchaseService::class);
        $bill = $service->create($this->company, 'bills', $this->lineData('bills'), $this->user);
        $this->assertNotNull($bill->financial_year_id);
        $this->assertNotNull($bill->accounting_period_id);
        $service->post($this->company, 'bills', $bill, $this->user);
        $bill = $bill->fresh();
        $this->assertSame('posted', $bill->status);
        $journal = $bill->journal_entry_id;
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journal, 'account_id' => $this->expense()->id, 'debit' => '100.0000']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $journal, 'account_id' => $this->payable()->id, 'credit' => '100.0000']);
        $this->assertSame(0, bccomp((string) \DB::table('journal_lines')->where('journal_entry_id', $journal)->selectRaw('SUM(debit-credit) balance')->value('balance'), '0', 4));
        $this->expectException(LogicException::class);
        $bill->update(['notes' => 'forged']);
    }

    public function test_credit_and_partial_multi_bill_payment_reduce_outstanding_with_correct_journals(): void
    {
        $service = app(PurchaseService::class);
        $billA = $service->create($this->company, 'bills', $this->lineData('bills'), $this->user);
        $billB = $service->create($this->company, 'bills', [...$this->lineData('bills'), 'lines' => [$this->line('1', '500', '0')]], $this->user);
        $service->post($this->company, 'bills', $billA, $this->user);
        $service->post($this->company, 'bills', $billB, $this->user);
        $credit = $service->create($this->company, 'credits', [...$this->lineData('credits'), 'supplier_bill_id' => $billB->id, 'lines' => [$this->line('1', '50', '0')]], $this->user);
        $service->post($this->company, 'credits', $credit, $this->user);
        $this->assertSame('450.0000', $billB->fresh()->amount_due);
        $payment = $service->create($this->company, 'payments', $this->paymentData('400', [$billA->id => '100', $billB->id => '300']), $this->user);
        $service->post($this->company, 'payments', $payment, $this->user);
        $this->assertSame('0.0000', $billA->fresh()->amount_due);
        $this->assertSame('150.0000', $billB->fresh()->amount_due);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $payment->fresh()->journal_entry_id, 'account_id' => $this->payable()->id, 'debit' => '400.0000']);
        $this->expectException(ValidationException::class);
        $service->create($this->company, 'payments', $this->paymentData('200', [$billB->id => '200']), $this->user);
    }

    public function test_reports_dashboard_branch_and_individual_isolation(): void
    {
        $service = app(PurchaseService::class);
        $bill = $service->create($this->company, 'bills', [...$this->lineData('bills'), 'due_date' => '2026-08-01'], $this->user);
        $service->post($this->company, 'bills', $bill, $this->user);
        $reports = app(PayablesReportService::class);
        $this->assertCount(1, $reports->outstanding($this->company));
        $this->assertSame('100.0000', $reports->aging($this->company, '2026-09-03')['totals']['31–60']);
        $metrics = app(DashboardService::class)->metrics($this->company, $this->company->branches()->first()->id, $bill->financial_year_id);
        $this->assertSame('100.0000', $metrics['payables']);
        $this->assertSame(1, $metrics['unpaid_bills']);
        $this->actingAs($this->user)->get(route('purchases', ['country_id' => $this->company->country_id]))->assertOk()->assertSee('Accounts Payable');
        $this->get(route('purchases.reports', ['type' => 'ap', 'company_id' => $this->company->id]))->assertOk()->assertSee($bill->bill_number);
        $this->get(route('purchases.reports', ['type' => 'aging', 'company_id' => $this->company->id]))->assertOk()->assertSee('31–60');
        $other = $this->entity('India AP', 'IN', 'INR');
        $this->actingAs($this->user)->get(route('purchases.documents', [$other, 'bills']))->assertOk()->assertDontSee($bill->bill_number);
        $individual = $this->entity('Personal', 'NZ', 'NZD', 'individual');
        $this->get(route('purchases.suppliers', $individual))->assertNotFound();
    }

    public function test_closed_period_and_cross_company_account_branch_supplier_are_rejected(): void
    {
        $other = $this->entity('Other');
        $data = $this->lineData('bills');
        $data['branch_id'] = $other->branches()->first()->id;
        $this->assertThrows(fn () => app(PurchaseService::class)->create($this->company, 'bills', $data, $this->user), ModelNotFoundException::class);
        $data = $this->lineData('bills');
        $data['lines'][0]['expense_account_id'] = $other->accounts()->first()->id;
        $this->assertThrows(fn () => app(PurchaseService::class)->create($this->company, 'bills', $data, $this->user), ModelNotFoundException::class);
        $period = $this->company->financialYears()->first()->periods()->whereDate('starts_on', '<=', '2026-09-03')->whereDate('ends_on', '>=', '2026-09-03')->first();
        $period->update(['status' => 'closed']);
        $this->assertThrows(fn () => app(PurchaseService::class)->create($this->company, 'bills', $this->lineData('bills'), $this->user), ValidationException::class);
    }

    private function supplier(string $code = 'SUP-1', string $name = 'Paper Supplier'): Supplier
    {
        return app(SupplierMaintenanceService::class)->create($this->company, [...$this->supplierData(), 'code' => $code, 'name' => $name], $this->user);
    }

    private function supplierData(): array
    {
        return ['code' => 'SUP-1', 'name' => 'Paper Supplier', 'legal_name' => null, 'type' => 'business', 'email' => null, 'phone' => null, 'address' => null, 'country_id' => null, 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 30, 'credit_limit' => '0', 'payable_account_id' => $this->payable()->id, 'notes' => null, 'is_active' => true];
    }

    private function line(string $q = '1', string $p = '100', string $d = '0'): array
    {
        return ['item_id' => null, 'expense_account_id' => $this->expense()->id, 'description' => 'Office supplies', 'quantity' => $q, 'unit_price' => $p, 'discount' => $d];
    }

    private function lineData(string $type): array
    {
        $date = ['orders' => 'order_date', 'bills' => 'bill_date', 'credits' => 'credit_date'][$type];

        return ['supplier_id' => $this->supplier->id, 'branch_id' => $this->company->branches()->first()->id, 'financial_year_id' => null, 'accounting_period_id' => null, $date => '2026-09-03', ...($type === 'bills' ? ['due_date' => '2026-10-03'] : []), 'supplier_reference' => null, 'notes' => null, 'lines' => [$this->line()]];
    }

    private function paymentData(string $amount, array $allocations): array
    {
        return ['supplier_id' => $this->supplier->id, 'branch_id' => $this->company->branches()->first()->id, 'financial_year_id' => null, 'accounting_period_id' => null, 'payment_date' => '2026-09-03', 'payment_account_id' => $this->company->accounts()->where('code', '1000')->value('id'), 'amount' => $amount, 'reference' => null, 'notes' => null, 'allocations' => collect($allocations)->map(fn ($value, $id) => ['supplier_bill_id' => $id, 'amount' => $value])->values()->all()];
    }

    private function payable()
    {
        return $this->company->accounts()->where('code', '2000')->first();
    }

    private function expense()
    {
        return $this->company->accounts()->where('code', '5000')->first();
    }

    private function entity(string $name, string $country = 'NZ', string $currency = 'NZD', string $type = 'company'): Company
    {
        return app(CompanyCreator::class)->create(['entity_type' => $type, 'name' => $name, 'legal_name' => $type === 'company' ? $name : null, 'individual_name' => $type === 'individual' ? $name : null, 'country_id' => Country::where('code', $country)->value('id'), 'base_currency_id' => Currency::where('code', $currency)->value('id'), 'timezone' => 'UTC', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
    }
}

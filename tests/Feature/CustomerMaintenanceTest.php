<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\CustomerMaintenanceService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CustomerMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Customer $customer;

    private CustomerMaintenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['name' => 'Customer Co', 'legal_name' => 'Customer Co Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
        $this->customer = $this->customer($this->company, 'C001');
        $this->service = app(CustomerMaintenanceService::class);
    }

    private function customer(Company $company, string $code): Customer
    {
        return app(SalesService::class)->createCustomer($company, ['code' => $code, 'name' => 'Original Name', 'email' => 'old@example.com', 'type' => 'business', 'currency_id' => $company->base_currency_id, 'receivable_account_id' => $company->accounts()->where('code', '1100')->value('id'), 'payment_terms_days' => 30, 'credit_limit' => 100, 'is_active' => true], $this->user);
    }

    private function data(array $overrides = []): array
    {
        return array_merge(['code' => $this->customer->code, 'name' => $this->customer->name, 'legal_name' => null, 'type' => 'business', 'email' => $this->customer->email, 'phone' => '123', 'billing_address' => 'Billing', 'shipping_address' => 'Shipping', 'country_id' => null, 'currency_id' => $this->customer->currency_id, 'payment_terms_days' => 30, 'credit_limit' => 100, 'receivable_account_id' => $this->customer->receivable_account_id], $overrides);
    }

    public function test_unused_customer_can_be_edited_and_corrected(): void
    {
        $updated = $this->service->update($this->company, $this->customer, $this->data(['code' => 'C002', 'name' => 'Correct Name', 'email' => 'new@example.com']), $this->user);
        $this->assertSame('C002', $updated->code);
        $this->assertSame('Correct Name', $updated->name);
        $this->assertSame('new@example.com', $updated->email);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer.updated', 'auditable_id' => $this->customer->id]);
    }

    public function test_duplicate_code_and_cross_company_edit_are_rejected(): void
    {
        $this->customer($this->company, 'DUP');
        $this->actingAs($this->user)->put(route('sales.customers.update', [$this->company, $this->customer]), $this->data(['code' => 'DUP']))->assertSessionHasErrors('code');
        $other = app(CompanyCreator::class)->create(['name' => 'Other', 'legal_name' => 'Other', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
        $foreign = $this->customer($other, 'FOREIGN');
        $this->actingAs($this->user)->put(route('sales.customers.update', [$this->company, $foreign]), $this->data())->assertNotFound();
    }

    public function test_customer_can_be_deactivated_and_reactivated_and_inactive_is_rejected_for_sales(): void
    {
        $this->service->setActive($this->company, $this->customer, false, $this->user);
        $this->assertFalse($this->customer->fresh()->is_active);
        $lines = [['revenue_account_id' => $this->company->accounts()->where('type', 'revenue')->value('id'), 'description' => 'Work', 'quantity' => 1, 'unit_price' => 10, 'discount' => 0]];
        $this->assertThrows(fn () => app(SalesService::class)->createQuotation($this->company, ['customer_id' => $this->customer->id, 'quotation_date' => '2026-01-01', 'status' => 'draft', 'lines' => $lines], $this->user), ValidationException::class);
        $this->service->setActive($this->company, $this->customer, true, $this->user);
        $this->assertTrue($this->customer->fresh()->is_active);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer.deactivated']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'customer.reactivated']);
    }

    public function test_unused_customer_can_be_permanently_deleted_with_exact_confirmation(): void
    {
        $id = $this->customer->id;
        $this->service->delete($this->company, $this->customer, $this->user, $this->customer->name);
        $this->assertDatabaseMissing('customers', ['id' => $id]);
        $this->assertDatabaseMissing('audit_logs', ['auditable_type' => Customer::class, 'auditable_id' => $id]);
    }

    public function test_forged_and_cross_company_deletion_are_rejected(): void
    {
        $this->assertThrows(fn () => $this->service->delete($this->company, $this->customer, $this->user, 'wrong'), ValidationException::class);
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->delete(route('sales.customers.destroy', [$this->company, $this->customer]), ['confirmation_name' => $this->customer->name])->assertNotFound();
        $this->assertDatabaseHas('customers', ['id' => $this->customer->id]);
    }

    public function test_each_transaction_type_blocks_permanent_deletion(): void
    {
        foreach (['sales_quotations', 'sales_orders', 'sales_invoices', 'sales_credit_notes', 'customer_receipts'] as $table) {
            $company = app(CompanyCreator::class)->create(['name' => $table, 'legal_name' => $table, 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
            $customer = $this->customer($company, $table);
            DB::table($table)->insert($this->document($table, $company, $customer));
            $this->assertFalse($this->service->isDeletable($customer), $table);
            $this->assertThrows(fn () => $this->service->delete($company, $customer, $this->user, $customer->name), ValidationException::class);
        }
    }

    private function document(string $table, Company $c, Customer $customer): array
    {
        $base = ['company_id' => $c->id, 'customer_id' => $customer->id, 'status' => 'draft', 'created_by' => $this->user->id, 'updated_by' => $this->user->id, 'created_at' => now(), 'updated_at' => now()];
        $period = $c->financialYears->first()->periods()->first();

        return match ($table) {
            'sales_quotations' => $base + ['quotation_number' => 'Q1', 'quotation_date' => '2026-01-01', 'subtotal' => 0, 'total' => 0],'sales_orders' => $base + ['order_number' => 'O1', 'order_date' => '2026-01-01', 'subtotal' => 0, 'total' => 0],'sales_invoices' => $base + ['accounting_period_id' => $period->id, 'currency_id' => $c->base_currency_id, 'invoice_number' => 'I1', 'invoice_date' => '2026-01-01', 'due_date' => '2026-01-01', 'subtotal' => 0, 'total' => 0],'sales_credit_notes' => $base + ['accounting_period_id' => $period->id, 'credit_note_number' => 'N1', 'credit_note_date' => '2026-01-01', 'total' => 0],'customer_receipts' => $base + ['accounting_period_id' => $period->id, 'receipt_number' => 'R1', 'receipt_date' => '2026-01-01', 'amount' => 0, 'payment_method' => 'cash', 'receiving_account_id' => $c->accounts[0]->id]
        };
    }

    public function test_customer_edit_does_not_change_historical_accounting_records(): void
    {
        $period = $this->company->financialYears->first()->periods()->first();
        $invoice = app(SalesService::class)->createInvoice($this->company, ['customer_id' => $this->customer->id, 'accounting_period_id' => $period->id, 'invoice_date' => '2026-01-01', 'due_date' => '2026-01-31', 'lines' => [['revenue_account_id' => $this->company->accounts()->where('type', 'revenue')->value('id'), 'description' => 'Work', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]]], $this->user);
        app(SalesService::class)->postInvoice($invoice, $this->user);
        $before = $invoice->fresh()->journal->lines()->orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray();
        $this->service->update($this->company, $this->customer, $this->data(['name' => 'Renamed', 'receivable_account_id' => $this->company->accounts()->where('code', '1000')->value('id')]), $this->user);
        $after = $invoice->fresh()->journal->lines()->orderBy('id')->get(['account_id', 'debit', 'credit'])->toArray();
        $this->assertSame($before, $after);
        $this->assertSame('posted', $invoice->fresh()->status);
    }
}

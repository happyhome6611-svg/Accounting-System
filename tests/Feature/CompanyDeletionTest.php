<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Item;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\CompanyDeletionService;
use App\Services\JournalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CompanyDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private CompanyDeletionService $deletion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->owner = User::factory()->create();
        $this->company = $this->makeCompany($this->owner, 'Unused Company');
        $this->deletion = app(CompanyDeletionService::class);
    }

    private function makeCompany(User $user, string $name): Company
    {
        return app(CompanyCreator::class)->create(['name' => $name, 'legal_name' => $name.' Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $user);
    }

    public function test_unused_company_and_generated_setup_can_be_permanently_deleted_without_affecting_another_company(): void
    {
        $other = $this->makeCompany($this->owner, 'Keep Company');
        $yearIds = $this->company->financialYears()->pluck('id');
        $accountIds = $this->company->accounts()->pluck('id');
        $this->assertTrue($this->deletion->isEligible($this->company));
        $this->deletion->delete($this->company, $this->owner, $this->company->name);
        $this->assertDatabaseMissing('companies', ['id' => $this->company->id]);
        $this->assertDatabaseMissing('financial_years', ['company_id' => $this->company->id]);
        $this->assertDatabaseMissing('accounting_periods', ['company_id' => $this->company->id]);
        $this->assertDatabaseMissing('accounts', ['company_id' => $this->company->id]);
        $this->assertDatabaseHas('companies', ['id' => $other->id]);
        $this->assertNotEmpty($yearIds);
        $this->assertNotEmpty($accountIds);
    }

    public function test_exact_name_confirmation_is_required(): void
    {
        $this->assertThrows(fn () => $this->deletion->delete($this->company, $this->owner, 'wrong'), ValidationException::class);
        $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
    }

    public function test_posted_or_draft_journal_blocks_deletion(): void
    {
        foreach ([false, true] as $post) {
            $company = $this->makeCompany($this->owner, $post ? 'Posted Co' : 'Draft Co');
            $period = $company->financialYears->first()->periods()->first();
            $accounts = $company->accounts;
            $journal = app(JournalService::class)->create($company, ['financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'transaction_date' => '2026-01-02', 'description' => 'Data', 'lines' => [['account_id' => $accounts[0]->id, 'debit' => 1, 'credit' => 0], ['account_id' => $accounts[4]->id, 'debit' => 0, 'credit' => 1]]], $this->owner);
            if ($post) {
                app(JournalService::class)->post($journal, $this->owner);
            }$this->assertFalse($this->deletion->isEligible($company));
            $this->assertThrows(fn () => $this->deletion->delete($company, $this->owner, $company->name), ValidationException::class);
        }
    }

    public function test_customer_or_item_blocks_deletion(): void
    {
        $customerCompany = $this->makeCompany($this->owner, 'Customer Co');
        Customer::create(['company_id' => $customerCompany->id, 'code' => 'C1', 'name' => 'Customer', 'currency_id' => $customerCompany->base_currency_id, 'receivable_account_id' => $customerCompany->accounts[1]->id, 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id]);
        $itemCompany = $this->makeCompany($this->owner, 'Item Co');
        Item::create(['company_id' => $itemCompany->id, 'code' => 'I1', 'name' => 'Item', 'type' => 'service', 'unit' => 'each', 'sales_price' => 1, 'revenue_account_id' => $itemCompany->accounts[4]->id, 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id]);
        $this->assertContains('customers', $this->deletion->blockers($customerCompany));
        $this->assertContains('products or services', $this->deletion->blockers($itemCompany));
    }

    public function test_every_sales_document_type_blocks_deletion(): void
    {
        foreach (['sales_quotations', 'sales_orders', 'sales_invoices', 'sales_credit_notes', 'customer_receipts'] as $table) {
            $company = $this->makeCompany($this->owner, $table);
            DB::table($table)->insert($this->minimalDocument($table, $company));
            $this->assertFalse($this->deletion->isEligible($company), $table);
        }
    }

    private function minimalDocument(string $table, Company $c): array
    {
        $customer = Customer::create(['company_id' => $c->id, 'code' => 'C1', 'name' => 'C', 'currency_id' => $c->base_currency_id, 'receivable_account_id' => $c->accounts[1]->id, 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id]);
        $base = ['company_id' => $c->id, 'customer_id' => $customer->id, 'status' => 'draft', 'created_by' => $this->owner->id, 'updated_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()];
        $period = $c->financialYears->first()->periods()->first();

        return match ($table) {
            'sales_quotations' => $base + ['quotation_number' => 'Q1', 'quotation_date' => '2026-01-01', 'subtotal' => 0, 'total' => 0],'sales_orders' => $base + ['order_number' => 'O1', 'order_date' => '2026-01-01', 'subtotal' => 0, 'total' => 0],'sales_invoices' => $base + ['accounting_period_id' => $period->id, 'currency_id' => $c->base_currency_id, 'invoice_number' => 'I1', 'invoice_date' => '2026-01-01', 'due_date' => '2026-01-01', 'subtotal' => 0, 'total' => 0],'sales_credit_notes' => $base + ['accounting_period_id' => $period->id, 'credit_note_number' => 'N1', 'credit_note_date' => '2026-01-01', 'total' => 0],'customer_receipts' => $base + ['accounting_period_id' => $period->id, 'receipt_number' => 'R1', 'receipt_date' => '2026-01-01', 'amount' => 0, 'payment_method' => 'cash', 'receiving_account_id' => $c->accounts[0]->id]
        };
    }

    public function test_another_user_and_forged_requests_are_rejected(): void
    {
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('companies.delete', $this->company))->assertNotFound();
        $this->actingAs($outsider)->delete(route('companies.destroy', $this->company), ['confirmation_name' => $this->company->name])->assertNotFound();
        $this->actingAs($this->owner)->delete(route('companies.destroy', $this->company), ['confirmation_name' => 'forged'])->assertSessionHasErrors('confirmation_name');
        $this->assertDatabaseHas('companies', ['id' => $this->company->id]);
    }
}

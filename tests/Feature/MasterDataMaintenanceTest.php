<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Item;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\CompanyDeletionService;
use App\Services\CompanyMaintenanceService;
use App\Services\ItemMaintenanceService;
use App\Services\JournalService;
use App\Services\SalesService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MasterDataMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = $this->make('Master Co');
    }

    private function make(string $name): Company
    {
        return app(CompanyCreator::class)->create(['name' => $name, 'legal_name' => $name.' Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
    }

    private function item(Company $c, string $code = 'I1'): Item
    {
        return app(SalesService::class)->createItem($c, ['code' => $code, 'name' => 'Consulting', 'type' => 'service', 'unit' => 'hour', 'sales_price' => 100, 'revenue_account_id' => $c->accounts()->where('type', 'revenue')->value('id'), 'is_active' => true], $this->user);
    }

    public function test_company_profile_edits_and_critical_locks(): void
    {
        $s = app(CompanyMaintenanceService::class);
        $data = ['name' => 'Corrected', 'legal_name' => 'Corrected Ltd', 'country_id' => $this->company->country_id, 'base_currency_id' => $this->company->base_currency_id, 'timezone' => 'UTC', 'address' => 'A', 'email' => 'a@example.com', 'phone' => '1'];
        $s->update($this->company, $data, $this->user);
        $period = $this->company->financialYears->first()->periods()->first();
        $a = $this->company->accounts;
        app(JournalService::class)->create($this->company, ['financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'transaction_date' => '2026-01-01', 'description' => 'Draft', 'lines' => [['account_id' => $a[0]->id, 'debit' => 1, 'credit' => 0], ['account_id' => $a[4]->id, 'debit' => 0, 'credit' => 1]]], $this->user);
        $s->update($this->company, array_merge($data, ['name' => 'With History']), $this->user);
        $this->assertSame('With History', $this->company->fresh()->name);
        $this->assertThrows(fn () => $s->update($this->company, array_merge($data, ['base_currency_id' => Currency::whereKeyNot($this->company->base_currency_id)->value('id')]), $this->user), ValidationException::class);
        $this->assertThrows(fn () => $s->update($this->company, array_merge($data, ['country_id' => Country::whereKeyNot($this->company->country_id)->value('id')]), $this->user), ValidationException::class);
    }

    public function test_company_status_security_reports_and_deletion_regression(): void
    {
        $s = app(CompanyMaintenanceService::class);
        $s->setActive($this->company, false, $this->user);
        $this->assertThrows(fn () => app(SalesService::class)->createItem($this->company, ['code' => 'X', 'name' => 'X', 'type' => 'service', 'unit' => 'each', 'sales_price' => 1, 'revenue_account_id' => $this->company->accounts[4]->id], $this->user), ValidationException::class);
        $this->actingAs($this->user)->get(route('reports.trial', ['company_id' => $this->company->id]))->assertOk();
        $s->setActive($this->company, true, $this->user);
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->patch(route('companies.status', $this->company), ['is_active' => 0])->assertNotFound();
        $unused = $this->make('Delete Me');
        app(CompanyDeletionService::class)->delete($unused, $this->user, $unused->name);
        $this->assertDatabaseMissing('companies', ['id' => $unused->id]);
    }

    public function test_item_maintenance_and_presentation(): void
    {
        $item = $this->item($this->company);
        $s = app(ItemMaintenanceService::class);
        $s->update($this->company, $item, ['code' => 'I2', 'name' => 'Updated', 'description' => 'D', 'type' => 'service', 'unit' => 'day', 'sales_price' => 125, 'revenue_account_id' => $item->revenue_account_id], $this->user);
        $this->assertSame('125.0000', $item->fresh()->sales_price);
        $this->item($this->company, 'DUP');
        $this->actingAs($this->user)->put(route('sales.items.update', [$this->company, $item]), ['code' => 'DUP', 'name' => 'X', 'type' => 'service', 'unit' => 'x', 'sales_price' => 1, 'revenue_account_id' => $item->revenue_account_id])->assertSessionHasErrors('code');
        $s->setActive($this->company, $item, false, $this->user);
        $this->assertFalse($item->fresh()->is_active);
        $s->setActive($this->company, $item, true, $this->user);
        $unused = $this->item($this->company, 'DEL');
        $s->delete($this->company, $unused, $this->user, $unused->name);
        $this->assertDatabaseMissing('items', ['id' => $unused->id]);
        $this->actingAs($this->user)->get(route('sales.items', $this->company))->assertSee('Item Code')->assertSee('Sales Price')->assertSee('Edit')->assertSee('Deactivate')->assertSee('₹100.00');
    }

    public function test_referenced_item_is_preserved_and_inactive_rejected(): void
    {
        $item = $this->item($this->company);
        $customer = app(SalesService::class)->createCustomer($this->company, ['code' => 'C1', 'name' => 'C', 'currency_id' => $this->company->base_currency_id, 'receivable_account_id' => $this->company->accounts[1]->id, 'payment_terms_days' => 0, 'credit_limit' => 0, 'is_active' => true], $this->user);
        $lines = [['item_id' => $item->id, 'revenue_account_id' => $item->revenue_account_id, 'description' => 'Original', 'quantity' => 1, 'unit_price' => 100, 'discount' => 0]];
        $q = app(SalesService::class)->createQuotation($this->company, ['customer_id' => $customer->id, 'quotation_date' => '2026-01-01', 'status' => 'draft', 'lines' => $lines], $this->user);
        $s = app(ItemMaintenanceService::class);
        $this->assertFalse($s->isDeletable($item));
        $s->update($this->company, $item, ['code' => 'NEW', 'name' => 'New', 'description' => 'new', 'type' => 'service', 'unit' => 'day', 'sales_price' => 200, 'revenue_account_id' => $item->revenue_account_id], $this->user);
        $this->assertSame('Original', $q->lines()->first()->description);
        $s->setActive($this->company, $item, false, $this->user);
        $this->assertThrows(fn () => app(SalesService::class)->createOrder($this->company, ['customer_id' => $customer->id, 'order_date' => '2026-01-02', 'status' => 'draft', 'lines' => $lines], $this->user), ValidationException::class);
    }

    public function test_customer_defaults_to_ar_and_explicit_existing_account_unchanged(): void
    {
        $existing = app(SalesService::class)->createCustomer($this->company, ['code' => 'C1', 'name' => 'C', 'currency_id' => $this->company->base_currency_id, 'receivable_account_id' => $this->company->accounts[0]->id, 'payment_terms_days' => 0, 'credit_limit' => 0, 'is_active' => true], $this->user);
        $this->actingAs($this->user)->get(route('sales.customers', $this->company))->assertSee('value="'.$this->company->accounts[1]->id.'" selected', false);
        $this->assertSame($this->company->accounts[0]->id, $existing->fresh()->receivable_account_id);
    }
}

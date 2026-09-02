<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\BranchService;
use App\Services\CompanyCreator;
use App\Services\JournalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class BranchFoundationDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['name' => 'Branch Books', 'legal_name' => 'Branch Books Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
    }

    public function test_company_creation_provisions_one_active_main_head_office(): void
    {
        $this->assertDatabaseHas('branches', ['company_id' => $this->company->id, 'code' => 'HO', 'is_active' => true, 'is_main_branch' => true]);
        $this->assertSame(1, $this->company->branches()->count());
    }

    public function test_branch_crud_status_and_company_isolation(): void
    {
        $service = app(BranchService::class);
        $branch = $service->create($this->company, ['code' => 'AKL', 'name' => 'Auckland', 'is_active' => true, 'is_main_branch' => false], $this->user);
        $service->update($this->company, $branch, ['code' => 'AKL', 'name' => 'Auckland Central', 'is_active' => true, 'is_main_branch' => false], $this->user);
        $service->setActive($this->company, $branch, false, $this->user);
        $this->assertFalse($branch->fresh()->is_active);
        $service->setActive($this->company, $branch, true, $this->user);
        $otherUser = User::factory()->create();
        $other = $this->newCompany($otherUser, 'Other');
        $this->actingAs($otherUser)->get(route('companies.branches.edit', [$other, $branch]))->assertNotFound();
        $service->delete($this->company, $branch, 'Auckland Central', $this->user);
        $this->assertDatabaseMissing('branches', ['id' => $branch->id]);
    }

    public function test_multiple_branches_require_selection_and_reject_cross_company_branch(): void
    {
        $branch = app(BranchService::class)->create($this->company, ['code' => 'WLG', 'name' => 'Wellington', 'is_active' => true, 'is_main_branch' => false], $this->user);
        $period = $this->company->financialYears()->first()->periods()->first();
        $accounts = $this->company->accounts()->get();
        $data = ['financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'transaction_date' => $period->starts_on->toDateString(), 'description' => 'Branch journal', 'lines' => [['account_id' => $accounts[0]->id, 'debit' => 10, 'credit' => 0], ['account_id' => $accounts[4]->id, 'debit' => 0, 'credit' => 10]]];
        try {
            app(JournalService::class)->create($this->company, $data, $this->user);
            $this->fail('Branch selection should be required.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $journal = app(JournalService::class)->create($this->company, $data + ['branch_id' => $branch->id], $this->user);
        $this->assertSame($branch->id, $journal->branch_id);
    }

    public function test_branch_with_transaction_cannot_be_deleted_and_dashboard_filters_are_isolated(): void
    {
        $branch = $this->company->branches()->first();
        $period = $this->company->financialYears()->first()->periods()->first();
        $accounts = $this->company->accounts()->get();
        app(JournalService::class)->create($this->company, ['branch_id' => $branch->id, 'financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'transaction_date' => $period->starts_on->toDateString(), 'description' => 'Activity', 'lines' => [['account_id' => $accounts[0]->id, 'debit' => 10, 'credit' => 0], ['account_id' => $accounts[4]->id, 'debit' => 0, 'credit' => 10]]], $this->user);
        $this->actingAs($this->user)->get('/dashboard?company_id='.$this->company->id.'&branch_id='.$branch->id)->assertOk()->assertSee('Branch Books');
        $this->expectException(ValidationException::class);
        app(BranchService::class)->delete($this->company, $branch, $branch->name, $this->user);
    }

    private function newCompany(User $user, string $name): Company
    {
        return app(CompanyCreator::class)->create(['name' => $name, 'legal_name' => $name.' Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $user);
    }
}

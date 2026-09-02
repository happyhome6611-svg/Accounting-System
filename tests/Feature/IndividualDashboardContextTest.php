<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\DashboardService;
use App\Services\JournalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IndividualDashboardContextTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private Company $individual;

    private Company $soleTrader;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = $this->entity('company', 'Business Entity');
        $this->individual = $this->entity('individual', 'Personal Entity');
        $this->soleTrader = $this->entity('sole_trader', 'Trader Entity');
    }

    public function test_company_individual_and_sole_trader_can_be_selected_without_context_404s(): void
    {
        $this->actingAs($this->user);

        $this->get(route('dashboard', ['company_id' => $this->company->id]))->assertOk()->assertSee('Business Entity');
        $this->get(route('dashboard', ['company_id' => $this->individual->id]))->assertOk()->assertSee('Personal Entity');
        $this->get(route('dashboard', ['company_id' => $this->soleTrader->id]))->assertOk()->assertSee('Trader Entity');
        $this->get(route('dashboard', ['company_id' => $this->company->id]))->assertOk()->assertSee('Business Entity');
    }

    public function test_individual_ignores_stale_branch_context_and_hides_business_actions(): void
    {
        $companyBranch = $this->company->branches()->first();
        $year = $this->individual->financialYears()->first();

        $this->actingAs($this->user)
            ->get(route('dashboard', ['company_id' => $this->individual->id, 'financial_year_id' => $year->id, 'branch_id' => $companyBranch->id]))
            ->assertOk()
            ->assertSee('Not applicable')
            ->assertDontSee('Manage Branches')
            ->assertDontSee('New Journal')
            ->assertDontSee('Products &amp; Services', false)
            ->assertSee('switchDashboardEntity', false);
    }

    public function test_zero_data_individual_metrics_are_isolated_from_company_activity(): void
    {
        $period = $this->company->financialYears()->first()->periods()->first();
        $accounts = $this->company->accounts;
        $journal = app(JournalService::class)->create($this->company, ['branch_id' => $this->company->branches()->first()->id, 'financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'transaction_date' => $period->starts_on->toDateString(), 'description' => 'Company-only cash', 'lines' => [['account_id' => $accounts->firstWhere('code', '1000')->id, 'debit' => '250.0000', 'credit' => '0'], ['account_id' => $accounts->firstWhere('code', '4000')->id, 'debit' => '0', 'credit' => '250.0000']]], $this->user);
        app(JournalService::class)->post($journal, $this->user);

        $companyMetrics = app(DashboardService::class)->metrics($this->company, null, $period->financial_year_id);
        $individualYear = $this->individual->financialYears()->first();
        $individualMetrics = app(DashboardService::class)->metrics($this->individual, null, $individualYear->id);

        $this->assertSame(0, bccomp($companyMetrics['cash'], '250.0000', 4));
        $this->assertSame(0, bccomp($individualMetrics['cash'], '0.0000', 4));
        $this->assertSame(0, bccomp($individualMetrics['receivables'], '0.0000', 4));
        $this->assertCount(0, $individualMetrics['activity']);
        $this->actingAs($this->user)->get(route('dashboard', ['company_id' => $this->individual->id]))->assertOk()->assertSee('No accounting activity yet.');
    }

    public function test_financial_year_is_entity_scoped_and_cross_entity_tampering_is_rejected(): void
    {
        $individualYear = $this->individual->financialYears()->first();
        $companyYear = $this->company->financialYears()->first();
        $this->actingAs($this->user)
            ->get(route('dashboard', ['company_id' => $this->individual->id, 'financial_year_id' => $individualYear->id]))
            ->assertOk()->assertSee($individualYear->name);
        $this->get(route('dashboard', ['company_id' => $this->individual->id, 'financial_year_id' => $companyYear->id]))
            ->assertNotFound();
    }

    public function test_company_and_sole_trader_branch_selectors_remain_functional(): void
    {
        $companyBranch = $this->company->branches()->first();
        $traderBranch = $this->soleTrader->branches()->first();
        $this->actingAs($this->user)
            ->get(route('dashboard', ['company_id' => $this->company->id, 'branch_id' => $companyBranch->id]))
            ->assertOk()->assertSee('All branches (consolidated)')->assertSee('Manage Branches');
        $this->get(route('dashboard', ['company_id' => $this->soleTrader->id, 'branch_id' => $traderBranch->id]))
            ->assertOk()->assertSee('All branches (consolidated)')->assertSee('Manage Branches');
    }

    private function entity(string $type, string $name): Company
    {
        return app(CompanyCreator::class)->create(['entity_type' => $type, 'name' => $name, 'legal_name' => $type === 'company' ? $name.' Ltd' : null, 'individual_name' => $type === 'company' ? null : $name, 'trading_name' => $type === 'sole_trader' ? $name : null, 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
    }
}

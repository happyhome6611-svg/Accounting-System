<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\FinancialYearService;
use App\Services\JournalService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingReportPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_show_branch_currency_period_and_preserve_navigation_filters(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $currency = Currency::where('code', 'NZD')->first();
        $company = app(CompanyCreator::class)->create(['name' => 'NZ Books', 'legal_name' => 'NZ Books Ltd', 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => $currency->id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2025-04-01', 'financial_year_end' => '2026-03-31'], $user);
        $branch = $company->branches()->first();
        $branch->update(['name' => 'ABC Branch1']);
        $period = $company->financialYears->first()->periods()->first();
        $accounts = $company->accounts;
        $journal = app(JournalService::class)->create($company, ['branch_id' => $branch->id, 'financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'transaction_date' => '2025-04-15', 'description' => 'Test Office Expense', 'lines' => [['account_id' => $accounts->firstWhere('code', '5000')->id, 'debit' => '100', 'credit' => '0'], ['account_id' => $accounts->firstWhere('code', '1000')->id, 'debit' => '0', 'credit' => '100']]], $user);
        app(JournalService::class)->post($journal, $user);
        $filters = ['company_id' => $company->id, 'branch_id' => $branch->id, 'from' => '2025-04-01', 'to' => '2025-04-30', 'account_id' => $accounts->firstWhere('code', '1000')->id];
        $this->actingAs($user);

        foreach (['reports.ledger', 'reports.trial', 'reports.profit-loss', 'reports.balance-sheet'] as $route) {
            $response = $this->get(route($route, $filters))->assertOk()->assertSee('Accounting Entity:')->assertSee('NZ Books')->assertSee('Branch:')->assertSee('ABC Branch1')->assertSee('Currency:')->assertSee('NZ$ (NZD)')->assertSee('Period:')->assertSee('01 Apr 2025 – 30 Apr 2025')->assertSee('company_id='.$company->id, false)->assertSee('branch_id='.$branch->id, false)->assertSee('from=2025-04-01', false)->assertSee('to=2025-04-30', false)->assertSee('account_id='.$filters['account_id'], false);
            if ($route === 'reports.ledger') {
                $response->assertSee('1000 — Cash and Cash Equivalents')->assertSee('15 Apr 2025')->assertSee('NZ$100.00');
            }
        }

        $this->get(route('reports.profit-loss', $filters))->assertSee('Net Loss')->assertSee('NZ$100.00')->assertDontSee('Net Profit / (Loss)');
        $consolidated = $filters;
        unset($consolidated['branch_id']);
        $this->get(route('reports.balance-sheet', $consolidated))->assertOk()->assertSee('Branch:')->assertSee('All branches (consolidated)');
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('reports.trial', $filters))->assertNotFound();
    }

    public function test_report_year_selector_is_entity_scoped_ordered_and_automatic_resolution_is_date_based(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $country = Country::where('code', 'NZ')->firstOrFail();
        $currency = Currency::where('code', 'NZD')->firstOrFail();
        $company = app(CompanyCreator::class)->create(['name' => 'Selected Books', 'legal_name' => 'Selected Books', 'country_id' => $country->id, 'base_currency_id' => $currency->id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2025-04-01', 'financial_year_end' => '2026-03-31'], $user);
        $older = app(FinancialYearService::class)->create($company, ['name' => 'FY 2024', 'starts_on' => '2024-04-01', 'ends_on' => '2025-03-31'], $user);
        $other = app(CompanyCreator::class)->create(['name' => 'Other Books', 'legal_name' => 'Other Books', 'country_id' => $country->id, 'base_currency_id' => $currency->id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2025-04-01', 'financial_year_end' => '2026-03-31'], $user);
        app(FinancialYearService::class)->create($other, ['name' => 'Other Entity FY Only', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31'], $user);
        $selected = $company->financialYears()->where('name', '2025-2026')->firstOrFail();

        $response = $this->actingAs($user)->get(route('reports', ['country_id' => $country->id, 'company_id' => $company->id]))->assertOk();
        $response->assertSeeInOrder(['Current Financial Year (automatic)', 'All Financial Years (explicit)', '2025-2026 (Open)', 'FY 2024 (Open)']);
        $this->assertSame(1, substr_count($response->getContent(), '2025-2026 (Open)'));
        $response->assertSee('value="'.$selected->id.'"', false)->assertSee('value="'.$older->id.'"', false)->assertDontSee('Other Entity FY Only');

        CarbonImmutable::setTestNow('2025-06-01');
        $account = $company->accounts()->where('code', '1000')->firstOrFail();
        $this->get(route('reports.ledger', ['country_id' => $country->id, 'company_id' => $company->id, 'account_id' => $account->id]))
            ->assertOk()->assertSee('Financial Year:')->assertSee('2025-2026')->assertSee('01 Apr 2025 – 31 Mar 2026');
        $this->get(route('reports.ledger', ['country_id' => $country->id, 'company_id' => $company->id, 'financial_year_id' => $older->id, 'account_id' => $account->id]))
            ->assertOk()->assertSee('FY 2024')->assertSee('01 Apr 2024 – 31 Mar 2025');
        foreach ([null, $selected->id, 'all'] as $yearFilter) {
            foreach (['reports.ledger', 'reports.trial', 'reports.profit-loss', 'reports.balance-sheet'] as $route) {
                $parameters = ['country_id' => $country->id, 'company_id' => $company->id, 'account_id' => $account->id];
                if ($yearFilter !== null) {
                    $parameters['financial_year_id'] = $yearFilter;
                }
                $this->get(route($route, $parameters))->assertOk();
            }
        }
        CarbonImmutable::setTestNow();
    }

    public function test_report_landing_recovers_from_stale_country_and_entity_context(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $nz = Country::where('code', 'NZ')->firstOrFail();
        $au = Country::where('code', 'AU')->firstOrFail();
        $nzCompany = app(CompanyCreator::class)->create(['name' => 'Aotearoa Books', 'legal_name' => 'Aotearoa Books', 'country_id' => $nz->id, 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $user);
        $auCompany = app(CompanyCreator::class)->create(['name' => 'Australian Books', 'legal_name' => 'Australian Books', 'country_id' => $au->id, 'base_currency_id' => Currency::where('code', 'AUD')->value('id'), 'timezone' => 'Australia/Sydney', 'financial_year_start' => '2026-07-01', 'financial_year_end' => '2027-06-30'], $user);
        $auBranch = $auCompany->branches()->firstOrFail();
        $auBranch->update(['name' => 'Sydney Foreign Branch']);
        $auYear = $auCompany->financialYears()->firstOrFail();
        $auYear->update(['name' => 'Australian Foreign Year']);
        $auAccount = $auCompany->accounts()->firstOrFail();

        $response = $this->actingAs($user)->get(route('reports', [
            'country_id' => $nz->id,
            'company_id' => $auCompany->id,
            'branch_id' => $auBranch->id,
            'financial_year_id' => $auYear->id,
            'account_id' => $auAccount->id,
        ]))->assertOk()->assertSee('Aotearoa Books')->assertDontSee('Australian Books')->assertDontSee('Sydney Foreign Branch')->assertDontSee('Australian Foreign Year');

        preg_match('/<select id="report-account".*?<\/select>/s', $response->getContent(), $accountSelect);
        $this->assertNotEmpty($accountSelect);
        $this->assertStringNotContainsString('value="'.$auAccount->id.'"', $accountSelect[0]);
        $response->assertSee('changeReportCountry(this.form)', false)
            ->assertSee("['company_id', 'branch_id', 'financial_year_id', 'account_id']", false)
            ->assertSee("['branch_id', 'financial_year_id', 'account_id']", false);

        $this->assertSame($nzCompany->id, $response->viewData('company')->id);
    }

    public function test_company_switch_clears_stale_dependencies_and_report_execution_remains_strict(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $nz = Country::where('code', 'NZ')->firstOrFail();
        $au = Country::where('code', 'AU')->firstOrFail();
        $currency = Currency::where('code', 'NZD')->firstOrFail();
        $first = app(CompanyCreator::class)->create(['name' => 'First Entity', 'legal_name' => 'First Entity', 'country_id' => $nz->id, 'base_currency_id' => $currency->id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $user);
        $second = app(CompanyCreator::class)->create(['name' => 'Second Entity', 'legal_name' => 'Second Entity', 'country_id' => $nz->id, 'base_currency_id' => $currency->id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $user);
        $australian = app(CompanyCreator::class)->create(['name' => 'Australian Entity', 'legal_name' => 'Australian Entity', 'country_id' => $au->id, 'base_currency_id' => Currency::where('code', 'AUD')->value('id'), 'timezone' => 'Australia/Sydney', 'financial_year_start' => '2026-07-01', 'financial_year_end' => '2027-06-30'], $user);
        $firstBranch = $first->branches()->firstOrFail();
        $firstBranch->update(['name' => 'First Entity Branch']);
        $firstYear = $first->financialYears()->firstOrFail();
        $firstYear->update(['name' => 'First Entity Year']);
        $firstAccount = $first->accounts()->firstOrFail();

        $response = $this->actingAs($user)->get(route('reports', ['country_id' => $nz->id, 'company_id' => $second->id, 'branch_id' => $firstBranch->id, 'financial_year_id' => $firstYear->id, 'account_id' => $firstAccount->id]))
            ->assertOk()->assertSee('Second Entity')->assertDontSee('First Entity Branch')->assertDontSee('First Entity Year');
        $this->assertSame($second->accounts()->orderBy('code')->value('id'), $response->viewData('selectedAccountId'));
        $this->assertNull($response->viewData('selectedBranchId'));
        $this->assertNull($response->viewData('selectedFinancialYearId'));

        $mixedJurisdiction = ['country_id' => $nz->id, 'company_id' => $australian->id, 'account_id' => $australian->accounts()->firstOrFail()->id];
        foreach (['reports.ledger', 'reports.trial', 'reports.profit-loss', 'reports.balance-sheet'] as $route) {
            $this->get(route($route, $mixedJurisdiction))->assertNotFound();
        }

        $this->get(route('reports.ledger', ['country_id' => $nz->id, 'company_id' => $second->id, 'account_id' => $firstAccount->id]))->assertNotFound();
        $this->get(route('reports.trial', ['country_id' => $nz->id, 'company_id' => $second->id, 'branch_id' => $firstBranch->id]))->assertNotFound();
        $this->get(route('reports.profit-loss', ['country_id' => $nz->id, 'company_id' => $second->id, 'financial_year_id' => $firstYear->id]))->assertNotFound();
        $this->get(route('reports.balance-sheet', ['country_id' => $nz->id, 'company_id' => $second->id, 'branch_id' => $firstBranch->id]))->assertNotFound();
    }

    public function test_individual_report_landing_is_branchless(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $country = Country::where('code', 'NZ')->firstOrFail();
        $individual = app(CompanyCreator::class)->create(['entity_type' => 'individual', 'name' => 'Personal Ledger', 'individual_name' => 'Personal Ledger', 'country_id' => $country->id, 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $user);

        $this->actingAs($user)->get(route('reports', ['country_id' => $country->id, 'company_id' => $individual->id, 'branch_id' => 999999]))
            ->assertOk()->assertSee('Not applicable')->assertSee('id="report-branch" name="branch_id" class="form-select" disabled', false);
    }
}

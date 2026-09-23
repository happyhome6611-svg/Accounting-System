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
        CarbonImmutable::setTestNow();
    }
}

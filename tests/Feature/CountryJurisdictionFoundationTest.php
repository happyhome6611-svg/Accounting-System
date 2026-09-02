<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\CountryJurisdictionService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CountryJurisdictionFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Country $nz;

    private Country $india;

    private Company $nzCompany;

    private Company $indiaCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->nz = Country::where('code', 'NZ')->firstOrFail();
        $this->india = Country::where('code', 'IN')->firstOrFail();
        $this->nzCompany = $this->entity($this->nz, 'company', 'NZ Books');
        $this->entity($this->nz, 'individual', 'NZ Individual');
        $this->indiaCompany = $this->entity($this->india, 'company', 'India Books');
    }

    public function test_jurisdiction_landing_separates_countries_and_counts_entities(): void
    {
        $response = $this->actingAs($this->user)->get(route('companies.index'))->assertOk();
        $response->assertSee('Accounting Jurisdictions')->assertSee('New Zealand')->assertSee('2 Accounting Entities')->assertSee('India')->assertSee('1 Accounting Entity');

        $this->get(route('companies.country', 'NZ'))->assertOk()->assertSee('Accounting Entities — New Zealand')->assertSee('NZ Books')->assertSee('NZ Individual')->assertDontSee('India Books');
        $this->get(route('companies.country', 'IN'))->assertOk()->assertSee('Accounting Entities — India')->assertSee('India Books')->assertDontSee('NZ Books');
    }

    public function test_country_scoped_creation_fixes_country_and_rejects_forgery(): void
    {
        $nzd = Currency::where('code', 'NZD')->firstOrFail();
        $payload = ['entity_type' => 'company', 'name' => 'Scoped NZ', 'legal_name' => 'Scoped NZ Ltd', 'country_id' => $this->nz->id, 'base_currency_id' => $nzd->id, 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2027-04-01', 'financial_year_end' => '2028-03-31'];

        $this->actingAs($this->user)->get(route('companies.country.create', 'NZ'))->assertOk()->assertSee('Country / Tax Jurisdiction')->assertSee('New Zealand')->assertSee('type="hidden" name="country_id" value="'.$this->nz->id.'"', false);
        $this->post(route('companies.country.store', 'NZ'), $payload)->assertRedirect();
        $this->assertDatabaseHas('companies', ['name' => 'Scoped NZ', 'country_id' => $this->nz->id]);
        $this->post(route('companies.country.store', 'NZ'), [...$payload, 'name' => 'Forged India', 'country_id' => $this->india->id])->assertStatus(422);
        $this->assertDatabaseMissing('companies', ['name' => 'Forged India']);
    }

    public function test_dashboard_switching_and_module_lists_are_country_scoped(): void
    {
        $this->actingAs($this->user)
            ->get(route('dashboard', ['country_id' => $this->nz->id, 'company_id' => $this->nzCompany->id]))->assertOk()->assertSee('NZ Books')->assertDontSee('India Books');
        $this->get(route('dashboard', ['country_id' => $this->india->id, 'company_id' => $this->nzCompany->id]))->assertNotFound();
        $this->get(route('dashboard', ['country_id' => $this->india->id]))->assertOk()->assertSee('India Books')->assertDontSee('NZ Books');
        $this->get(route('accounting', ['country_id' => $this->nz->id]))->assertOk()->assertSee('NZ Books')->assertDontSee('India Books');
        $this->get(route('sales', ['country_id' => $this->india->id]))->assertOk()->assertSee('India Books')->assertDontSee('NZ Books');
        $this->get(route('reports', ['country_id' => $this->nz->id]))->assertOk()->assertSee('NZ Books')->assertDontSee('India Books');
    }

    public function test_report_country_entity_mismatch_and_cross_entity_filters_are_rejected(): void
    {
        $nzAccount = $this->nzCompany->accounts()->firstOrFail();
        $indiaYear = $this->indiaCompany->financialYears()->firstOrFail();
        $this->actingAs($this->user)
            ->get(route('reports.ledger', ['country_id' => $this->india->id, 'company_id' => $this->nzCompany->id, 'account_id' => $nzAccount->id]))->assertNotFound();
        $this->get(route('reports.trial', ['country_id' => $this->nz->id, 'company_id' => $this->nzCompany->id, 'financial_year_id' => $indiaYear->id]))->assertNotFound();
    }

    public function test_country_provider_resolves_entity_type_and_effective_date(): void
    {
        $profile = app(CountryJurisdictionService::class)->taxProfile($this->nz, 'sole_trader', '2027-04-01');
        $this->assertSame(['country' => 'NZ', 'entity_type' => 'sole_trader', 'effective_date' => '2027-04-01', 'calculation_engine' => null], $profile);
    }

    private function entity(Country $country, string $type, string $name): Company
    {
        $currency = Currency::where('code', $country->code === 'IN' ? 'INR' : 'NZD')->firstOrFail();

        return app(CompanyCreator::class)->create(['entity_type' => $type, 'name' => $name, 'legal_name' => $type === 'company' ? $name.' Ltd' : null, 'individual_name' => $type === 'company' ? null : $name, 'trading_name' => $type === 'sole_trader' ? $name : null, 'country_id' => $country->id, 'base_currency_id' => $currency->id, 'timezone' => $country->code === 'IN' ? 'Asia/Kolkata' : 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
    }
}

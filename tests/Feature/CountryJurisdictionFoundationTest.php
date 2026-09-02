<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\CountryJurisdictionService;
use App\Services\JournalService;
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

    public function test_country_creation_defaults_cover_supported_jurisdictions(): void
    {
        $expected = ['NZ' => ['Pacific/Auckland', 'NZD'], 'IN' => ['Asia/Kolkata', 'INR'], 'AU' => ['Australia/Sydney', 'AUD'], 'GB' => ['Europe/London', 'GBP'], 'SG' => ['Asia/Singapore', 'SGD']];
        $this->actingAs($this->user);
        foreach ($expected as $code => [$timezone, $currency]) {
            $this->get(route('companies.country.create', $code))->assertOk()->assertSee('value="'.$timezone.'"', false)->assertSee('data-code="'.$currency.'"', false)->assertSee('selected', false);
        }
    }

    public function test_unused_entity_can_change_country_and_generated_tax_year_follows(): void
    {
        $inr = Currency::where('code', 'INR')->firstOrFail();
        $payload = ['name' => 'Moved Books', 'legal_name' => 'Moved Books Ltd', 'country_id' => $this->india->id, 'base_currency_id' => $inr->id, 'timezone' => 'Asia/Kolkata', 'address' => null, 'email' => null, 'phone' => null];

        $this->actingAs($this->user)->get(route('companies.edit', $this->nzCompany))->assertOk()->assertSee('Changing jurisdiction will update the suggested timezone and base currency')->assertDontSee('disabled', false);
        $this->put(route('companies.update', $this->nzCompany), $payload)->assertRedirect();
        $this->assertDatabaseHas('companies', ['id' => $this->nzCompany->id, 'country_id' => $this->india->id, 'base_currency_id' => $inr->id, 'timezone' => 'Asia/Kolkata']);
        $this->assertSame([$this->india->id], $this->nzCompany->taxYears()->pluck('country_id')->unique()->values()->all());
    }

    public function test_used_entity_country_is_locked_in_ui_and_forged_update_is_rejected(): void
    {
        $period = $this->nzCompany->financialYears()->firstOrFail()->periods()->firstOrFail();
        $accounts = $this->nzCompany->accounts;
        $journal = app(JournalService::class)->create($this->nzCompany, ['financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'transaction_date' => $period->starts_on->toDateString(), 'description' => 'Meaningful activity', 'lines' => [['account_id' => $accounts[0]->id, 'debit' => 1, 'credit' => 0], ['account_id' => $accounts[4]->id, 'debit' => 0, 'credit' => 1]]], $this->user);
        $historicalAmounts = $journal->lines()->orderBy('id')->get(['debit', 'credit'])->toArray();
        $inr = Currency::where('code', 'INR')->firstOrFail();
        $payload = ['name' => $this->nzCompany->name, 'legal_name' => $this->nzCompany->legal_name, 'country_id' => $this->india->id, 'base_currency_id' => $inr->id, 'timezone' => 'Asia/Kolkata', 'address' => null, 'email' => null, 'phone' => null];

        $edit = $this->actingAs($this->user)->get(route('companies.edit', $this->nzCompany))->assertOk()->assertSee('Country / Tax Jurisdiction and Base Currency cannot be changed after accounting or business activity exists.');
        $this->assertSame(2, substr_count($edit->getContent(), 'disabled'));
        $this->put(route('companies.update', $this->nzCompany), $payload)->assertSessionHasErrors('country_id');
        $this->assertSame($this->nz->id, $this->nzCompany->fresh()->country_id);
        $this->put(route('companies.update', $this->nzCompany), [...$payload, 'country_id' => $this->nz->id])->assertSessionHasErrors('base_currency_id');
        $this->assertSame('NZD', $this->nzCompany->fresh()->baseCurrency->code);
        $this->assertSame($historicalAmounts, $journal->lines()->orderBy('id')->get(['debit', 'credit'])->toArray());

        $safeProfile = [...$payload, 'name' => 'Renamed Used Entity', 'legal_name' => 'Renamed Used Entity Ltd', 'country_id' => $this->nz->id, 'base_currency_id' => $this->nzCompany->base_currency_id, 'timezone' => 'Pacific/Auckland'];
        $this->put(route('companies.update', $this->nzCompany), $safeProfile)->assertRedirect();
        $this->assertSame('Renamed Used Entity', $this->nzCompany->fresh()->name);
        $this->assertSame($historicalAmounts, $journal->lines()->orderBy('id')->get(['debit', 'credit'])->toArray());
    }

    public function test_unused_entity_can_change_base_currency_without_changing_country(): void
    {
        $usd = Currency::where('code', 'USD')->firstOrFail();
        $payload = ['name' => $this->nzCompany->name, 'legal_name' => $this->nzCompany->legal_name, 'country_id' => $this->nz->id, 'base_currency_id' => $usd->id, 'timezone' => 'Pacific/Auckland', 'address' => null, 'email' => null, 'phone' => null];

        $this->actingAs($this->user)->put(route('companies.update', $this->nzCompany), $payload)->assertRedirect();
        $this->assertSame('USD', $this->nzCompany->fresh()->baseCurrency->code);
    }

    public function test_sole_trader_keeps_branches_and_individual_remains_branchless(): void
    {
        $trader = $this->entity($this->nz, 'sole_trader', 'NZ Trader');
        $individual = $this->user->companies()->where('entity_type', 'individual')->firstOrFail();
        $this->assertTrue($trader->supportsBranches());
        $this->assertCount(1, $trader->branches);
        $this->assertFalse($individual->supportsBranches());
        $this->assertCount(0, $individual->branches);
        $this->actingAs($this->user)->get(route('dashboard', ['country_id' => $this->nz->id, 'company_id' => $trader->id]))->assertOk()->assertSee('All branches (consolidated)');
        $this->get(route('reports', ['country_id' => $this->nz->id]))->assertOk()->assertSee('NZ Trader');
    }

    private function entity(Country $country, string $type, string $name): Company
    {
        $currency = Currency::where('code', $country->code === 'IN' ? 'INR' : 'NZD')->firstOrFail();

        return app(CompanyCreator::class)->create(['entity_type' => $type, 'name' => $name, 'legal_name' => $type === 'company' ? $name.' Ltd' : null, 'individual_name' => $type === 'company' ? null : $name, 'trading_name' => $type === 'sole_trader' ? $name : null, 'country_id' => $country->id, 'base_currency_id' => $currency->id, 'timezone' => $country->code === 'IN' ? 'Asia/Kolkata' : 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
    }
}

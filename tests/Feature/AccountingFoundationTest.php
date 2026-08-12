<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Tax\CountryModuleRegistry;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_authentication_and_protected_dashboard(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
        $user = User::factory()->create(['password' => 'password']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/dashboard');
    }

    public function test_company_creation_builds_periods_and_chart(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/companies', ['name' => 'Kiwi Books', 'legal_name' => 'Kiwi Books Limited', 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'])->assertRedirect();
        $company = Company::first();
        $this->assertTrue($company->users->contains($user));
        $this->assertCount(12, $company->financialYears->first()->periods);
        $this->assertSame(6, $company->accounts()->count());
    }

    public function test_company_routes_enforce_user_isolation(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $company = Company::create(['name' => 'Private', 'legal_name' => 'Private Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'accounting_configuration' => [], 'tax_configuration' => [], 'created_by' => $owner->id, 'updated_by' => $owner->id]);
        $company->users()->attach($owner, ['role' => 'owner']);
        $this->actingAs($other)->get(route('companies.show', $company))->assertNotFound();
    }

    public function test_country_provider_resolution(): void
    {
        $registry = app(CountryModuleRegistry::class);
        foreach (['IN', 'NZ', 'AU', 'GB', 'SG'] as $code) {
            $this->assertSame($code, $registry->resolve($code)->countryCode());
        }
    }

    public function test_seeders_are_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('countries', 5);
        $this->assertDatabaseCount('currencies',7);
    }
}

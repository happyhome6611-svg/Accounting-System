<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\CompanyCreator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BladeRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_pages_render_layout_without_literal_blade_directives(): void
    {
        config(['app.name' => 'Arua Accounting System']);

        $this->get('/')->assertRedirect('/dashboard');

        foreach (['/login', '/register'] as $uri) {
            $response = $this->get($uri)->assertOk()->assertSee('Arua Accounting System');
            $this->assertCompiledBlade($response->getContent());
            $this->assertStringContainsString('name="_token"', $response->getContent());
        }
    }

    public function test_authenticated_application_pages_render_layout_without_literal_blade_directives(): void
    {
        config(['app.name' => 'Arua Accounting System']);
        $this->seed(DatabaseSeeder::class);
        $user = User::factory()->create();
        $company = app(CompanyCreator::class)->create([
            'name' => 'Arua Test Company',
            'legal_name' => 'Arua Test Company Limited',
            'country_id' => Country::first()->id,
            'base_currency_id' => Currency::first()->id,
            'timezone' => 'UTC',
            'financial_year_start' => '2026-01-01',
            'financial_year_end' => '2026-12-31',
        ], $user);

        $this->actingAs($user);

        $uris = [
            '/dashboard',
            '/companies',
            '/companies/create',
            "/companies/{$company->id}",
            '/accounting',
            "/companies/{$company->id}/journals/create",
            '/sales',
            "/companies/{$company->id}/customers",
            "/companies/{$company->id}/items",
            "/companies/{$company->id}/sales-invoices",
            '/reports',
        ];

        foreach ($uris as $uri) {
            $content = $this->get($uri)->assertOk()->assertSee('Arua Accounting System')->getContent();
            $this->assertCompiledBlade($content);
        }
    }

    private function assertCompiledBlade(string $content): void
    {
        foreach (['@yield', '@section', '@extends', '@csrf', '@auth', '@foreach', '@endif'] as $directive) {
            $this->assertStringNotContainsString($directive, $content, "Literal {$directive} found in rendered HTML.");
        }

        $this->assertStringNotContainsString('Accounting Pro v0.1', $content);
    }
}

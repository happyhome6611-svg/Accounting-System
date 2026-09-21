<?php

namespace Tests\Feature;

use App\ImportExport\ImportService;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\TaxCalculationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class DemoTradingSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_command_refuses_missing_ambiguous_and_missing_accounts_safely(): void
    {
        $this->artisan('arua:setup-demo-trading')->assertExitCode(1);
        $first = $this->company();
        $this->artisan('arua:setup-demo-trading', ['--entity' => $first->id])->assertExitCode(1);
        $this->assertSame(1, $first->branches()->count());
        $this->assertSame(0, $first->taxCodes()->count());
        $second = $this->company();
        $this->artisan('arua:setup-demo-trading')->assertExitCode(1);
        $this->artisan('arua:setup-demo-trading', ['--entity' => $second->id])->assertExitCode(1);
    }

    public function test_explicit_setup_is_entity_scoped_idempotent_and_validates_exact_products(): void
    {
        $company = $this->company();
        $other = $this->company();
        $this->accounts($company);
        $this->accounts($other);
        $this->artisan('arua:setup-demo-trading')->assertExitCode(1);
        $this->artisan('arua:setup-demo-trading', ['--entity' => $company->id])->assertExitCode(0);
        $this->artisan('arua:setup-demo-trading', ['--entity' => $company->id])->assertExitCode(0);

        $this->assertSame(1, $company->branches()->where('code', 'AKL')->count());
        $this->assertSame(1, $company->branches()->where('code', 'WLG')->count());
        $this->assertSame(1, $company->branches()->where('code', 'HO')->count());
        $this->assertSame(0, $other->taxCodes()->count());
        $this->assertSame(0, $other->taxRegistrations()->count());
        $registration = $company->taxRegistrations()->where('registration_number', 'DEMO-NZ-001')->firstOrFail();
        $this->assertSame('accrual', $registration->accounting_basis);
        $this->assertSame('two_monthly', $registration->filing_frequency);
        $this->assertSame('2025-04-01', $registration->effective_from->toDateString());
        $this->assertSame('2026-03-31', $registration->effective_to->toDateString());
        $this->assertSame(6, $registration->periods()->count());
        $this->assertSame(1, $company->taxRegistrations()->where('registration_number', 'DEMO-NZ-001')->count());
        $this->assertSame(1, $company->taxCodes()->where('code', 'STANDARD')->count());
        $this->assertSame(1, $company->taxCodes()->where('code', 'ZERO')->count());
        $standard = $company->taxCodes()->where('code', 'STANDARD')->firstOrFail();
        $zero = $company->taxCodes()->where('code', 'ZERO')->firstOrFail();
        $this->assertSame('taxable', $standard->treatment);
        $this->assertSame('zero_rated', $zero->treatment);
        $this->assertSame(1, $standard->rates()->count());
        $this->assertSame(0, $zero->rates()->count());
        $this->assertSame('2025-04-01', $standard->rates()->firstOrFail()->effective_from->toDateString());
        $this->assertSame('2026-03-31', $standard->rates()->firstOrFail()->effective_to->toDateString());
        $this->assertSame($company->accounts()->where('code', '2100')->value('id'), $company->taxSetting->output_tax_account_id);
        $this->assertSame($company->accounts()->where('code', '1200')->value('id'), $company->taxSetting->input_tax_account_id);
        $tax = app(TaxCalculationService::class);
        $this->assertSame('15.000000', $tax->calculate($company, $standard->id, '2025-04-01', '100')['rate']);
        $this->assertSame('0.000000', $tax->calculate($company, $zero->id, '2026-03-31', '100')['rate']);

        $path = base_path('tests/SampleBusinessData/AruaDemoTrading/06_products_services.csv');
        $file = new UploadedFile($path, '06_products_services.csv', 'text/csv', null, true);
        $batch = app(ImportService::class)->upload($company, 'products', $file, null, $company->users()->firstOrFail());
        $this->assertSame(40, $batch->total_rows);
        $this->assertSame(40, $batch->valid_rows);
        $this->assertSame(0, $batch->invalid_rows);
        $this->assertSame(0, $batch->duplicate_rows);
    }

    private function company(): Company
    {
        $user = User::factory()->create();

        return app(CompanyCreator::class)->create([
            'entity_type' => 'company', 'name' => 'Arua Demo Trading Ltd', 'legal_name' => 'Arua Demo Trading Ltd',
            'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'),
            'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2025-04-01', 'financial_year_end' => '2026-03-31',
        ], $user);
    }

    private function accounts(Company $company): void
    {
        foreach ([['1200', 'Input Tax Recoverable', 'asset'], ['2100', 'Output Tax Payable', 'liability']] as [$code, $name, $type]) {
            $company->accounts()->create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $type === 'asset' ? 'debit' : 'credit', 'is_active' => true, 'created_by' => $company->created_by, 'updated_by' => $company->created_by]);
        }
    }
}

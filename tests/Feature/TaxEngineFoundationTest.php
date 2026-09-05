<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\TransactionTaxLine;
use App\Models\User;
use App\Services\CompanyCreator;
use App\Services\SalesService;
use App\Services\TaxCalculationService;
use App\Services\TaxConfigurationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TaxEngineFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private TaxConfigurationService $configuration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['entity_type' => 'company', 'name' => 'Generic Tax Books', 'legal_name' => 'Generic Tax Books Ltd', 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2027-01-01', 'financial_year_end' => '2027-12-31'], $this->user);
        $this->taxAccount('1150', 'Input Tax Recoverable', 'asset', 'debit');
        $this->taxAccount('2100', 'Output Tax Payable', 'liability', 'credit');
        $this->configuration = app(TaxConfigurationService::class);
    }

    public function test_effective_registration_codes_rates_and_decimal_calculation(): void
    {
        [$standard, $zero, $exempt, $out] = $this->taxConfiguration();
        $calculator = app(TaxCalculationService::class);

        $this->assertSame('10.0000', $calculator->calculate($this->company, $standard->id, '2027-06-30', '100', false)['tax']);
        $this->assertSame('12.0000', $calculator->calculate($this->company, $standard->id, '2027-07-01', '100', false)['tax']);
        $inclusive = $calculator->calculate($this->company, $standard->id, '2027-06-30', '110', true);
        $this->assertSame('100.0000', $inclusive['net']);
        $this->assertSame('10.0000', $inclusive['tax']);
        $this->assertSame('110.0000', $inclusive['gross']);
        foreach ([[$zero, 'zero_rated'], [$exempt, 'exempt'], [$out, 'out_of_scope']] as [$code, $treatment]) {
            $result = $calculator->calculate($this->company, $code->id, '2027-06-30', '100', false);
            $this->assertSame('0.0000', $result['tax']);
            $this->assertSame($treatment, $result['treatment']);
        }
        $this->assertThrows(fn () => $this->configuration->rate($this->company, $standard, ['rate' => '11', 'effective_from' => '2027-06-01', 'effective_to' => '2027-08-01', 'is_active' => true], $this->user), ValidationException::class);
    }

    public function test_mixed_sales_invoice_posts_tax_and_preserves_immutable_snapshots(): void
    {
        [$standard, $zero, $exempt] = $this->taxConfiguration();
        $this->configuration->settings($this->company, ['output_tax_account_id' => $this->account('2100'), 'input_tax_account_id' => $this->account('1150'), 'rounding_method' => 'per_line'], $this->user);
        $sales = app(SalesService::class);
        $customer = $sales->createCustomer($this->company, ['code' => 'TAX-C', 'name' => 'Tax Customer', 'type' => 'business', 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 0, 'credit_limit' => 1000, 'receivable_account_id' => $this->account('1100')], $this->user);
        $invoice = $sales->createInvoice($this->company, ['customer_id' => $customer->id, 'branch_id' => $this->company->branches()->value('id'), 'invoice_date' => '2027-06-30', 'due_date' => '2027-07-30', 'lines' => [
            ['revenue_account_id' => $this->account('4000'), 'description' => 'Taxable', 'quantity' => '1', 'unit_price' => '100', 'discount' => '0', 'tax_code_id' => $standard->id],
            ['revenue_account_id' => $this->account('4000'), 'description' => 'Zero rated', 'quantity' => '1', 'unit_price' => '50', 'discount' => '0', 'tax_code_id' => $zero->id],
            ['revenue_account_id' => $this->account('4000'), 'description' => 'Exempt', 'quantity' => '1', 'unit_price' => '25', 'discount' => '0', 'tax_code_id' => $exempt->id],
        ]], $this->user);
        $this->assertSame('175.0000', $invoice->subtotal);
        $this->assertSame('10.0000', $invoice->tax_amount);
        $this->assertSame('185.0000', $invoice->total);
        $sales->postInvoice($invoice, $this->user);
        $this->assertSame(3, TransactionTaxLine::where('company_id', $this->company->id)->count());
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $invoice->fresh()->journal_entry_id, 'account_id' => $this->account('1100'), 'debit' => '185.0000']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $invoice->fresh()->journal_entry_id, 'account_id' => $this->account('2100'), 'credit' => '10.0000']);
        $snapshot = TransactionTaxLine::where('tax_code_id', $standard->id)->firstOrFail();
        $this->assertSame('10.000000', $snapshot->rate_snapshot);
        $this->assertThrows(fn () => $snapshot->update(['tax_amount' => '99']));
    }

    public function test_tax_periods_are_independent_from_monthly_accounting_periods_and_routes_are_scoped(): void
    {
        [$standard] = $this->taxConfiguration(true);
        $registration = $standard->registration;
        $this->configuration->generatePeriods($this->company, $registration, $this->user);
        $this->assertSame(4, $registration->periods()->count());
        $this->assertSame(12, $this->company->financialYears()->first()->periods()->count());
        $this->actingAs($this->user)->get(route('tax'))->assertOk()->assertSee('New Zealand');
        $this->get(route('tax.country', 'NZ'))->assertOk()->assertSee($this->company->name);
        $this->get(route('tax.workspace', ['NZ', $this->company]))->assertOk()->assertSee('Tax Engine Foundation')->assertSee('Tax Transaction Register')->assertDontSee('@yield');
        foreach (['tax.register', 'tax.output', 'tax.input', 'tax.summary', 'tax.adjustments'] as $routeName) {
            $this->get(route($routeName, ['NZ', $this->company]))->assertOk()->assertDontSee('@yield');
        }
        $this->get(route('tax.registrations.edit', ['NZ', $this->company, $registration]))->assertOk()->assertSee('Edit Tax Registration')->assertDontSee('@section');
        $this->get(route('tax.codes.edit', ['NZ', $this->company, $standard]))->assertOk()->assertSee('Edit Tax Code')->assertDontSee('@section');
        $this->get(route('tax.rates.edit', ['NZ', $this->company, $standard, $standard->rates()->first()]))->assertOk()->assertSee('Edit Effective Tax Rate')->assertDontSee('@section');
        $this->get(route('tax.workspace', ['IN', $this->company]))->assertNotFound();
    }

    public function test_control_accounts_and_foreign_configuration_are_rejected(): void
    {
        [$standard] = $this->taxConfiguration();
        $other = app(CompanyCreator::class)->create(['entity_type' => 'company', 'name' => 'Foreign Entity', 'legal_name' => 'Foreign Entity Ltd', 'country_id' => Country::where('code', 'IN')->value('id'), 'base_currency_id' => Currency::where('code', 'INR')->value('id'), 'timezone' => 'Asia/Kolkata', 'financial_year_start' => '2027-04-01', 'financial_year_end' => '2028-03-31'], $this->user);
        $this->assertThrows(fn () => $this->configuration->settings($this->company, ['output_tax_account_id' => $this->account('1000'), 'rounding_method' => 'per_line'], $this->user));
        $this->assertThrows(fn () => $this->configuration->settings($this->company, ['output_tax_account_id' => $other->accounts()->where('type', 'liability')->value('id'), 'rounding_method' => 'per_line'], $this->user));
        $this->assertThrows(fn () => $this->configuration->rate($other, $standard, ['rate' => '5', 'effective_from' => '2028-01-01', 'is_active' => true], $this->user));
    }

    private function taxConfiguration(bool $bounded = false): array
    {
        $registration = $this->configuration->registration($this->company, ['tax_type' => 'GST', 'name' => 'Generic Indirect Tax', 'registration_number' => 'REG-001', 'registration_name' => 'Generic Tax Books', 'effective_from' => '2027-01-01', 'effective_to' => $bounded ? '2027-12-31' : null, 'filing_frequency' => 'quarterly', 'accounting_basis' => 'accrual', 'status' => 'active'], $this->user);
        $codes = [];
        foreach (['STANDARD' => 'taxable', 'ZERO' => 'zero_rated', 'EXEMPT' => 'exempt', 'OUT' => 'out_of_scope'] as $code => $treatment) {
            $codes[] = $this->configuration->code($this->company, ['tax_registration_id' => $registration->id, 'tax_type' => 'GST', 'code' => $code, 'name' => $code, 'treatment' => $treatment, 'recoverability_type' => 'full', 'effective_from' => '2027-01-01', 'is_active' => true], $this->user);
        }
        $this->configuration->rate($this->company, $codes[0], ['rate' => '10', 'effective_from' => '2027-01-01', 'effective_to' => '2027-06-30', 'is_active' => true], $this->user);
        $this->configuration->rate($this->company, $codes[0], ['rate' => '12', 'effective_from' => '2027-07-01', 'is_active' => true], $this->user);
        $this->configuration->generatePeriods($this->company, $registration, $this->user);

        return $codes;
    }

    private function account(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    private function taxAccount(string $code, string $name, string $type, string $normalBalance): void
    {
        $this->company->accounts()->create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normalBalance, 'is_active' => true, 'is_system' => false, 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
    }
}

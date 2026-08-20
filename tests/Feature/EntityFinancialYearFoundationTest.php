<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\CarryForwardService;
use App\Services\CompanyCreator;
use App\Services\FinancialYearResolver;
use App\Services\FinancialYearService;
use App\Services\JournalService;
use App\Services\PriorPeriodAdjustmentService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EntityFinancialYearFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = $this->entity('company', 'Foundation Ltd');
    }

    public function test_company_individual_and_sole_trader_entity_profiles_preserve_branch_rules(): void
    {
        $individual = $this->entity('individual', 'Anika Rao');
        $trader = $this->entity('sole_trader', 'Anika Consulting');

        $this->assertSame('company', $this->company->entity_type);
        $this->assertTrue($this->company->supportsBranches());
        $this->assertSame('individual', $individual->entity_type);
        $this->assertFalse($individual->supportsBranches());
        $this->assertCount(0, $individual->branches);
        $this->assertSame('Anika Rao', $individual->entity_label);
        $this->assertSame('sole_trader', $trader->entity_type);
        $this->assertSame('Anika Consulting', $trader->entity_label);
        $this->assertCount(1, $trader->branches);
        $this->assertCount(1, $individual->taxYears);
    }

    public function test_financial_year_creation_rejects_overlap_and_builds_periods(): void
    {
        $service = app(FinancialYearService::class);
        $year = $service->create($this->company, ['name' => '2027-2028', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'], $this->user);
        $this->assertCount(12, $year->periods);
        $this->assertThrows(fn () => $service->create($this->company, ['name' => 'Overlap', 'starts_on' => '2028-01-01', 'ends_on' => '2028-12-31'], $this->user), ValidationException::class);
    }

    public function test_date_resolution_is_entity_scoped_and_rejects_outside_or_closed_year(): void
    {
        $year = $this->company->financialYears()->first();
        $period = app(FinancialYearResolver::class)->resolve($this->company, '2026-04-15');
        $this->assertSame($year->id, $period->financial_year_id);
        $this->assertThrows(fn () => app(FinancialYearResolver::class)->resolve($this->company, '2035-01-01'), ValidationException::class);
        app(FinancialYearService::class)->close($year, 'Approved annual close', $this->user);
        $this->assertThrows(fn () => app(FinancialYearResolver::class)->resolve($this->company, '2026-04-15'), ValidationException::class);
    }

    public function test_close_and_authorised_reopen_are_audited_while_filed_year_is_protected(): void
    {
        $year = $this->company->financialYears()->first();
        $service = app(FinancialYearService::class);
        $service->close($year, 'Approved annual close', $this->user);
        $service->reopen($year->fresh(), 'Correction authorised by owner', $this->user);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'financial_year.closed']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'financial_year.reopened']);
        $service->close($year->fresh(), 'Final close before filing', $this->user);
        $service->markFiled($year->fresh(), 'Generic filing reference', $this->user);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'financial_year.filed']);
        $this->assertThrows(fn () => $service->reopen($year->fresh(), 'Attempt to reopen filed year', $this->user), ValidationException::class);
    }

    public function test_journal_resolves_financial_year_and_rejects_cross_entity_period(): void
    {
        $other = $this->entity('company', 'Other Ltd');
        $accounts = $this->company->accounts;
        $journal = app(JournalService::class)->create($this->company, ['branch_id' => $this->company->branches()->first()->id, 'transaction_date' => '2026-04-15', 'description' => 'Automatically resolved', 'lines' => [['account_id' => $accounts->first()->id, 'debit' => '10', 'credit' => '0'], ['account_id' => $accounts->skip(1)->first()->id, 'debit' => '0', 'credit' => '10']]], $this->user);
        $this->assertNotNull($journal->financial_year_id);
        $this->assertNotNull($journal->accounting_period_id);
        $this->assertThrows(fn () => app(FinancialYearResolver::class)->resolve($this->company, '2026-04-15', null, $other->financialYears()->first()->periods()->first()->id), ValidationException::class);
    }

    public function test_prior_period_adjustment_and_carry_forward_are_explicit_and_entity_scoped(): void
    {
        $origin = $this->company->financialYears()->first();
        app(FinancialYearService::class)->close($origin, 'Approved annual close', $this->user);
        $destination = app(FinancialYearService::class)->create($this->company, ['name' => '2027-2028', 'starts_on' => '2027-04-01', 'ends_on' => '2028-03-31'], $this->user);
        $adjustment = app(PriorPeriodAdjustmentService::class)->create($this->company, ['origin_financial_year_id' => $origin->id, 'adjustment_financial_year_id' => $destination->id, 'adjustment_type' => 'accounting_correction', 'reason' => 'Missed expense identified after close'], $this->user);
        $this->assertSame($origin->id, $adjustment->origin_financial_year_id);
        $carry = app(CarryForwardService::class)->create($this->company, ['source_financial_year_id' => $origin->id, 'destination_financial_year_id' => $destination->id, 'type' => 'generic_balance', 'original_amount' => '125.5000'], $this->user);
        app(CarryForwardService::class)->use($carry, '25.2500', $this->user);
        $this->assertSame('100.2500', $carry->fresh()->amount_remaining);
        $other = $this->entity('company', 'Other Ltd');
        $this->assertThrows(fn () => app(CarryForwardService::class)->create($this->company, ['source_financial_year_id' => $other->financialYears()->first()->id, 'destination_financial_year_id' => $destination->id, 'type' => 'forged', 'original_amount' => '1'], $this->user), ValidationException::class);
    }

    public function test_financial_year_ui_and_report_context_are_isolated(): void
    {
        $year = $this->company->financialYears()->first();
        $this->actingAs($this->user)
            ->get(route('companies.financial-years.index', $this->company))
            ->assertOk()->assertSee('Financial Years')->assertSee($year->name);
        $this->get(route('reports.trial', ['company_id' => $this->company->id, 'financial_year_id' => $year->id]))
            ->assertOk()->assertSee('Financial Year:')->assertSee($year->name)->assertSee('financial_year_id='.$year->id, false);
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('companies.financial-years.index', $this->company))->assertNotFound();
    }

    private function entity(string $type, string $name): Company
    {
        return app(CompanyCreator::class)->create(['entity_type' => $type, 'name' => $name, 'legal_name' => $type === 'company' ? $name : null, 'individual_name' => $type === 'company' ? null : 'Anika Rao', 'trading_name' => $type === 'sole_trader' ? $name : null, 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2026-04-01', 'financial_year_end' => '2027-03-31'], $this->user);
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\AccountingLockService;
use App\Services\AccountingPeriodService;
use App\Services\AccountingReportService;
use App\Services\AuditLogger;
use App\Services\ClosingReadinessService;
use App\Services\CompanyCreator;
use App\Services\FinancialYearService;
use App\Services\JournalService;
use App\Services\PeriodEndService;
use App\Services\YearEndClosingService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class PeriodEndAccountantControlsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['entity_type' => 'company', 'name' => 'Period End Books', 'legal_name' => 'Period End Books Ltd', 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2025-04-01', 'financial_year_end' => '2026-03-31'], $this->user);
    }

    public function test_period_end_navigation_is_jurisdiction_and_entity_scoped(): void
    {
        $india = app(CompanyCreator::class)->create(['entity_type' => 'individual', 'name' => 'India Personal', 'individual_name' => 'India Personal', 'country_id' => Country::where('code', 'IN')->value('id'), 'base_currency_id' => Currency::where('code', 'INR')->value('id'), 'timezone' => 'Asia/Kolkata', 'financial_year_start' => '2025-04-01', 'financial_year_end' => '2026-03-31'], $this->user);
        $year = $this->company->financialYears()->firstOrFail();
        $period = $year->periods()->firstOrFail();
        $this->actingAs($this->user)->get(route('period-end'))->assertOk()->assertSee('New Zealand')->assertSee('India');
        $this->get(route('period-end.country', 'NZ'))->assertOk()->assertSee($this->company->name)->assertDontSee($india->name);
        $this->get(route('period-end.country', 'IN'))->assertOk()->assertSee($india->name)->assertDontSee($this->company->name);
        $this->get(route('period-end.entity', ['IN', $this->company]))->assertNotFound();
        $this->get(route('period-end.workspace', ['NZ', $this->company, $year, $period]))->assertOk()->assertSee('Closing Checklist')->assertSee('Accountant Adjustment')->assertDontSee('@yield');
        $this->get(route('period-end.workspace', ['IN', $india, $year, $period]))->assertNotFound();
    }

    public function test_adjustment_lock_override_and_scheduled_reversal_are_controlled(): void
    {
        $year = $this->company->financialYears()->firstOrFail();
        $period = $year->periods()->whereDate('ends_on', '2026-03-31')->firstOrFail();
        $next = app(FinancialYearService::class)->create($this->company, ['name' => 'FY 2027', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31'], $this->user);
        app(AccountingLockService::class)->update($this->company, ['bookkeeping_lock_date' => '2026-03-31', 'adviser_lock_date' => null], $this->user, app(AuditLogger::class));
        $journals = app(JournalService::class);
        $lines = [['account_id' => $this->account('5000'), 'description' => 'Accrual', 'debit' => '1000', 'credit' => '0'], ['account_id' => $this->account('2000'), 'description' => 'Accrual', 'debit' => '0', 'credit' => '1000']];
        $this->assertThrows(fn () => $journals->create($this->company, ['branch_id' => $this->branch(), 'transaction_date' => '2026-03-31', 'description' => 'Ordinary locked entry', 'lines' => $lines], $this->user), ValidationException::class);
        $service = app(PeriodEndService::class);
        $adjustment = $service->adjustment($this->company, ['branch_id' => $this->branch(), 'financial_year_id' => $year->id, 'accounting_period_id' => $period->id, 'transaction_date' => '2026-03-31', 'description' => 'Accrued expense', 'reason' => 'Recognise March accrued expense', 'lines' => $lines], $this->user);
        $journals->post($adjustment, $this->user);
        $this->assertSame('adjusting', $adjustment->fresh()->journal_type);
        $this->assertThrows(fn () => $adjustment->fresh()->update(['reason' => 'forged']), LogicException::class);
        $schedule = $service->scheduleReversal($this->company, $adjustment->fresh(), '2026-04-01', $this->user);
        $reversal = $service->postReversal($this->company, $schedule, $this->user);
        $this->assertSame('reversing', $reversal->journal_type);
        $this->assertSame($next->id, $reversal->financial_year_id);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $reversal->id, 'account_id' => $this->account('2000'), 'debit' => '1000.0000']);
        $this->assertSame($reversal->id, $service->postReversal($this->company, $schedule->fresh(), $this->user)->id);
        $this->assertDatabaseCount('adjustment_reversal_schedules', 1);
    }

    public function test_checklist_readiness_period_close_and_reverse_order_reopen(): void
    {
        $year = $this->company->financialYears()->firstOrFail();
        $periods = $year->periods()->take(2)->get();
        $readiness = app(ClosingReadinessService::class);
        $service = app(PeriodEndService::class);
        foreach ($periods as $period) {
            $readiness->initialize($period, $this->user);
            $this->assertFalse($readiness->summary($period)['ready']);
            foreach ($period->checklistItems()->where('is_system_check', false)->get() as $item) {
                $service->checklist($this->company, $period, $item->id, ['status' => 'completed', 'notes' => 'Reviewed by accountant'], $this->user);
            }
            $this->assertTrue($readiness->summary($period)['ready']);
            $service->close($this->company, $period, 'Period review completed', $this->user);
            $this->assertNotNull($period->fresh()->closure_snapshot);
        }
        $this->assertThrows(fn () => $service->reopen($this->company, $periods->first(), 'Need correction in January', $this->user), ValidationException::class);
        $service->reopen($this->company, $periods->last(), 'Need correction in February', $this->user);
        $service->reopen($this->company, $periods->first(), 'Need correction in January', $this->user);
        $this->assertSame('open', $periods->first()->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'accounting_period.reopened']);
    }

    public function test_profit_year_end_close_is_balanced_immutable_and_idempotent(): void
    {
        $year = $this->company->financialYears()->firstOrFail();
        $journals = app(JournalService::class);
        $revenue = $journals->create($this->company, ['branch_id' => $this->branch(), 'transaction_date' => '2025-04-10', 'description' => 'Revenue fixture', 'lines' => [['account_id' => $this->account('1000'), 'description' => 'Revenue', 'debit' => '100000', 'credit' => '0'], ['account_id' => $this->account('4000'), 'description' => 'Revenue', 'debit' => '0', 'credit' => '100000']]], $this->user);
        $expense = $journals->create($this->company, ['branch_id' => $this->branch(), 'transaction_date' => '2025-04-11', 'description' => 'Expense fixture', 'lines' => [['account_id' => $this->account('5000'), 'description' => 'Expense', 'debit' => '70000', 'credit' => '0'], ['account_id' => $this->account('1000'), 'description' => 'Expense', 'debit' => '0', 'credit' => '70000']]], $this->user);
        $journals->post($revenue, $this->user);
        $journals->post($expense, $this->user);
        $this->assertSame('30000.0000', app(AccountingReportService::class)->profitAndLoss($this->company, financialYearId: $year->id)['net']);
        foreach ($year->periods as $period) {
            app(AccountingPeriodService::class)->close($period, 'Year-end period close', $this->user);
        }
        app(FinancialYearService::class)->beginClosing($year->fresh(), 'All year-end reviews completed', $this->user);
        $closure = app(YearEndClosingService::class)->close($this->company, $year->fresh(), $this->account('3000'), 'Transfer annual profit to owner equity', $this->user);
        $this->assertSame('30000.0000', $closure->net_result);
        $this->assertSame('closed', $year->fresh()->status);
        $this->assertSame(0, bccomp((string) $closure->closingJournal->lines()->selectRaw('SUM(debit-credit) balance')->value('balance'), '0', 4));
        $this->assertSame($closure->id, app(YearEndClosingService::class)->close($this->company, $year->fresh(), $this->account('3000'), 'Repeat safely returns completed result', $this->user)->id);
        $this->assertDatabaseCount('year_end_closures', 1);
        $this->assertDatabaseCount('journal_entries', 3);
        $this->assertThrows(fn () => app(FinancialYearService::class)->reopen($year->fresh(), 'Attempt unsafe reopening', $this->user), ValidationException::class);
    }

    public function test_year_end_loss_reduces_equity_and_new_year_profit_and_loss_starts_clean(): void
    {
        $year = $this->company->financialYears()->firstOrFail();
        $journals = app(JournalService::class);
        $income = $journals->create($this->company, ['branch_id' => $this->branch(), 'transaction_date' => '2025-05-01', 'description' => 'Loss fixture income', 'lines' => [['account_id' => $this->account('1000'), 'description' => 'Income', 'debit' => '50000', 'credit' => '0'], ['account_id' => $this->account('4000'), 'description' => 'Income', 'debit' => '0', 'credit' => '50000']]], $this->user);
        $expense = $journals->create($this->company, ['branch_id' => $this->branch(), 'transaction_date' => '2025-05-02', 'description' => 'Loss fixture expense', 'lines' => [['account_id' => $this->account('5000'), 'description' => 'Expense', 'debit' => '70000', 'credit' => '0'], ['account_id' => $this->account('1000'), 'description' => 'Expense', 'debit' => '0', 'credit' => '70000']]], $this->user);
        $journals->post($income, $this->user);
        $journals->post($expense, $this->user);
        foreach ($year->periods as $period) {
            app(AccountingPeriodService::class)->close($period, 'Reviewed for annual close', $this->user);
        }
        app(FinancialYearService::class)->beginClosing($year->fresh(), 'Loss year ready for closing', $this->user);
        $closure = app(YearEndClosingService::class)->close($this->company, $year->fresh(), $this->account('3000'), 'Transfer annual loss to owner equity', $this->user);
        $this->assertSame('-20000.0000', $closure->net_result);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $closure->closing_journal_id, 'account_id' => $this->account('3000'), 'debit' => '20000.0000']);
        $next = app(FinancialYearService::class)->create($this->company, ['name' => 'FY 2027', 'starts_on' => '2026-04-01', 'ends_on' => '2027-03-31'], $this->user);
        $this->assertSame('0.0000', app(AccountingReportService::class)->profitAndLoss($this->company, financialYearId: $next->id)['net']);
    }

    public function test_non_accountant_and_cross_entity_accountant_actions_are_rejected(): void
    {
        $viewer = User::factory()->create();
        $this->company->users()->attach($viewer->id, ['role' => 'member']);
        $year = $this->company->financialYears()->firstOrFail();
        $period = $year->periods()->firstOrFail();
        $this->assertThrows(fn () => app(AccountingLockService::class)->update($this->company, ['bookkeeping_lock_date' => '2025-04-30'], $viewer, app(AuditLogger::class)));
        $other = app(CompanyCreator::class)->create(['entity_type' => 'company', 'name' => 'Other Books', 'legal_name' => 'Other Books Ltd', 'country_id' => Country::where('code', 'IN')->value('id'), 'base_currency_id' => Currency::where('code', 'INR')->value('id'), 'timezone' => 'Asia/Kolkata', 'financial_year_start' => '2025-04-01', 'financial_year_end' => '2026-03-31'], $this->user);
        $this->actingAs($this->user)->post(route('period-end.period.close', ['IN', $other, $year, $period]), ['reason' => 'Forged cross entity close'])->assertNotFound();
    }

    public function test_lock_is_rechecked_at_posting_and_authorised_override_is_audited(): void
    {
        $journals = app(JournalService::class);
        $lines = [['account_id' => $this->account('5000'), 'description' => 'Expense', 'debit' => '1000', 'credit' => '0'], ['account_id' => $this->account('2000'), 'description' => 'Accrual', 'debit' => '0', 'credit' => '1000']];
        $standard = $journals->create($this->company, ['branch_id' => $this->branch(), 'transaction_date' => '2025-04-30', 'description' => 'Draft before lock', 'lines' => $lines], $this->user);
        $adjustment = app(PeriodEndService::class)->adjustment($this->company, ['branch_id' => $this->branch(), 'transaction_date' => '2025-04-30', 'description' => 'Accrual before lock', 'reason' => 'Recognise the April accrual', 'lines' => $lines], $this->user);

        app(AccountingLockService::class)->update($this->company, ['bookkeeping_lock_date' => '2025-04-30'], $this->user, app(AuditLogger::class));

        $this->assertThrows(fn () => $journals->post($standard, $this->user), ValidationException::class);
        $this->assertSame('draft', $standard->fresh()->status);
        $journals->post($adjustment, $this->user);
        $this->assertSame('posted', $adjustment->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'user_id' => $this->user->id, 'event' => 'accounting_lock.overridden', 'auditable_id' => $adjustment->id]);
    }

    public function test_posting_rechecks_financial_year_state_and_filed_protection(): void
    {
        $year = $this->company->financialYears()->firstOrFail();
        $journal = app(JournalService::class)->create($this->company, ['branch_id' => $this->branch(), 'transaction_date' => '2025-04-30', 'description' => 'Draft before filing', 'lines' => [['account_id' => $this->account('1000'), 'description' => 'Cash', 'debit' => '100', 'credit' => '0'], ['account_id' => $this->account('4000'), 'description' => 'Income', 'debit' => '0', 'credit' => '100']]], $this->user);
        $year->update(['status' => 'filed']);

        $this->assertThrows(fn () => app(JournalService::class)->post($journal, $this->user), ValidationException::class);
        $this->assertSame('draft', $journal->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['event' => 'journal.posted', 'auditable_id' => $journal->id]);
    }

    public function test_zero_result_and_invalid_equity_fail_atomically_without_closing_journal(): void
    {
        $year = $this->company->financialYears()->firstOrFail();
        foreach ($year->periods as $period) {
            app(AccountingPeriodService::class)->close($period, 'Reviewed for zero-result close', $this->user);
        }
        app(FinancialYearService::class)->beginClosing($year->fresh(), 'All zero-result periods reviewed', $this->user);

        $before = $this->company->journals()->count();
        $this->assertThrows(fn () => app(YearEndClosingService::class)->close($this->company, $year->fresh(), $this->account('1000'), 'Attempt invalid non-equity close account', $this->user));
        $this->assertThrows(fn () => app(YearEndClosingService::class)->close($this->company, $year->fresh(), $this->account('3000'), 'Zero result requires no monetary journal', $this->user), ValidationException::class);
        $this->assertSame($before, $this->company->journals()->count());
        $this->assertDatabaseCount('year_end_closures', 0);
        $this->assertSame('closing', $year->fresh()->status);
    }

    public function test_legacy_period_close_route_cannot_bypass_period_end_readiness(): void
    {
        $year = $this->company->financialYears()->firstOrFail();
        $period = $year->periods()->firstOrFail();

        $this->actingAs($this->user)->post(route('companies.financial-years.periods.close', [$this->company, $year, $period]), [
            'reason' => 'Attempt to bypass the closing checklist',
            'confirmation' => 'CLOSE PERIOD',
        ])->assertSessionHasErrors('closing');

        $this->assertSame('open', $period->fresh()->status);
        $this->assertSame(10, $period->checklistItems()->count());
    }

    private function account(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    private function branch(): int
    {
        return $this->company->branches()->value('id');
    }
}

<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\AccountingReportService;
use App\Services\CompanyCreator;
use App\Services\JournalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class JournalEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private $period;

    private JournalService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = app(CompanyCreator::class)->create(['name' => 'Books', 'legal_name' => 'Books Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
        $this->period = $this->company->financialYears->first()->periods()->first();
        $this->service = app(JournalService::class);
    }

    private function journal(?array $lines = null)
    {
        $accounts = $this->company->accounts()->get();

        return $this->service->create($this->company, ['financial_year_id' => $this->period->financial_year_id, 'accounting_period_id' => $this->period->id, 'transaction_date' => $this->period->starts_on->toDateString(), 'description' => 'Test', 'lines' => $lines ?? [['account_id' => $accounts[0]->id, 'debit' => '100.0000', 'credit' => '0'], ['account_id' => $accounts[4]->id, 'debit' => '0', 'credit' => '100.0000']]], $this->user);
    }

    public function test_valid_balanced_journal_posts_to_open_period(): void
    {
        $j = $this->service->post($this->journal(), $this->user);
        $this->assertSame('posted', $j->fresh()->status);
        $this->assertNotNull($j->fresh()->posted_at);
    }

    public function test_unbalanced_and_one_line_journals_are_rejected(): void
    {
        foreach ([[['account_id' => $this->company->accounts[0]->id, 'debit' => 100, 'credit' => 0]], [['account_id' => $this->company->accounts[0]->id, 'debit' => 100, 'credit' => 0], ['account_id' => $this->company->accounts[1]->id, 'debit' => 0, 'credit' => 99]]] as $lines) {
            try {
                $this->service->post($this->journal($lines), $this->user);
                $this->fail();
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_same_side_zero_and_negative_lines_are_rejected(): void
    {
        foreach ([[['account_id' => $this->company->accounts[0]->id, 'debit' => 10, 'credit' => 10], ['account_id' => $this->company->accounts[1]->id, 'debit' => 0, 'credit' => 10]], [['account_id' => $this->company->accounts[0]->id, 'debit' => -1, 'credit' => 0], ['account_id' => $this->company->accounts[1]->id, 'debit' => 0, 'credit' => -1]], [['account_id' => $this->company->accounts[0]->id, 'debit' => 0, 'credit' => 0], ['account_id' => $this->company->accounts[1]->id, 'debit' => 0, 'credit' => 0]]] as $lines) {
            try {
                $this->service->post($this->journal($lines), $this->user);
                $this->fail();
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_cross_company_account_and_closed_period_are_rejected(): void
    {
        $other = app(CompanyCreator::class)->create(['name' => 'Other', 'legal_name' => 'Other', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $this->user);
        $this->assertThrows(fn () => $this->journal([['account_id' => $this->company->accounts[0]->id, 'debit' => 10, 'credit' => 0], ['account_id' => $other->accounts[0]->id, 'debit' => 0, 'credit' => 10]]), ValidationException::class);
        $j = $this->journal();
        $this->period->update(['status' => 'closed']);
        $this->assertThrows(fn () => $this->service->post($j, $this->user), ValidationException::class);
    }

    public function test_posted_journal_is_immutable_and_not_deletable(): void
    {
        $j = $this->service->post($this->journal(), $this->user);
        $this->assertThrows(fn () => $j->update(['description' => 'Changed']), LogicException::class);
        $this->assertThrows(fn () => $j->delete(), LogicException::class);
    }

    public function test_reversal_is_posted_swapped_and_cannot_duplicate(): void
    {
        $j = $this->service->post($this->journal(), $this->user);
        $r = $this->service->reverse($j, $this->user, $this->period->id, $this->period->starts_on->toDateString());
        $this->assertSame('posted', $r->fresh()->status);
        $this->assertSame('reversed', $j->fresh()->status);
        $original = $j->fresh()->lines()->orderBy('id')->get();
        $reversed = $r->fresh()->lines()->orderBy('id')->get();
        foreach ($original as $i => $line) {
            $this->assertSame($line->debit, $reversed[$i]->credit);
            $this->assertSame($line->credit, $reversed[$i]->debit);
        }$this->assertThrows(fn () => $this->service->reverse($j, $this->user, $this->period->id, $this->period->starts_on->toDateString()), ValidationException::class);
    }

    public function test_reports_calculate_from_posted_lines_only(): void
    {
        $this->service->post($this->journal(), $this->user);
        $reports = app(AccountingReportService::class);
        $ledger = $reports->generalLedger($this->company, $this->company->accounts[0]->id);
        $this->assertSame('100.0000', $ledger->first()->running_balance);
        $trial = $reports->trialBalance($this->company);
        $this->assertSame($trial['debit'], $trial['credit']);
        $pl = $reports->profitAndLoss($this->company);
        $this->assertSame('100.0000', $pl['net']);
        $bs = $reports->balanceSheet($this->company);
        $this->assertSame($bs['assets'], $bs['liabilities_and_equity']);
    }

    public function test_company_isolation_blocks_journal_and_reports(): void
    {
        $outsider = User::factory()->create();
        $j = $this->journal();
        $this->actingAs($outsider)->get(route('journals.show', [$this->company, $j]))->assertNotFound();
        $this->actingAs($outsider)->get(route('reports.trial', ['company_id' => $this->company->id]))->assertNotFound();
    }
}

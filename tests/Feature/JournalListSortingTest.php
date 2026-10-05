<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\CompanyCreator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class JournalListSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private array $journals;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = $this->company('Sortable Books');
        $this->journals = [
            $this->journal('J2026-000001', '2026-01-20', 'Zebra adjustment', 'posted'),
            $this->journal('J2026-000002', '2026-01-10', 'Alpha adjustment', 'draft'),
            $this->journal('J2026-000010', '2026-01-15', 'Middle adjustment', 'reversed'),
            $this->journal('J2026-000020', '2026-01-15', 'Middle adjustment', 'draft'),
        ];
    }

    #[DataProvider('sortCases')]
    public function test_each_journal_column_sorts_ascending_and_descending(string $sort, string $direction, array $order): void
    {
        $before = JournalEntry::where('company_id', $this->company->id)->pluck('updated_at', 'id')->map->toISOString()->all();
        $response = $this->actingAs($this->user)->get($this->url($sort, $direction))->assertOk();

        $this->assertJournalOrder($response->getContent(), $order);
        $response->assertSee($this->label($sort))
            ->assertSee('aria-sort="'.($direction === 'asc' ? 'ascending' : 'descending').'"', false)
            ->assertSee($direction === 'asc' ? '▲' : '▼');
        $this->assertSame($before, JournalEntry::where('company_id', $this->company->id)->pluck('updated_at', 'id')->map->toISOString()->all());
    }

    public static function sortCases(): array
    {
        return [
            'number ascending' => ['number', 'asc', [0, 1, 2, 3]],
            'number descending' => ['number', 'desc', [3, 2, 1, 0]],
            'date ascending' => ['date', 'asc', [1, 2, 3, 0]],
            'date descending' => ['date', 'desc', [0, 3, 2, 1]],
            'description ascending' => ['description', 'asc', [1, 2, 3, 0]],
            'description descending' => ['description', 'desc', [0, 3, 2, 1]],
            'status ascending' => ['status', 'asc', [1, 3, 0, 2]],
            'status descending' => ['status', 'desc', [2, 0, 3, 1]],
        ];
    }

    public function test_header_links_toggle_and_preserve_country_and_entity_context(): void
    {
        $response = $this->actingAs($this->user)->get($this->url('number', 'asc'))->assertOk();

        $response->assertSee('Number')->assertSee('▲')
            ->assertSee('title="Sort by Number"', false)
            ->assertSee('sort=number&amp;direction=desc', false)
            ->assertSee('country_id='.$this->company->country_id, false)
            ->assertSee('company_id='.$this->company->id, false)
            ->assertSee('Create Journal')
            ->assertSee('Opening Balances');

        $this->get($this->url('number', 'desc'))->assertOk()->assertSee('Number')->assertSee('▼')->assertSee('sort=number&amp;direction=asc', false);
    }

    public function test_invalid_sorting_falls_back_safely_and_entity_isolation_is_preserved(): void
    {
        $other = $this->company('Other Entity');
        $foreign = $this->journal('J2026-999999', '2026-01-31', 'FOREIGN JOURNAL TOKEN', 'posted', $other);

        $default = $this->actingAs($this->user)->get(route('accounting', [
            'country_id' => $this->company->country_id,
            'company_id' => $this->company->id,
            'sort' => 'created_by desc; drop table journal_entries',
            'direction' => 'sideways',
        ]))->assertOk()->assertSee('Date')->assertSee('▼')->assertDontSee($foreign->journal_number)->assertDontSee('FOREIGN JOURNAL TOKEN');
        $this->assertJournalOrder($default->getContent(), [0, 3, 2, 1]);

        $invalidDirection = $this->get($this->url('number', 'sideways'))->assertOk()->assertSee('Number')->assertSee('▲');
        $this->assertJournalOrder($invalidDirection->getContent(), [0, 1, 2, 3]);
        $default->assertSee('20 Jan 2026')->assertDontSee('2026-01-20');
    }

    private function url(string $sort, string $direction): string
    {
        return route('accounting', ['country_id' => $this->company->country_id, 'company_id' => $this->company->id, 'sort' => $sort, 'direction' => $direction]);
    }

    private function assertJournalOrder(string $html, array $order): void
    {
        $positions = array_map(fn (int $index) => strpos($html, $this->journals[$index]->journal_number), $order);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    private function label(string $sort): string
    {
        return ['number' => 'Number', 'date' => 'Date', 'description' => 'Description', 'status' => 'Status'][$sort];
    }

    private function company(string $name): Company
    {
        return app(CompanyCreator::class)->create([
            'name' => $name,
            'legal_name' => $name,
            'country_id' => Country::where('code', 'NZ')->value('id'),
            'base_currency_id' => Currency::where('code', 'NZD')->value('id'),
            'timezone' => 'Pacific/Auckland',
            'financial_year_start' => '2026-01-01',
            'financial_year_end' => '2026-12-31',
        ], $this->user);
    }

    private function journal(string $number, string $date, string $description, string $status, ?Company $company = null): JournalEntry
    {
        $company ??= $this->company;
        $year = $company->financialYears()->firstOrFail();
        $period = $year->periods()->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->firstOrFail();

        return $company->journals()->create([
            'financial_year_id' => $year->id,
            'accounting_period_id' => $period->id,
            'journal_number' => $number,
            'transaction_date' => $date,
            'description' => $description,
            'status' => $status,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }
}

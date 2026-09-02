<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\User;
use App\Services\BranchService;
use App\Services\CompanyCreator;
use App\Services\JournalService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DraftJournalMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    private JournalService $service;

    private $period;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = $this->makeCompany($this->user, 'Draft Books');
        $this->period = $this->company->financialYears()->first()->periods()->first();
        $this->service = app(JournalService::class);
    }

    public function test_draft_fields_accounts_amounts_and_lines_can_be_edited(): void
    {
        $journal = $this->draft('500', '300');
        $accounts = $this->company->accounts()->get();
        $data = $this->data([['account_id' => $accounts[1]->id, 'description' => 'Corrected debit', 'debit' => '300.00', 'credit' => '0'], ['account_id' => $accounts[4]->id, 'description' => 'Corrected credit', 'debit' => '0', 'credit' => '300.00'], ['account_id' => $accounts[2]->id, 'description' => 'Added line', 'debit' => '25.00', 'credit' => '0']]);
        $data['reference'] = 'UPDATED';
        $data['description'] = 'Updated narration';
        $updated = $this->service->update($journal, $data, $this->user);
        $this->assertSame('UPDATED', $updated->reference);
        $this->assertSame('Updated narration', $updated->description);
        $this->assertCount(3, $updated->lines);
        $this->assertSame($accounts[1]->id, $updated->lines[0]->account_id);

        array_pop($data['lines']);
        $this->assertCount(2, $this->service->update($journal, $data, $this->user)->lines);
    }

    public function test_valid_active_branch_can_change_and_invalid_branches_are_rejected(): void
    {
        $journal = $this->draft();
        $branch = app(BranchService::class)->create($this->company, ['code' => 'AKL', 'name' => 'Auckland', 'is_active' => true, 'is_main_branch' => false], $this->user);
        $data = $this->data();
        $data['branch_id'] = $branch->id;
        $this->assertSame($branch->id, $this->service->update($journal, $data, $this->user)->branch_id);
        app(BranchService::class)->setActive($this->company, $branch, false, $this->user);
        $this->assertThrows(fn () => $this->service->update($journal, $data, $this->user), ModelNotFoundException::class);

        $other = $this->makeCompany($this->user, 'Other Books');
        $data['branch_id'] = $other->branches()->first()->id;
        $this->assertThrows(fn () => $this->service->update($journal, $data, $this->user), ModelNotFoundException::class);
    }

    public function test_invalid_lines_and_unbalanced_post_are_rejected_then_corrected_draft_posts(): void
    {
        $journal = $this->draft('500', '300');
        $this->assertThrows(fn () => $this->service->post($journal, $this->user), ValidationException::class);
        $invalidSets = [array_slice($this->data()['lines'], 0, 1), [['account_id' => $this->company->accounts[0]->id, 'debit' => 10, 'credit' => 10], ['account_id' => $this->company->accounts[1]->id, 'debit' => 0, 'credit' => 10]], [['account_id' => $this->company->accounts[0]->id, 'debit' => -1, 'credit' => 0], ['account_id' => $this->company->accounts[1]->id, 'debit' => 0, 'credit' => 1]]];
        foreach ($invalidSets as $lines) {
            $data = $this->data($lines);
            $this->assertThrows(fn () => $this->service->update($journal, $data, $this->user), ValidationException::class);
        }
        $this->service->update($journal, $this->data(), $this->user);
        $this->assertSame('posted', $this->service->post($journal->fresh(), $this->user)->status);
    }

    public function test_draft_delete_removes_lines_but_posted_and_reversed_are_protected(): void
    {
        $draft = $this->draft();
        $lineIds = $draft->lines->pluck('id');
        $this->service->deleteDraft($draft, $this->user);
        $this->assertDatabaseMissing('journal_entries', ['id' => $draft->id]);
        foreach ($lineIds as $id) {
            $this->assertDatabaseMissing('journal_lines', ['id' => $id]);
        }
        $routeDraft = $this->draft();
        $this->actingAs($this->user)->delete(route('journals.destroy', [$this->company, $routeDraft]), ['confirmation' => 'WRONG'])->assertSessionHasErrors('confirmation');
        $this->assertDatabaseHas('journal_entries', ['id' => $routeDraft->id]);
        $this->delete(route('journals.destroy', [$this->company, $routeDraft]), ['confirmation' => $routeDraft->journal_number])->assertRedirect();
        $this->assertDatabaseMissing('journal_entries', ['id' => $routeDraft->id]);
        $posted = $this->service->post($this->draft(), $this->user);
        $this->assertThrows(fn () => $this->service->update($posted, $this->data(), $this->user), ValidationException::class);
        $this->assertThrows(fn () => $this->service->deleteDraft($posted, $this->user), ValidationException::class);
        $this->service->reverse($posted, $this->user, $this->period->id, $this->period->starts_on->toDateString());
        $this->assertThrows(fn () => $this->service->deleteDraft($posted->fresh(), $this->user), ValidationException::class);
    }

    public function test_routes_enforce_company_isolation_and_ui_has_explicit_line_headings(): void
    {
        $journal = $this->draft();
        $otherUser = User::factory()->create();
        $other = $this->makeCompany($otherUser, 'Other');
        $this->actingAs($otherUser)->get(route('journals.edit', [$other, $journal]))->assertNotFound();
        $this->actingAs($otherUser)->delete(route('journals.destroy', [$other, $journal]), ['confirmation' => $journal->journal_number])->assertNotFound();
        $this->actingAs($this->user)->get(route('journals.edit', [$this->company, $journal]))->assertOk()->assertSeeInOrder(['Account', 'Description', 'Debit', 'Credit', 'Action'])->assertSee('Remove Line')->assertSee('Add Line');
        $this->get(route('journals.show', [$this->company, $journal]))->assertOk()->assertSee('Edit Draft')->assertSee('Delete Draft');
    }

    private function draft(string $debit = '100', string $credit = '100')
    {
        $accounts = $this->company->accounts()->get();

        return $this->service->create($this->company, $this->data([['account_id' => $accounts[0]->id, 'description' => 'Debit', 'debit' => $debit, 'credit' => '0'], ['account_id' => $accounts[4]->id, 'description' => 'Credit', 'debit' => '0', 'credit' => $credit]]), $this->user);
    }

    private function data(?array $lines = null): array
    {
        $accounts = $this->company->accounts()->get();

        return ['branch_id' => $this->company->branches()->first()->id, 'financial_year_id' => $this->period->financial_year_id, 'accounting_period_id' => $this->period->id, 'transaction_date' => $this->period->starts_on->toDateString(), 'reference' => 'REF', 'description' => 'Draft journal', 'lines' => $lines ?? [['account_id' => $accounts[0]->id, 'debit' => '100.00', 'credit' => '0'], ['account_id' => $accounts[4]->id, 'debit' => '0', 'credit' => '100.00']]];
    }

    private function makeCompany(User $user, string $name): Company
    {
        return app(CompanyCreator::class)->create(['name' => $name, 'legal_name' => $name.' Ltd', 'country_id' => Country::first()->id, 'base_currency_id' => Currency::first()->id, 'timezone' => 'UTC', 'financial_year_start' => '2026-01-01', 'financial_year_end' => '2026-12-31'], $user);
    }
}

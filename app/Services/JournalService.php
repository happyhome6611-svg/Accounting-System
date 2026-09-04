<?php

namespace App\Services;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class JournalService
{
    public function __construct(private AuditLogger $audit, private BranchService $branches, private FinancialYearResolver $years) {}

    public function create(Company $company, array $data, User $user): JournalEntry
    {
        $this->assertAccessible($company->id, $user);
        if ($company->is_active === false) {
            throw ValidationException::withMessages(['company' => 'Inactive companies cannot accept new accounting transactions.']);
        }
        $branch = $this->branches->resolve($company, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $period = $this->validateDraft($company, $data);

        return DB::transaction(function () use ($company, $data, $user, $branch, $period) {
            $journal = $company->journals()->create(['branch_id' => $branch?->id, 'financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'journal_number' => $this->nextNumber($company), 'transaction_date' => $data['transaction_date'], 'reference' => $data['reference'] ?? null, 'description' => $data['description'], 'status' => 'draft', 'created_by' => $user->id, 'updated_by' => $user->id]);
            foreach ($data['lines'] as $line) {
                $journal->lines()->create(['company_id' => $company->id, ...$line]);
            }$this->audit->log('journal.created', $journal, $company->id, $user->id, null, $journal->toArray());

            return $journal->load('lines.account');
        });
    }

    public function update(JournalEntry $journal, array $data, User $user): JournalEntry
    {
        $this->assertAccessible($journal->company_id, $user);
        $company = Company::findOrFail($journal->company_id);
        $branch = $this->branches->resolve($company, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $period = $this->validateDraft($company, $data);

        return DB::transaction(function () use ($journal, $data, $user, $branch, $period) {
            $journal = JournalEntry::query()->lockForUpdate()->with('lines')->findOrFail($journal->id);
            if ($journal->status !== 'draft' || $journal->reversal_of_id) {
                throw ValidationException::withMessages(['journal' => 'Only an ordinary Draft journal can be edited.']);
            }
            $before = $journal->toArray() + ['lines' => $journal->lines->toArray()];
            $journal->update(['branch_id' => $branch?->id, 'financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'transaction_date' => $data['transaction_date'], 'reference' => $data['reference'] ?? null, 'description' => $data['description'], 'updated_by' => $user->id]);
            $journal->lines()->delete();
            foreach ($data['lines'] as $line) {
                $journal->lines()->create(['company_id' => $journal->company_id, ...$line]);
            }
            $this->audit->log('journal.updated', $journal, $journal->company_id, $user->id, $before, $journal->fresh()->load('lines')->toArray());

            return $journal->fresh()->load('lines.account');
        });
    }

    public function deleteDraft(JournalEntry $journal, User $user): void
    {
        $this->assertAccessible($journal->company_id, $user);
        DB::transaction(function () use ($journal) {
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($journal->id);
            if ($journal->status !== 'draft' || $journal->reversal_of_id) {
                throw ValidationException::withMessages(['journal' => 'Only an ordinary Draft journal can be permanently deleted.']);
            }
            DB::table('audit_logs')->where('company_id', $journal->company_id)->where('auditable_type', JournalEntry::class)->where('auditable_id', $journal->id)->delete();
            $journal->lines()->delete();
            $journal->delete();
        });
    }

    public function post(JournalEntry $journal, User $user): JournalEntry
    {
        $this->assertAccessible($journal->company_id, $user);

        return DB::transaction(function () use ($journal, $user) {
            $journal = JournalEntry::query()->lockForUpdate()->with(['lines.account', 'period'])->findOrFail($journal->id);
            $errors = [];
            if ($journal->status !== 'draft') {
                $errors[] = 'Journal must be Draft.';
            }if ($journal->period->status !== 'open') {
                $errors[] = 'Accounting period is closed.';
            }if (! $journal->transaction_date->betweenIncluded($journal->period->starts_on, $journal->period->ends_on)) {
                $errors[] = 'Journal date must fall within the accounting period.';
            }if ($journal->lines->count() < 2) {
                $errors[] = 'A journal requires at least two lines.';
            }foreach ($journal->lines as $line) {
                if ($line->company_id !== $journal->company_id || $line->account->company_id !== $journal->company_id) {
                    $errors[] = 'All accounts must belong to the journal company.';
                }if (! $line->account->is_active) {
                    $errors[] = 'All accounts must be active.';
                }if (bccomp($line->debit, '0', 4) < 0 || bccomp($line->credit, '0', 4) < 0 || ((bccomp($line->debit, '0', 4) > 0) === (bccomp($line->credit, '0', 4) > 0))) {
                    $errors[] = 'Each line must contain one positive debit or credit.';
                }
            }$debit = $journal->lines->reduce(fn ($c, $l) => bcadd($c, $l->debit, 4), '0.0000');
            $credit = $journal->lines->reduce(fn ($c, $l) => bcadd($c, $l->credit, 4), '0.0000');
            if (bccomp($debit, $credit, 4) !== 0) {
                $errors[] = 'Total debit must equal total credit.';
            }if ($errors) {
                throw ValidationException::withMessages(['journal' => array_values(array_unique($errors))]);
            }app()->instance('accounting.system-write', true);
            $journal->update(['status' => 'posted', 'posted_at' => now(), 'posted_by' => $user->id, 'updated_by' => $user->id]);
            app()->forgetInstance('accounting.system-write');
            $this->audit->log('journal.posted', $journal, $journal->company_id, $user->id, ['status' => 'draft'], ['status' => 'posted']);

            return $journal;
        });
    }

    public function reverse(JournalEntry $original, User $user, int $periodId, string $date): JournalEntry
    {
        $this->assertAccessible($original->company_id, $user);

        return DB::transaction(function () use ($original, $user, $periodId, $date) {
            $original = JournalEntry::query()->lockForUpdate()->with('lines')->findOrFail($original->id);
            if ($original->status !== 'posted' || $original->reversal()->exists()) {
                throw ValidationException::withMessages(['journal' => 'Only an unreversed Posted journal can be reversed.']);
            }$period = $original->period()->getModel()->where('company_id', $original->company_id)->findOrFail($periodId);
            if ($period->status !== 'open' || ! now()->parse($date)->betweenIncluded($period->starts_on, $period->ends_on)) {
                throw ValidationException::withMessages(['journal' => 'Reversal date must be in an open accounting period.']);
            }$reversal = $original->replicate(['status', 'posted_at', 'posted_by', 'reversed_at', 'reversed_by']);
            $reversal->fill(['accounting_period_id' => $period->id, 'financial_year_id' => $period->financial_year_id, 'journal_number' => $this->nextNumber(Company::findOrFail($original->company_id)), 'transaction_date' => $date, 'reference' => 'REV-'.$original->journal_number, 'description' => 'Reversal: '.$original->description, 'status' => 'draft', 'reversal_of_id' => $original->id, 'created_by' => $user->id, 'updated_by' => $user->id])->save();
            foreach ($original->lines as $line) {
                $reversal->lines()->create(['company_id' => $original->company_id, 'account_id' => $line->account_id, 'description' => $line->description, 'debit' => $line->credit, 'credit' => $line->debit, 'reference' => $line->reference]);
            }$this->post($reversal, $user);
            app()->instance('accounting.system-write', true);
            $original->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $user->id, 'updated_by' => $user->id]);
            app()->forgetInstance('accounting.system-write');
            $this->audit->log('journal.reversed', $original, $original->company_id, $user->id, ['status' => 'posted'], ['status' => 'reversed', 'reversal_id' => $reversal->id]);

            return $reversal;
        });
    }

    private function nextNumber(Company $company): string
    {
        return 'J'.now()->format('Y').'-'.str_pad((string) (((int) $company->journals()->max('id')) + 1), 6, '0', STR_PAD_LEFT);
    }

    private function validateDraft(Company $company, array $data)
    {
        $period = $this->years->resolve($company, $data['transaction_date'], isset($data['financial_year_id']) ? (int) $data['financial_year_id'] : null, isset($data['accounting_period_id']) ? (int) $data['accounting_period_id'] : null);
        if (count($data['lines']) < 2) {
            throw ValidationException::withMessages(['lines' => 'A journal requires at least two lines.']);
        }
        foreach ($data['lines'] as $index => $line) {
            if (! $company->accounts()->whereKey($line['account_id'])->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(["lines.$index.account_id" => 'Select an active account belonging to this company.']);
            }
            $debit = (string) ($line['debit'] ?? 0);
            $credit = (string) ($line['credit'] ?? 0);
            if (bccomp($debit, '0', 4) < 0 || bccomp($credit, '0', 4) < 0 || ((bccomp($debit, '0', 4) > 0) === (bccomp($credit, '0', 4) > 0))) {
                throw ValidationException::withMessages(["lines.$index.debit" => 'Each line must contain one positive debit or credit.']);
            }
        }

        return $period;
    }

    private function assertAccessible(int $companyId, User $user): void
    {
        if (! $user->companies()->whereKey($companyId)->exists()) {
            throw ValidationException::withMessages(['company' => 'The company is not accessible to this user.']);
        }
    }
}

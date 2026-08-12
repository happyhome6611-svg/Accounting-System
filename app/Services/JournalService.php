<?php

namespace App\Services;

use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class JournalService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(Company $company, array $data, User $user): JournalEntry
    {
        $this->assertAccessible($company->id, $user);
        if ($company->is_active === false) {
            throw ValidationException::withMessages(['company' => 'Inactive companies cannot accept new accounting transactions.']);
        }

        return DB::transaction(function () use ($company, $data, $user) {
            $period = $company->financialYears()->with('periods')->findOrFail($data['financial_year_id'])->periods->firstWhere('id', (int) $data['accounting_period_id']);
            if (! $period) {
                throw ValidationException::withMessages(['accounting_period_id' => 'Period does not belong to this company and financial year.']);
            }$journal = $company->journals()->create(['financial_year_id' => $data['financial_year_id'], 'accounting_period_id' => $period->id, 'journal_number' => $this->nextNumber($company), 'transaction_date' => $data['transaction_date'], 'reference' => $data['reference'] ?? null, 'description' => $data['description'], 'status' => 'draft', 'created_by' => $user->id, 'updated_by' => $user->id]);
            foreach ($data['lines'] as $line) {
                $journal->lines()->create(['company_id' => $company->id, ...$line]);
            }$this->audit->log('journal.created', $journal, $company->id, $user->id, null, $journal->toArray());

            return $journal->load('lines.account');
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
        return 'J'.now()->format('Y').'-'.str_pad((string) ($company->journals()->count() + 1), 6, '0', STR_PAD_LEFT);
    }

    private function assertAccessible(int $companyId, User $user): void
    {
        if (! $user->companies()->whereKey($companyId)->exists()) {
            throw ValidationException::withMessages(['company' => 'The company is not accessible to this user.']);
        }
    }
}

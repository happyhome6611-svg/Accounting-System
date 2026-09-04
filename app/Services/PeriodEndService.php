<?php

namespace App\Services;

use App\Models\AccountingPeriod;
use App\Models\AdjustmentReversalSchedule;
use App\Models\Company;
use App\Models\JournalEntry;
use App\Models\PeriodCloseChecklistItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PeriodEndService
{
    public function __construct(private AccountingLockService $locks, private ClosingReadinessService $readiness, private JournalService $journals, private AccountingPeriodService $periods, private AuditLogger $audit) {}

    public function adjustment(Company $company, array $data, User $user): JournalEntry
    {
        $this->locks->authorize($company, $user);
        if (mb_strlen(trim((string) ($data['reason'] ?? ''))) < 10) {
            throw ValidationException::withMessages(['reason' => 'A clear adjustment reason of at least 10 characters is required.']);
        }
        $journal = $this->journals->create($company, $data + ['journal_type' => 'adjusting'], $user);
        $this->audit->log('adjustment_journal.created', $journal, $company->id, $user->id);

        return $journal;
    }

    public function scheduleReversal(Company $company, JournalEntry $journal, string $date, User $user): AdjustmentReversalSchedule
    {
        $this->locks->authorize($company, $user);
        abort_unless($journal->company_id === $company->id, 404);
        if ($journal->journal_type !== 'adjusting' || $journal->status !== 'posted') {
            throw ValidationException::withMessages(['journal' => 'Only a posted Adjusting journal can be scheduled for reversal.']);
        }

        return AdjustmentReversalSchedule::firstOrCreate(['adjustment_journal_id' => $journal->id], ['company_id' => $company->id, 'reversal_date' => $date, 'status' => 'scheduled', 'created_by' => $user->id]);
    }

    public function postReversal(Company $company, AdjustmentReversalSchedule $schedule, User $user): JournalEntry
    {
        $this->locks->authorize($company, $user);

        return DB::transaction(function () use ($company, $schedule, $user) {
            $schedule = AdjustmentReversalSchedule::where('company_id', $company->id)->lockForUpdate()->findOrFail($schedule->id);
            if ($schedule->status === 'posted') {
                return $schedule->reversalJournal()->firstOrFail();
            }
            $original = $schedule->adjustmentJournal()->with('lines')->firstOrFail();
            $journal = $this->journals->create($company, ['journal_type' => 'reversing', 'transaction_date' => $schedule->reversal_date->toDateString(), 'reference' => 'REV-'.$original->journal_number, 'description' => 'Scheduled reversal: '.$original->description, 'reason' => 'Scheduled reversal of '.$original->journal_number, 'lines' => $original->lines->map(fn ($line) => ['account_id' => $line->account_id, 'description' => $line->description, 'debit' => $line->credit, 'credit' => $line->debit])->all()], $user);
            $this->journals->post($journal, $user);
            $schedule->update(['status' => 'posted', 'reversal_journal_id' => $journal->id, 'posted_by' => $user->id, 'posted_at' => now()]);
            $this->audit->log('adjustment_reversal.posted', $schedule, $company->id, $user->id);

            return $journal;
        });
    }

    public function checklist(Company $company, AccountingPeriod $period, int $itemId, array $data, User $user): void
    {
        $this->locks->authorize($company, $user);
        $item = PeriodCloseChecklistItem::where('company_id', $company->id)->where('accounting_period_id', $period->id)->findOrFail($itemId);
        if ($item->is_system_check) {
            throw ValidationException::withMessages(['status' => 'System checks cannot be manually overridden.']);
        }
        $before = $item->toArray();
        $completed = in_array($data['status'], ['completed', 'not_applicable'], true);
        $item->update(['status' => $data['status'], 'notes' => $data['notes'] ?? null, 'completed_by' => $completed ? $user->id : null, 'completed_at' => $completed ? now() : null, 'updated_by' => $user->id]);
        $this->audit->log('period_checklist.updated', $item, $company->id, $user->id, $before, $item->fresh()->toArray());
    }

    public function close(Company $company, AccountingPeriod $period, string $reason, User $user): void
    {
        $this->locks->authorize($company, $user);
        DB::transaction(function () use ($company, $period, $reason, $user) {
            $period = AccountingPeriod::where('company_id', $company->id)->lockForUpdate()->findOrFail($period->id);
            $blockers = $this->readiness->blockers($period);
            if ($blockers) {
                throw ValidationException::withMessages(['closing' => $blockers]);
            }
            $snapshot = ['checklist' => $period->checklistItems()->get()->toArray(), 'closed_at' => now()->toIso8601String()];
            $this->periods->close($period, $reason, $user);
            $period->update(['closed_by' => $user->id, 'closure_reason' => $reason, 'closure_snapshot' => $snapshot]);
        });
    }

    public function reopen(Company $company, AccountingPeriod $period, string $reason, User $user): void
    {
        $this->locks->authorize($company, $user);
        if ($period->financialYear->status === 'filed') {
            throw ValidationException::withMessages(['period' => 'A period in a Filed Financial Year cannot be reopened.']);
        }
        if ($period->financialYear->periods()->whereDate('starts_on', '>', $period->starts_on)->where('status', 'closed')->exists()) {
            throw ValidationException::withMessages(['period' => 'Later closed periods must be reopened first in reverse chronological order.']);
        }
        $this->periods->reopen($period, $reason, $user);
    }
}

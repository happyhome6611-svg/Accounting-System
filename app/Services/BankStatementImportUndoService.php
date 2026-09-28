<?php

namespace App\Services;

use App\Models\BankStatementImport;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BankStatementImportUndoService
{
    public function __construct(private AuditLogger $audit) {}

    public function analysis(Company $company, BankStatementImport $batch, User $user): array
    {
        $this->authorize($company, $batch, $user);
        $blocked = $batch->rows()->where(fn ($query) => $query->where('status', '!=', 'unmatched')->orWhereHas('match'))->count();

        return ['safe' => $batch->rows()->count() - $blocked, 'blocked' => $blocked, 'already_undone' => $batch->undo_status === 'undone' ? $batch->imported_count : 0];
    }

    public function undo(Company $company, BankStatementImport $batch, User $user, string $confirmation): void
    {
        if ($confirmation !== 'UNDO') {
            throw ValidationException::withMessages(['confirmation' => 'Type UNDO exactly to confirm.']);
        }
        $preflight = $this->analysis($company, $batch, $user);
        if ($preflight['blocked']) {
            $batch->update(['undo_status' => 'blocked', 'undo_summary' => $preflight]);
            $this->audit->log('bank_statement_import.undo_blocked', $batch, $company->id, $user->id, null, $preflight);
            throw ValidationException::withMessages(['undo' => 'Matched or reconciled statement evidence cannot be removed.']);
        }
        DB::transaction(function () use ($company, $batch, $user) {
            $batch = BankStatementImport::lockForUpdate()->findOrFail($batch->id);
            $analysis = $this->analysis($company, $batch, $user);
            if ($batch->undo_status === 'undone') {
                return;
            }
            if ($analysis['blocked']) {
                throw ValidationException::withMessages(['undo' => 'Matched or reconciled statement evidence cannot be removed.']);
            }
            $batch->rows()->delete();
            $batch->update(['undo_status' => 'undone', 'undo_summary' => $analysis, 'undone_by' => $user->id, 'undone_at' => now()]);
            $this->audit->log('bank_statement_import.undone', $batch, $company->id, $user->id, null, $analysis);
        });
    }

    public function deleteAttempt(Company $company, BankStatementImport $batch, User $user): void
    {
        $this->authorize($company, $batch, $user);
        abort_if($batch->rows()->exists() || $batch->imported_count > 0, 422, 'This statement import created evidence rows. Use Undo Import.');
        DB::transaction(function () use ($company, $batch, $user) {
            $this->audit->log('bank_statement_import.attempt_deleted', $batch, $company->id, $user->id, null, ['filename' => $batch->file_name]);
            $batch->delete();
        });
    }

    private function authorize(Company $company, BankStatementImport $batch, User $user): void
    {
        abort_unless($user->companies()->whereKey($company->id)->exists() && $batch->company_id === $company->id, 404);
    }
}

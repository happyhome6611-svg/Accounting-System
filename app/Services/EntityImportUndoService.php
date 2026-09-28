<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\EntityImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EntityImportUndoService
{
    public function __construct(private CompanyDeletionService $companies) {}

    public function analysis(EntityImportBatch $batch, User $user): array
    {
        $this->authorize($batch, $user);
        $company = $batch->resultCompany;
        $blockers = $company ? $this->companies->blockers($company) : [];

        return ['safe' => $company && $blockers === [] ? 1 : 0, 'blocked' => $blockers, 'already_undone' => ! $company && $batch->undo_status === 'undone'];
    }

    public function undo(EntityImportBatch $batch, User $user, string $confirmation): void
    {
        if ($confirmation !== 'UNDO') {
            throw ValidationException::withMessages(['confirmation' => 'Type UNDO exactly to confirm.']);
        }
        $preflight = $this->analysis($batch, $user);
        if ($preflight['blocked']) {
            $batch->update(['undo_status' => 'blocked', 'undo_summary' => $preflight]);
            throw ValidationException::withMessages(['undo' => 'The imported entity contains '.implode(', ', $preflight['blocked']).'.']);
        }
        DB::transaction(function () use ($batch, $user) {
            $batch = EntityImportBatch::lockForUpdate()->findOrFail($batch->id);
            $this->authorize($batch, $user);
            if ($batch->undo_status === 'undone') {
                return;
            }
            $company = Company::find($batch->result_company_id);
            if (! $company) {
                $batch->update(['undo_status' => 'undone', 'undone_by' => $user->id, 'undone_at' => now()]);

                return;
            }
            $blockers = $this->companies->blockers($company);
            if ($blockers) {
                throw ValidationException::withMessages(['undo' => 'The imported entity contains '.implode(', ', $blockers).'.']);
            }
            $summary = ['entity_id' => $company->id, 'entity_name' => $company->name, 'generated_foundation_removed' => true];
            $batch->update(['result_company_id' => null]);
            $this->companies->delete($company, $user, $company->name);
            $batch->update(['undo_status' => 'undone', 'undo_summary' => $summary, 'undone_by' => $user->id, 'undone_at' => now()]);
            AuditLog::create(['company_id' => null, 'user_id' => $user->id, 'event' => 'accounting_entity_import.undone', 'auditable_type' => EntityImportBatch::class, 'auditable_id' => $batch->id, 'new_values' => $summary]);
        });
    }

    public function deleteAttempt(EntityImportBatch $batch, User $user): void
    {
        $this->authorize($batch, $user);
        abort_if($batch->result_company_id, 422, 'This import created an Accounting Entity. Use Undo Import.');
        AuditLog::create(['company_id' => null, 'user_id' => $user->id, 'event' => 'accounting_entity_import.attempt_deleted', 'auditable_type' => EntityImportBatch::class, 'auditable_id' => $batch->id, 'new_values' => ['filename' => $batch->original_filename]]);
        $batch->delete();
    }

    private function authorize(EntityImportBatch $batch, User $user): void
    {
        abort_unless($batch->uploaded_by === $user->id, 404);
    }
}

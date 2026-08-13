<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BranchService
{
    private const TRANSACTION_TABLES = ['journal_entries', 'sales_quotations', 'sales_orders', 'sales_invoices', 'sales_credit_notes', 'customer_receipts'];

    public function __construct(private AuditLogger $audit) {}

    public function resolve(Company $company, ?int $id, bool $active = true): Branch
    {
        $query = $company->branches();
        if ($active) {
            $query->where('is_active', true);
        }
        if ($id) {
            return $query->findOrFail($id);
        }
        $branches = $query->get();
        if ($branches->count() === 1) {
            return $branches->first();
        }

        throw ValidationException::withMessages(['branch_id' => 'Select a valid active branch.']);
    }

    public function create(Company $company, array $data, User $user): Branch
    {
        $this->access($company, $user);
        if (! empty($data['is_main_branch']) && $company->branches()->where('is_main_branch', true)->exists()) {
            throw ValidationException::withMessages(['is_main_branch' => 'The company already has a main branch.']);
        }
        $branch = $company->branches()->create([...$data, 'created_by' => $user->id, 'updated_by' => $user->id]);
        $this->audit->log('branch.created', $branch, $company->id, $user->id);

        return $branch;
    }

    public function update(Company $company, Branch $branch, array $data, User $user): Branch
    {
        $this->access($company, $user, $branch);
        if (array_key_exists('is_main_branch', $data) && ! $data['is_main_branch'] && $branch->is_main_branch) {
            throw ValidationException::withMessages(['is_main_branch' => 'Assign another branch as main instead of removing the main designation.']);
        }
        $before = $branch->toArray();
        DB::transaction(function () use ($company, $branch, $data, $user) {
            if (! empty($data['is_main_branch']) && ! $branch->is_main_branch) {
                $company->branches()->where('is_main_branch', true)->update(['is_main_branch' => false, 'updated_by' => $user->id]);
            }
            $branch->update([...$data, 'updated_by' => $user->id]);
        });
        $this->audit->log('branch.updated', $branch, $company->id, $user->id, $before, $branch->fresh()->toArray());

        return $branch;
    }

    public function setActive(Company $company, Branch $branch, bool $active, User $user): void
    {
        $this->access($company, $user, $branch);
        if (! $active && $company->branches()->where('is_active', true)->count() <= 1) {
            throw ValidationException::withMessages(['branch' => 'A company must retain at least one active branch.']);
        }
        $branch->update(['is_active' => $active, 'updated_by' => $user->id]);
        $this->audit->log($active ? 'branch.reactivated' : 'branch.deactivated', $branch, $company->id, $user->id);
    }

    public function blockers(Branch $branch): array
    {
        return collect(self::TRANSACTION_TABLES)->filter(fn ($table) => DB::table($table)->where('branch_id', $branch->id)->exists())->values()->all();
    }

    public function delete(Company $company, Branch $branch, string $name, User $user): void
    {
        $this->access($company, $user, $branch);
        if (! hash_equals($branch->name, $name)) {
            throw ValidationException::withMessages(['confirmation_name' => 'Enter the exact branch name.']);
        }
        DB::transaction(function () use ($company, $branch) {
            $locked = Branch::lockForUpdate()->where('company_id', $company->id)->findOrFail($branch->id);
            if ($this->blockers($locked)) {
                throw ValidationException::withMessages(['branch' => 'Branch has transactions and cannot be deleted. Deactivate it instead.']);
            }
            if ($locked->is_main_branch) {
                throw ValidationException::withMessages(['branch' => 'Reassign the company main branch before deleting it.']);
            }
            DB::table('audit_logs')->where('company_id', $company->id)->where('auditable_type', Branch::class)->where('auditable_id', $locked->id)->delete();
            $locked->delete();
        });
    }

    private function access(Company $company, User $user, ?Branch $branch = null): void
    {
        abort_unless($user->companies()->whereKey($company->id)->exists() && (! $branch || $branch->company_id === $company->id), 404);
    }
}

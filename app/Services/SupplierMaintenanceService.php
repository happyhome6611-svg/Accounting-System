<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SupplierMaintenanceService
{
    public function __construct(private DocumentNumberService $numbers, private AuditLogger $audit, private TaxDefaultService $taxDefaults) {}

    public function create(Company $company, array $data, User $user): Supplier
    {
        $this->access($company, $user);
        $this->validateAccount($company, $data['payable_account_id']);
        $this->validateCurrency($company, $data['currency_id']);
        $this->taxDefaults->validate($company, $data['default_purchase_tax_code_id'] ?? null);
        $supplier = $company->suppliers()->create([...$data, 'code' => $data['code'] ?: $this->numbers->next($company, 'supplier', 'SUP'), 'created_by' => $user->id, 'updated_by' => $user->id]);
        $this->audit->log('supplier.created', $supplier, $company->id, $user->id);

        return $supplier;
    }

    public function update(Company $company, Supplier $supplier, array $data, User $user): Supplier
    {
        $this->access($company, $user, $supplier);
        $this->validateAccount($company, $data['payable_account_id']);
        $this->validateCurrency($company, $data['currency_id']);
        $this->taxDefaults->validate($company, $data['default_purchase_tax_code_id'] ?? null);
        $old = $supplier->toArray();
        $supplier->update([...$data, 'updated_by' => $user->id]);
        $this->audit->log('supplier.updated', $supplier, $company->id, $user->id, $old, $supplier->fresh()->toArray());

        return $supplier->fresh();
    }

    public function setActive(Company $company, Supplier $supplier, bool $active, User $user): void
    {
        $this->access($company, $user, $supplier);
        $supplier->update(['is_active' => $active, 'updated_by' => $user->id]);
        $this->audit->log($active ? 'supplier.reactivated' : 'supplier.deactivated', $supplier, $company->id, $user->id);
    }

    public function blockers(Supplier $supplier): array
    {
        return collect(['purchase_orders' => 'purchase orders', 'supplier_bills' => 'supplier bills', 'supplier_credits' => 'supplier credits', 'supplier_payments' => 'supplier payments'])->filter(fn ($label, $table) => DB::table($table)->where('supplier_id', $supplier->id)->exists())->values()->all();
    }

    public function delete(Company $company, Supplier $supplier, string $confirmation, User $user): void
    {
        $this->access($company, $user, $supplier);
        if (! hash_equals($supplier->name, $confirmation)) {
            throw ValidationException::withMessages(['confirmation_name' => 'Enter the exact supplier name.']);
        }
        DB::transaction(function () use ($company, $supplier) {
            $locked = Supplier::lockForUpdate()->where('company_id', $company->id)->findOrFail($supplier->id);
            if ($this->blockers($locked)) {
                throw ValidationException::withMessages(['supplier' => 'Supplier has purchase history and cannot be deleted. Deactivate it instead.']);
            } DB::table('audit_logs')->where('company_id', $company->id)->where('auditable_type', Supplier::class)->where('auditable_id', $locked->id)->delete();
            $locked->forceDelete();
        });
    }

    private function validateAccount(Company $company, int $id): void
    {
        $company->accounts()->where('type', 'liability')->whereKey($id)->firstOrFail();
    }

    private function validateCurrency(Company $company, int $id): void
    {
        abort_unless($company->base_currency_id === $id, 422, 'Purchases use the entity base currency in v0.4.');
    }

    private function access(Company $company, User $user, ?Supplier $supplier = null): void
    {
        abort_unless($user->companies()->whereKey($company->id)->exists() && $company->entity_type !== 'individual' && (! $supplier || $supplier->company_id === $company->id), 404);
    }
}

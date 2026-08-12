<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CustomerMaintenanceService
{
    private const DEPENDENCIES = [
        'sales_quotations' => 'sales quotations',
        'sales_orders' => 'sales orders',
        'sales_invoices' => 'sales invoices',
        'sales_credit_notes' => 'sales credit notes',
        'customer_receipts' => 'customer receipts',
    ];

    public function __construct(private AuditLogger $audit) {}

    public function blockers(Customer $customer): array
    {
        return collect(self::DEPENDENCIES)->filter(fn ($label, $table) => DB::table($table)->where('customer_id', $customer->id)->exists())->values()->all();
    }

    public function isDeletable(Customer $customer): bool
    {
        return $this->blockers($customer) === [];
    }

    public function update(Company $company, Customer $customer, array $data, User $user): Customer
    {
        $this->authorize($company, $customer, $user);
        $company->accounts()->findOrFail($data['receivable_account_id']);

        return DB::transaction(function () use ($company, $customer, $data, $user) {
            $old = $customer->toArray();
            $customer->update([...$data, 'updated_by' => $user->id]);
            $this->audit->log('customer.updated', $customer, $company->id, $user->id, $old, $customer->fresh()->toArray());

            return $customer->fresh();
        });
    }

    public function setActive(Company $company, Customer $customer, bool $active, User $user): Customer
    {
        $this->authorize($company, $customer, $user);
        $event = $active ? 'customer.reactivated' : 'customer.deactivated';

        return DB::transaction(function () use ($company, $customer, $active, $user, $event) {
            $old = ['is_active' => $customer->is_active];
            $customer->update(['is_active' => $active, 'updated_by' => $user->id]);
            $this->audit->log($event, $customer, $company->id, $user->id, $old, ['is_active' => $active]);

            return $customer;
        });
    }

    public function delete(Company $company, Customer $customer, User $user, string $confirmation): void
    {
        $this->authorize($company, $customer, $user);
        if (! hash_equals($customer->name, $confirmation)) {
            throw ValidationException::withMessages(['confirmation_name' => 'Enter the exact customer name to confirm permanent deletion.']);
        }
        DB::transaction(function () use ($company, $customer) {
            $locked = Customer::withTrashed()->lockForUpdate()->where('company_id', $company->id)->findOrFail($customer->id);
            $blockers = $this->blockers($locked);
            if ($blockers !== []) {
                throw ValidationException::withMessages(['customer' => 'This customer cannot be deleted because it is referenced by '.implode(', ', $blockers).'. Deactivate the customer instead.']);
            } DB::table('audit_logs')->where('company_id', $company->id)->where('auditable_type', Customer::class)->where('auditable_id', $locked->id)->delete();
            $locked->forceDelete();
        });
    }

    private function authorize(Company $company, Customer $customer, User $user): void
    {
        abort_unless($user->companies()->whereKey($company->id)->exists() && $customer->company_id === $company->id, 404);
    }
}

<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use App\Services\SupplierMaintenanceService;
use Illuminate\Database\Eloquent\Model;

final class SupplierImportAdapter extends AbstractAdapter
{
    public function __construct(private SupplierMaintenanceService $suppliers) {}

    public function type(): string
    {
        return 'suppliers';
    }

    public function fields(): array
    {
        return ['code' => ['label' => 'Supplier Code', 'required' => true, 'aliases' => ['supplier_code', 'vendor_code']], 'name' => ['label' => 'Supplier Name', 'required' => true, 'aliases' => ['supplier_name', 'vendor']], 'legal_name' => ['label' => 'Legal Name', 'required' => false, 'aliases' => []], 'email' => ['label' => 'Email', 'required' => false, 'aliases' => ['email_address']], 'phone' => ['label' => 'Phone', 'required' => false, 'aliases' => ['telephone']], 'address' => ['label' => 'Address', 'required' => false, 'aliases' => []], 'payment_terms_days' => ['label' => 'Payment Terms Days', 'required' => false, 'aliases' => ['terms']], 'credit_limit' => ['label' => 'Credit Limit', 'required' => false, 'aliases' => []], 'active' => ['label' => 'Active', 'required' => false, 'aliases' => ['status']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        if ($company->entity_type === 'individual') {
            $errors[] = 'Supplier import is not supported for Individual entities.';
        }
        if (($values['email'] ?? '') && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is invalid.';
        }

        return $this->result($errors, [], $company->suppliers()->withTrashed()->where('code', $values['code'] ?? '')->exists() ? 'exact_duplicate' : 'not_duplicate');
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        return $this->suppliers->create($company, ['code' => $values['code'], 'name' => $values['name'], 'legal_name' => $values['legal_name'] ?: null, 'type' => 'business', 'email' => $values['email'] ?: null, 'phone' => $values['phone'] ?: null, 'address' => $values['address'] ?: null, 'country_id' => $company->country_id, 'currency_id' => $company->base_currency_id, 'payment_terms_days' => (int) ($values['payment_terms_days'] ?: 0), 'credit_limit' => $values['credit_limit'] ?: '0', 'payable_account_id' => $company->accounts()->where('code', '2000')->value('id'), 'default_purchase_tax_code_id' => null, 'is_active' => $this->bool($values['active'] ?? null), 'notes' => 'Imported through Arua Import & Export'], $user);
    }
}

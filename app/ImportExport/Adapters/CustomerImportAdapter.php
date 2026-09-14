<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Database\Eloquent\Model;

final class CustomerImportAdapter extends AbstractAdapter
{
    public function __construct(private SalesService $sales) {}

    public function type(): string
    {
        return 'customers';
    }

    public function fields(): array
    {
        return ['code' => ['label' => 'Customer Code', 'required' => true, 'aliases' => ['customer_code', 'customerid', 'cust_code']], 'name' => ['label' => 'Customer Name', 'required' => true, 'aliases' => ['customer_name', 'custname']], 'legal_name' => ['label' => 'Legal Name', 'required' => false, 'aliases' => []], 'email' => ['label' => 'Email', 'required' => false, 'aliases' => ['email_address']], 'phone' => ['label' => 'Phone', 'required' => false, 'aliases' => ['telephone']], 'billing_address' => ['label' => 'Billing Address', 'required' => false, 'aliases' => ['address']], 'payment_terms_days' => ['label' => 'Payment Terms Days', 'required' => false, 'aliases' => ['terms']], 'credit_limit' => ['label' => 'Credit Limit', 'required' => false, 'aliases' => []], 'active' => ['label' => 'Active', 'required' => false, 'aliases' => ['status']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        if (($values['email'] ?? '') && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is invalid.';
        }
        if (($values['credit_limit'] ?? '') !== '' && ! is_numeric($values['credit_limit'])) {
            $errors[] = 'Credit Limit must be numeric.';
        }

        return $this->result($errors, [], $company->customers()->withTrashed()->where('code', $values['code'] ?? '')->exists() ? 'exact_duplicate' : 'not_duplicate');
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        return $this->sales->createCustomer($company, ['code' => $values['code'], 'name' => $values['name'], 'legal_name' => $values['legal_name'] ?: null, 'type' => 'business', 'email' => $values['email'] ?: null, 'phone' => $values['phone'] ?: null, 'billing_address' => $values['billing_address'] ?: null, 'shipping_address' => null, 'country_id' => $company->country_id, 'currency_id' => $company->base_currency_id, 'tax_identifiers' => [], 'payment_terms_days' => (int) ($values['payment_terms_days'] ?: 0), 'credit_limit' => $values['credit_limit'] ?: '0', 'receivable_account_id' => $company->accounts()->where('code', '1100')->value('id'), 'default_sales_tax_code_id' => null, 'is_active' => $this->bool($values['active'] ?? null), 'notes' => 'Imported through Arua Import & Export'], $user);
    }
}

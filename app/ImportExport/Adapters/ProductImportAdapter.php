<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Database\Eloquent\Model;

final class ProductImportAdapter extends AbstractAdapter
{
    public function __construct(private SalesService $sales) {}

    public function type(): string
    {
        return 'products';
    }

    public function fields(): array
    {
        return ['code' => ['label' => 'Code / SKU', 'required' => true, 'aliases' => ['sku', 'item_code', 'product_code']], 'name' => ['label' => 'Name', 'required' => true, 'aliases' => ['item_name', 'product_name']], 'description' => ['label' => 'Description', 'required' => false, 'aliases' => []], 'type' => ['label' => 'Type', 'required' => true, 'aliases' => ['item_type']], 'unit' => ['label' => 'Unit', 'required' => false, 'aliases' => ['uom']], 'sales_price' => ['label' => 'Sales Price', 'required' => true, 'aliases' => ['price']], 'purchase_price' => ['label' => 'Purchase Price', 'required' => false, 'aliases' => ['cost']], 'revenue_account' => ['label' => 'Sales Account', 'required' => false, 'aliases' => ['revenue_account']], 'expense_account' => ['label' => 'Purchase Account', 'required' => false, 'aliases' => ['expense_account']], 'default_sales_tax_code' => ['label' => 'Default Sales Tax Code', 'required' => false, 'aliases' => ['sales_tax_code']], 'default_purchase_tax_code' => ['label' => 'Default Purchase Tax Code', 'required' => false, 'aliases' => ['purchase_tax_code']], 'active' => ['label' => 'Active', 'required' => false, 'aliases' => ['status']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        if (! in_array($values['type'] ?? null, ['product', 'service'], true)) {
            $errors[] = 'Type must be product or service.';
        }
        foreach (['sales_price', 'purchase_price'] as $field) {
            if (($values[$field] ?? '') !== '' && (! is_numeric($values[$field]) || bccomp((string) $values[$field], '0', 4) < 0)) {
                $errors[] = $this->fields()[$field]['label'].' must be non-negative.';
            }
        }
        foreach (['default_sales_tax_code', 'default_purchase_tax_code'] as $field) {
            if (($values[$field] ?? '') && ! $company->taxCodes()->where('code', $values[$field])->where('is_active', true)->exists()) {
                $errors[] = $this->fields()[$field]['label'].' is not valid for this entity.';
            }
        }

        return $this->result($errors, [], $company->items()->withTrashed()->where('code', $values['code'] ?? '')->exists() ? 'exact_duplicate' : 'not_duplicate');
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        return $this->sales->createItem($company, ['code' => $values['code'], 'name' => $values['name'], 'description' => $values['description'] ?: null, 'type' => $values['type'], 'unit' => $values['unit'] ?: 'each', 'sales_price' => $values['sales_price'], 'purchase_price' => $values['purchase_price'] ?: null, 'revenue_account_id' => $this->account($company, $values['revenue_account'] ?: '4000', ['revenue', 'income']), 'expense_account_id' => $this->account($company, $values['expense_account'] ?: '5000', ['expense', 'asset']), 'purchase_description' => null, 'tax_category' => null, 'default_sales_tax_code_id' => ($values['default_sales_tax_code'] ?? '') ? $company->taxCodes()->where('code', $values['default_sales_tax_code'])->value('id') : null, 'default_purchase_tax_code_id' => ($values['default_purchase_tax_code'] ?? '') ? $company->taxCodes()->where('code', $values['default_purchase_tax_code'])->value('id') : null, 'is_active' => $this->bool($values['active'] ?? null)], $user);
    }
}

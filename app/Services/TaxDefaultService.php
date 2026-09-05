<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Database\Eloquent\Model;

final class TaxDefaultService
{
    public function sales(Company $company, array $line, Model $customer, string $date): ?int
    {
        return $this->resolve($company, $line['tax_code_id'] ?? null, $this->itemDefault($company, $line['item_id'] ?? null, 'default_sales_tax_code_id'), $customer->default_sales_tax_code_id, $company->taxSetting()->value('default_sales_tax_code_id'), $date);
    }

    public function purchase(Company $company, array $line, Model $supplier, string $date): ?int
    {
        return $this->resolve($company, $line['tax_code_id'] ?? null, $this->itemDefault($company, $line['item_id'] ?? null, 'default_purchase_tax_code_id'), $supplier->default_purchase_tax_code_id, $company->taxSetting()->value('default_purchase_tax_code_id'), $date);
    }

    public function validate(Company $company, ?int $taxCodeId): void
    {
        if ($taxCodeId) {
            $company->taxCodes()->where('country_id', $company->country_id)->where('is_active', true)->findOrFail($taxCodeId);
        }
    }

    private function resolve(Company $company, mixed $explicit, mixed $item, mixed $counterparty, mixed $entity, string $date): ?int
    {
        $id = collect([$explicit, $item, $counterparty, $entity])->first(fn ($value) => ! empty($value));
        if (! $id) {
            return null;
        }

        return $company->taxCodes()->where('country_id', $company->country_id)->where('is_active', true)->whereDate('effective_from', '<=', $date)->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->findOrFail($id)->id;
    }

    private function itemDefault(Company $company, mixed $itemId, string $field): ?int
    {
        return $itemId ? $company->items()->where('is_active', true)->findOrFail($itemId)->{$field} : null;
    }
}

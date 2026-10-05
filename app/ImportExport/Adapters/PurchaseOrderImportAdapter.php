<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use App\Services\PurchaseService;
use Illuminate\Database\Eloquent\Model;

final class PurchaseOrderImportAdapter extends AbstractAdapter
{
    public function __construct(private PurchaseService $purchases) {}

    public function type(): string
    {
        return 'purchase_orders';
    }

    public function fields(): array
    {
        return ['order_ref' => ['label' => 'Purchase Order Source ID', 'required' => true, 'aliases' => ['order', 'reference']], 'supplier' => ['label' => 'Supplier Code', 'required' => true, 'aliases' => ['supplier_code']], 'order_date' => ['label' => 'Order Date', 'required' => true, 'aliases' => ['date']], 'expected_date' => ['label' => 'Expected Date', 'required' => false, 'aliases' => []], 'branch' => ['label' => 'Branch Code', 'required' => false, 'aliases' => ['branch_code']], 'item' => ['label' => 'Product / Service Code', 'required' => false, 'aliases' => ['sku']], 'expense_account' => ['label' => 'Expense / Asset Account', 'required' => true, 'aliases' => ['account']], 'description' => ['label' => 'Description', 'required' => true, 'aliases' => []], 'quantity' => ['label' => 'Quantity', 'required' => true, 'aliases' => ['qty']], 'unit_price' => ['label' => 'Unit Price', 'required' => true, 'aliases' => ['price']], 'discount' => ['label' => 'Discount Amount', 'required' => false, 'aliases' => ['discount_amount']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        if (! $company->suppliers()->where('code', $values['supplier'] ?? '')->where('is_active', true)->exists()) {
            $errors[] = 'Supplier Code is invalid or inactive.';
        }
        if (! $this->account($company, $values['expense_account'] ?? null, ['expense', 'asset'])) {
            $errors[] = 'Expense / Asset Account is invalid.';
        }
        if (($values['item'] ?? '') && ! $company->items()->where('code', $values['item'])->where('is_active', true)->exists()) {
            $errors[] = 'Product / Service Code is invalid or inactive.';
        }
        foreach (['quantity', 'unit_price', 'discount'] as $field) {
            if (($values[$field] ?? '') !== '' && ! is_numeric($values[$field])) {
                $errors[] = $this->fields()[$field]['label'].' must be numeric.';
            }
        }

        return $this->result($errors, [], $company->purchaseOrders()->where('supplier_reference', $values['order_ref'] ?? '')->exists() ? 'exact_duplicate' : 'not_duplicate');
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        throw new \LogicException('Purchase Orders are grouped by ImportEngine.');
    }

    public function importGroup(Company $company, array $rows, array $options, User $user): Model
    {
        $first = $rows[0];
        $branch = empty($first['branch']) ? ($options['default_branch_id'] ?? null) : $company->branches()->where('code', $first['branch'])->value('id');

        return $this->purchases->create($company, 'orders', ['supplier_id' => $company->suppliers()->where('code', $first['supplier'])->value('id'), 'branch_id' => $branch, 'order_date' => $first['order_date'], 'expected_date' => $first['expected_date'] ?: null, 'supplier_reference' => $first['order_ref'], 'notes' => 'Imported through Arua Import & Export', 'lines' => array_map(fn ($row) => ['item_id' => empty($row['item']) ? null : $company->items()->where('code', $row['item'])->value('id'), 'expense_account_id' => $this->account($company, $row['expense_account'], ['expense', 'asset']), 'description' => $row['description'], 'quantity' => $row['quantity'], 'unit_price' => $row['unit_price'], 'discount' => $row['discount'] ?: '0'], $rows)], $user);
    }
}

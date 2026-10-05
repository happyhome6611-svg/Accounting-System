<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use App\Services\SalesService;
use Illuminate\Database\Eloquent\Model;

final class SalesOrderImportAdapter extends AbstractAdapter
{
    public function __construct(private SalesService $sales) {}

    public function type(): string
    {
        return 'sales_orders';
    }

    public function fields(): array
    {
        return ['order_ref' => ['label' => 'Sales Order Source ID', 'required' => true, 'aliases' => ['order', 'reference']], 'customer' => ['label' => 'Customer Code', 'required' => true, 'aliases' => ['customer_code']], 'order_date' => ['label' => 'Order Date', 'required' => true, 'aliases' => ['date']], 'branch' => ['label' => 'Branch Code', 'required' => false, 'aliases' => ['branch_code']], 'item' => ['label' => 'Product / Service Code', 'required' => false, 'aliases' => ['sku']], 'revenue_account' => ['label' => 'Revenue Account', 'required' => true, 'aliases' => ['account']], 'description' => ['label' => 'Description', 'required' => true, 'aliases' => []], 'quantity' => ['label' => 'Quantity', 'required' => true, 'aliases' => ['qty']], 'unit_price' => ['label' => 'Unit Price', 'required' => true, 'aliases' => ['price']], 'discount' => ['label' => 'Discount Amount', 'required' => false, 'aliases' => ['discount_amount']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        if (! $company->customers()->where('code', $values['customer'] ?? '')->where('is_active', true)->exists()) {
            $errors[] = 'Customer Code is invalid or inactive.';
        }
        if (! $this->account($company, $values['revenue_account'] ?? null, ['revenue', 'income'])) {
            $errors[] = 'Revenue Account is invalid.';
        }
        if (($values['item'] ?? '') && ! $company->items()->where('code', $values['item'])->where('is_active', true)->exists()) {
            $errors[] = 'Product / Service Code is invalid or inactive.';
        }
        foreach (['quantity', 'unit_price', 'discount'] as $field) {
            if (($values[$field] ?? '') !== '' && ! is_numeric($values[$field])) {
                $errors[] = $this->fields()[$field]['label'].' must be numeric.';
            }
        }

        return $this->result($errors, [], $company->salesOrders()->where('customer_reference', $values['order_ref'] ?? '')->exists() ? 'exact_duplicate' : 'not_duplicate');
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        throw new \LogicException('Sales Orders are grouped by ImportEngine.');
    }

    public function importGroup(Company $company, array $rows, array $options, User $user): Model
    {
        $first = $rows[0];
        $branch = empty($first['branch']) ? ($options['default_branch_id'] ?? null) : $company->branches()->where('code', $first['branch'])->value('id');

        return $this->sales->createOrder($company, ['customer_id' => $company->customers()->where('code', $first['customer'])->value('id'), 'branch_id' => $branch, 'order_date' => $first['order_date'], 'customer_reference' => $first['order_ref'], 'notes' => 'Imported through Arua Import & Export', 'status' => 'draft', 'lines' => array_map(fn ($row) => ['item_id' => empty($row['item']) ? null : $company->items()->where('code', $row['item'])->value('id'), 'revenue_account_id' => $this->account($company, $row['revenue_account'], ['revenue', 'income']), 'description' => $row['description'], 'quantity' => $row['quantity'], 'unit_price' => $row['unit_price'], 'discount' => $row['discount'] ?: '0'], $rows)], $user);
    }
}

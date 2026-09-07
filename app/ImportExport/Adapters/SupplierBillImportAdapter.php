<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use App\Services\PurchaseService;
use App\Services\TaxCalculationService;
use Illuminate\Database\Eloquent\Model;

final class SupplierBillImportAdapter extends AbstractAdapter
{
    public function __construct(private PurchaseService $purchases, private TaxCalculationService $tax) {}

    public function type(): string
    {
        return 'supplier_bills';
    }

    public function fields(): array
    {
        return ['bill_ref' => ['label' => 'Bill Number / Source ID', 'required' => true, 'aliases' => ['bill_number', 'bill', 'document']], 'supplier' => ['label' => 'Supplier Code', 'required' => true, 'aliases' => ['supplier_code', 'vendor']], 'bill_date' => ['label' => 'Bill Date', 'required' => true, 'aliases' => ['date']], 'due_date' => ['label' => 'Due Date', 'required' => true, 'aliases' => []], 'branch' => ['label' => 'Branch Code', 'required' => false, 'aliases' => ['branch_code']], 'item' => ['label' => 'Product / Service Code', 'required' => false, 'aliases' => ['item', 'sku']], 'expense_account' => ['label' => 'Expense / Asset Account', 'required' => true, 'aliases' => ['account']], 'description' => ['label' => 'Description', 'required' => true, 'aliases' => []], 'quantity' => ['label' => 'Quantity', 'required' => true, 'aliases' => ['qty']], 'unit_price' => ['label' => 'Unit Price', 'required' => true, 'aliases' => ['price']], 'discount' => ['label' => 'Discount Amount', 'required' => false, 'aliases' => ['discount_amount']], 'tax_code' => ['label' => 'Tax Code', 'required' => false, 'aliases' => []], 'tax_inclusive' => ['label' => 'Tax Inclusive', 'required' => false, 'aliases' => []], 'source_tax' => ['label' => 'Source Tax Amount', 'required' => false, 'aliases' => ['tax_amount']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        $warnings = [];
        if ($company->entity_type === 'individual') {
            $errors[] = 'Supplier Bills are not supported for Individual entities.';
        }
        if (! $company->suppliers()->where('code', $values['supplier'] ?? '')->where('is_active', true)->exists()) {
            $errors[] = 'Supplier Code is invalid or inactive.';
        }
        if (! $this->account($company, $values['expense_account'] ?? null, ['expense', 'asset'])) {
            $errors[] = 'Expense / Asset Account is invalid.';
        }
        if (($values['tax_code'] ?? '') && ! $company->taxCodes()->where('code', $values['tax_code'])->where('is_active', true)->exists()) {
            $errors[] = 'Tax Code is invalid or inactive.';
        }
        foreach (['quantity', 'unit_price', 'discount'] as $field) {
            if (($values[$field] ?? '') !== '' && ! is_numeric($values[$field])) {
                $errors[] = $this->fields()[$field]['label'].' must be numeric.';
            }
        }
        if (($values['tax_code'] ?? '') && ! $errors) {
            try {
                $amount = bcsub(bcmul((string) $values['quantity'], (string) $values['unit_price'], 4), (string) ($values['discount'] ?: 0), 4);
                $codeId = $company->taxCodes()->where('code', $values['tax_code'])->value('id');
                $result = $this->tax->calculate($company, $codeId, $values['bill_date'], $amount, $this->bool($values['tax_inclusive'] ?? null, false));
                if (($values['source_tax'] ?? '') !== '' && (! is_numeric($values['source_tax']) || bccomp((string) $values['source_tax'], $result['tax'], 4) !== 0)) {
                    $warnings[] = 'Source Tax Amount differs from Arua resolved tax '.$result['tax'].' and will not override it.';
                }
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        } elseif (($values['source_tax'] ?? '') !== '') {
            $warnings[] = 'Source Tax Amount is reference-only; no Tax Code was mapped.';
        }

        return $this->result($errors, $warnings, $company->supplierBills()->where('supplier_reference', $values['bill_ref'] ?? '')->exists() ? 'exact_duplicate' : 'not_duplicate');
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        throw new \LogicException('Supplier Bills are grouped by ImportEngine.');
    }

    public function importGroup(Company $company, array $rows, array $options, User $user): Model
    {
        $first = $rows[0];
        $branchId = empty($first['branch']) ? ($options['default_branch_id'] ?? null) : $company->branches()->where('code', $first['branch'])->value('id');
        $bill = $this->purchases->create($company, 'bills', ['supplier_id' => $company->suppliers()->where('code', $first['supplier'])->value('id'), 'branch_id' => $branchId, 'bill_date' => $first['bill_date'], 'due_date' => $first['due_date'], 'supplier_reference' => $first['bill_ref'], 'notes' => 'Imported through Arua Import & Export', 'lines' => array_map(fn ($row) => ['item_id' => empty($row['item']) ? null : $company->items()->where('code', $row['item'])->value('id'), 'expense_account_id' => $this->account($company, $row['expense_account'], ['expense', 'asset']), 'description' => $row['description'], 'quantity' => $row['quantity'], 'unit_price' => $row['unit_price'], 'discount' => $row['discount'] ?: '0', 'tax_code_id' => empty($row['tax_code']) ? null : $company->taxCodes()->where('code', $row['tax_code'])->value('id'), 'tax_inclusive' => $this->bool($row['tax_inclusive'] ?? null, false)], $rows)], $user);
        if (($options['posting_mode'] ?? 'draft') === 'post') {
            $this->purchases->post($company, 'bills', $bill, $user);
        }

        return $bill->fresh();
    }
}

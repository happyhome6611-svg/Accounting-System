<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use App\Services\SalesService;
use App\Services\TaxCalculationService;
use Illuminate\Database\Eloquent\Model;

final class SalesInvoiceImportAdapter extends AbstractAdapter
{
    public function __construct(private SalesService $sales, private TaxCalculationService $tax) {}

    public function type(): string
    {
        return 'sales_invoices';
    }

    public function fields(): array
    {
        return ['invoice_ref' => ['label' => 'Invoice Number / Source ID', 'required' => true, 'aliases' => ['invoice_number', 'invoice', 'document']], 'customer' => ['label' => 'Customer Code', 'required' => true, 'aliases' => ['customer_code']], 'invoice_date' => ['label' => 'Invoice Date', 'required' => true, 'aliases' => ['date']], 'due_date' => ['label' => 'Due Date', 'required' => true, 'aliases' => []], 'branch' => ['label' => 'Branch Code', 'required' => false, 'aliases' => ['branch_code']], 'item' => ['label' => 'Product / Service Code', 'required' => false, 'aliases' => ['item', 'sku']], 'revenue_account' => ['label' => 'Revenue Account', 'required' => true, 'aliases' => ['account']], 'description' => ['label' => 'Description', 'required' => true, 'aliases' => []], 'quantity' => ['label' => 'Quantity', 'required' => true, 'aliases' => ['qty']], 'unit_price' => ['label' => 'Unit Price', 'required' => true, 'aliases' => ['price']], 'discount' => ['label' => 'Discount Amount', 'required' => false, 'aliases' => ['discount_amount']], 'tax_code' => ['label' => 'Tax Code', 'required' => false, 'aliases' => []], 'tax_inclusive' => ['label' => 'Tax Inclusive', 'required' => false, 'aliases' => []], 'source_tax' => ['label' => 'Source Tax Amount', 'required' => false, 'aliases' => ['tax_amount']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        $warnings = [];
        if (! $company->customers()->where('code', $values['customer'] ?? '')->where('is_active', true)->exists()) {
            $errors[] = 'Customer Code is invalid or inactive.';
        }
        if (! $this->account($company, $values['revenue_account'] ?? null, ['revenue', 'income'])) {
            $errors[] = 'Revenue Account is invalid.';
        }
        if (($values['item'] ?? '') && ! $company->items()->where('code', $values['item'])->where('is_active', true)->exists()) {
            $errors[] = 'Product / Service Code is invalid or inactive.';
        }
        if (($values['tax_code'] ?? '') && ! $company->taxCodes()->where('code', $values['tax_code'])->where('is_active', true)->exists()) {
            $errors[] = 'Tax Code is invalid or inactive.';
        }
        foreach (['quantity', 'unit_price', 'discount'] as $field) {
            if (($values[$field] ?? '') !== '' && ! is_numeric($values[$field])) {
                $errors[] = $this->fields()[$field]['label'].' must be numeric.';
            }
        }
        if ($company->entity_type === 'individual' && ($values['branch'] ?? '')) {
            $errors[] = 'Individual imports cannot specify a branch.';
        }
        if (($values['tax_code'] ?? '') && ! $errors) {
            try {
                $amount = bcsub(bcmul((string) $values['quantity'], (string) $values['unit_price'], 4), (string) ($values['discount'] ?: 0), 4);
                $codeId = $company->taxCodes()->where('code', $values['tax_code'])->value('id');
                $result = $this->tax->calculate($company, $codeId, $values['invoice_date'], $amount, $this->bool($values['tax_inclusive'] ?? null, false));
                if (($values['source_tax'] ?? '') !== '' && (! is_numeric($values['source_tax']) || bccomp((string) $values['source_tax'], $result['tax'], 4) !== 0)) {
                    $warnings[] = 'Source Tax Amount differs from Arua resolved tax '.$result['tax'].' and will not override it.';
                }
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        } elseif (($values['source_tax'] ?? '') !== '') {
            $warnings[] = 'Source Tax Amount is reference-only; no Tax Code was mapped.';
        }
        $duplicate = $company->salesInvoices()->where('customer_reference', $values['invoice_ref'] ?? '')->exists() ? 'exact_duplicate' : 'not_duplicate';

        return $this->result($errors, $warnings, $duplicate);
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        throw new \LogicException('Sales Invoices are grouped by ImportEngine.');
    }

    public function importGroup(Company $company, array $rows, array $options, User $user): Model
    {
        $first = $rows[0];
        $branchId = empty($first['branch']) ? ($options['default_branch_id'] ?? null) : $company->branches()->where('code', $first['branch'])->value('id');
        $invoice = $this->sales->createInvoice($company, ['customer_id' => $company->customers()->where('code', $first['customer'])->value('id'), 'branch_id' => $branchId, 'invoice_date' => $first['invoice_date'], 'due_date' => $first['due_date'], 'customer_reference' => $first['invoice_ref'], 'notes' => 'Imported through Arua Import & Export', 'lines' => array_map(fn ($row) => ['item_id' => empty($row['item']) ? null : $company->items()->where('code', $row['item'])->value('id'), 'revenue_account_id' => $this->account($company, $row['revenue_account'], ['revenue', 'income']), 'description' => $row['description'], 'quantity' => $row['quantity'], 'unit_price' => $row['unit_price'], 'discount' => $row['discount'] ?: '0', 'tax_code_id' => empty($row['tax_code']) ? null : $company->taxCodes()->where('code', $row['tax_code'])->value('id'), 'tax_inclusive' => $this->bool($row['tax_inclusive'] ?? null, false)], $rows)], $user);
        if (($options['posting_mode'] ?? 'draft') === 'post') {
            $this->sales->postInvoice($invoice, $user);
        }

        return $invoice->fresh();
    }
}

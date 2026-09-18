<?php

namespace App\ImportExport;

use App\Models\Company;
use App\Models\ExportLog;
use App\Models\User;
use App\Services\AccountingReportService;
use App\Services\AuditLogger;
use App\Services\BankingService;
use App\Services\TaxReportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ExportService
{
    public function __construct(private AccountingReportService $accounting, private TaxReportService $tax, private BankingService $banking, private AuditLogger $audit) {}

    public function types(): array
    {
        return ['accounting_entity' => 'Accounting Entity', 'chart_of_accounts' => 'Chart of Accounts', 'customers' => 'Customers', 'suppliers' => 'Suppliers', 'products' => 'Products / Services', 'general_ledger' => 'General Ledger', 'trial_balance' => 'Trial Balance', 'profit_loss' => 'Profit & Loss', 'balance_sheet' => 'Balance Sheet', 'journal_entries' => 'Journal Entries', 'sales_invoices' => 'Sales Invoices', 'sales_credits' => 'Sales Credits', 'customer_receipts' => 'Customer Receipts', 'supplier_bills' => 'Supplier Bills', 'supplier_credits' => 'Supplier Credits', 'supplier_payments' => 'Supplier Payments', 'bank_register' => 'Bank / Cash Register', 'statement_imports' => 'Statement Import History', 'reconciliations' => 'Reconciliation Summaries', 'tax_register' => 'Tax Transaction Register', 'output_tax' => 'Output Tax', 'input_tax' => 'Input Tax', 'tax_summary' => 'Tax Summary', 'tax_periods' => 'Tax Periods'];
    }

    public function generate(Company $company, string $type, string $format, array $filters, User $user): array
    {
        abort_unless($user->companies()->whereKey($company->id)->exists(), 404);
        if (! isset($this->types()[$type]) || ! in_array($format, ['csv', 'xlsx'], true)) {
            abort(404);
        }
        $filters = $this->scopeFilters($company, $filters);
        [$headers, $rows] = $this->dataset($company, $type, $filters);
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Arua Export');
        foreach ([$headers, ...$rows] as $rowIndex => $row) {
            foreach (array_values($row) as $columnIndex => $value) {
                $cell = $sheet->getCell([$columnIndex + 1, $rowIndex + 1]);
                if (is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(\.\d+)?$/', $value))) {
                    $cell->setValueExplicit((string) $value, DataType::TYPE_NUMERIC);
                } else {
                    $cell->setValueExplicit($this->safeText((string) $value), DataType::TYPE_STRING);
                }
            }
        }
        $slug = preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($company->entity_label));
        $filename = 'arua-'.str_replace('_', '-', $type).'-'.trim($slug, '-').'-'.now()->format('Y-m-d').'.'.$format;
        $path = storage_path('app/private/exports/'.bin2hex(random_bytes(12)).'.'.$format);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }
        $writer = $format === 'csv' ? new Csv($spreadsheet) : new Xlsx($spreadsheet);
        if ($writer instanceof Csv) {
            $writer->setDelimiter(',');
            $writer->setEnclosure('"');
            $writer->setUseBOM(true);
        }
        $writer->save($path);
        $log = ExportLog::create(['company_id' => $company->id, 'country_id' => $company->country_id, 'branch_id' => $filters['branch_id'] ?? null, 'data_type' => $type, 'format' => $format, 'filters' => $filters, 'filename' => $filename, 'row_count' => count($rows), 'generated_by' => $user->id, 'generated_at' => now()]);
        $this->audit->log('export.generated', $log, $company->id, $user->id, null, ['type' => $type, 'format' => $format, 'rows' => count($rows)]);

        return compact('path', 'filename');
    }

    private function dataset(Company $c, string $type, array $f): array
    {
        return match ($type) {
            'accounting_entity' => [['Entity Name', 'Entity Type', 'Legal Name', 'Individual Name', 'Trading Name', 'Country / Jurisdiction', 'Base Currency', 'Timezone', 'Financial Year Start', 'Financial Year End', 'Address', 'Email', 'Phone', 'Active'], [[$c->entity_label, $c->entity_type, $c->legal_name, $c->individual_name, $c->trading_name, $c->country->code, $c->baseCurrency->code, $c->timezone, $c->financialYears()->where('is_current', true)->value('starts_on'), $c->financialYears()->where('is_current', true)->value('ends_on'), $c->address, $c->email, $c->phone, $c->is_active ? 'Yes' : 'No']]],
            'chart_of_accounts' => [['Account Code', 'Account Name', 'Type', 'Normal Balance', 'Active'], $c->accounts()->orderBy('code')->get()->map(fn ($r) => [$r->code, $r->name, $r->type, $r->normal_balance, $r->is_active ? 'Yes' : 'No'])->all()],
            'customers' => [['Customer Code', 'Customer Name', 'Email', 'Phone', 'Billing Address', 'Payment Terms Days', 'Credit Limit', 'Active'], $c->customers()->orderBy('code')->get()->map(fn ($r) => [$r->code, $r->name, $r->email, $r->phone, $r->billing_address, $r->payment_terms_days, $r->credit_limit, $r->is_active ? 'Yes' : 'No'])->all()],
            'suppliers' => [['Supplier Code', 'Supplier Name', 'Email', 'Phone', 'Address', 'Payment Terms Days', 'Credit Limit', 'Active'], $c->suppliers()->orderBy('code')->get()->map(fn ($r) => [$r->code, $r->name, $r->email, $r->phone, $r->address, $r->payment_terms_days, $r->credit_limit, $r->is_active ? 'Yes' : 'No'])->all()],
            'products' => [['Code / SKU', 'Name', 'Description', 'Type', 'Unit', 'Sales Price', 'Purchase Price', 'Active'], $c->items()->orderBy('code')->get()->map(fn ($r) => [$r->code, $r->name, $r->description, $r->type, $r->unit, $r->sales_price, $r->purchase_price, $r->is_active ? 'Yes' : 'No'])->all()],
            'general_ledger' => $this->generalLedger($c, $f),
            'trial_balance' => $this->trialBalance($c, $f),
            'profit_loss' => $this->summaryRows(['Revenue', 'Expenses', 'Net Profit / Loss'], array_values($this->accounting->profitAndLoss($c, $f['from'] ?? null, $f['to'] ?? null, $f['branch_id'] ?? null, $f['financial_year_id'] ?? null))),
            'balance_sheet' => $this->summaryRows(['Assets', 'Liabilities', 'Equity', 'Current Earnings', 'Liabilities and Equity'], array_values($this->accounting->balanceSheet($c, $f['to'] ?? null, $f['branch_id'] ?? null, $f['financial_year_id'] ?? null))),
            'journal_entries' => $this->tableRows($c, 'journal_entries', ['journal_number', 'transaction_date', 'reference', 'description', 'journal_type', 'status'], $f),
            'sales_invoices' => $this->tableRows($c, 'sales_invoices', ['invoice_number', 'invoice_date', 'due_date', 'customer_reference', 'status', 'subtotal', 'tax_amount', 'total'], $f, 'invoice_date'),
            'sales_credits' => $this->tableRows($c, 'sales_credit_notes', ['credit_note_number', 'credit_note_date', 'status', 'total'], $f, 'credit_note_date'),
            'customer_receipts' => $this->tableRows($c, 'customer_receipts', ['receipt_number', 'receipt_date', 'payment_method', 'reference', 'amount', 'status'], $f, 'receipt_date'),
            'supplier_bills' => $this->tableRows($c, 'supplier_bills', ['bill_number', 'bill_date', 'due_date', 'supplier_reference', 'status', 'subtotal', 'tax_amount', 'total'], $f, 'bill_date'),
            'supplier_credits' => $this->tableRows($c, 'supplier_credits', ['credit_number', 'credit_date', 'supplier_reference', 'status', 'total'], $f, 'credit_date'),
            'supplier_payments' => $this->tableRows($c, 'supplier_payments', ['payment_number', 'payment_date', 'reference', 'amount', 'status'], $f, 'payment_date'),
            'statement_imports' => $this->tableRows($c, 'bank_statement_imports', ['file_name', 'row_count', 'imported_count', 'duplicate_count', 'error_count', 'status', 'imported_at'], $f),
            'reconciliations' => $this->tableRows($c, 'bank_reconciliations', ['statement_start_date', 'statement_end_date', 'statement_closing_balance', 'book_balance', 'difference', 'status'], $f, 'statement_end_date'),
            'bank_register' => $this->bankRegister($c, $f),
            'tax_register' => $this->taxRegister($c, $f),
            'output_tax' => $this->taxBreakdown($c, 'output', $f),
            'input_tax' => $this->taxBreakdown($c, 'input', $f),
            'tax_summary' => $this->summaryRows(['Output Tax', 'Input Tax', 'Adjustments', 'Net', 'Output GL', 'Input GL', 'Output Difference', 'Input Difference'], array_values($this->tax->summary($c, $f))),
            'tax_periods' => $this->tableRows($c, 'tax_periods', ['starts_on', 'ends_on', 'status', 'prepared_at', 'filed_at'], $f),
            default => throw new \LogicException('Unsupported export type.'),
        };
    }

    private function scopeFilters(Company $c, array $f): array
    {
        $scopes = ['branch_id' => 'branches', 'financial_year_id' => 'financialYears', 'account_id' => 'accounts', 'customer_id' => 'customers', 'supplier_id' => 'suppliers', 'tax_registration_id' => 'taxRegistrations', 'tax_code_id' => 'taxCodes', 'bank_account_id' => 'bankAccounts'];
        foreach ($scopes as $key => $relation) {
            if (! empty($f[$key])) {
                $c->{$relation}()->findOrFail((int) $f[$key]);
            }
        }
        if (! empty($f['tax_period_id'])) {
            abort_unless(DB::table('tax_periods')->where('company_id', $c->id)->where('id', $f['tax_period_id'])->exists(), 404);
        }
        if (! empty($f['accounting_period_id'])) {
            abort_unless(DB::table('accounting_periods')->where('company_id', $c->id)->where('id', $f['accounting_period_id'])->exists(), 404);
        }
        if (! $c->supportsBranches() && ! empty($f['branch_id'])) {
            abort(404);
        }

        return array_filter($f, fn ($value) => $value !== null && $value !== '');
    }

    private function generalLedger(Company $c, array $f): array
    {
        if (empty($f['account_id'])) {
            throw ValidationException::withMessages(['account_id' => 'Select an Account for General Ledger export.']);
        }
        $rows = $this->accounting->generalLedger($c, (int) $f['account_id'], $f['from'] ?? null, $f['to'] ?? null, $f['branch_id'] ?? null, $f['financial_year_id'] ?? null);

        return [['Date', 'Journal Number', 'Reference', 'Description', 'Debit', 'Credit', 'Running Balance'], $rows->map(fn ($r) => [$r->transaction_date, $r->journal_number, $r->reference, $r->description, $r->debit, $r->credit, $r->running_balance])->all()];
    }

    private function trialBalance(Company $c, array $f): array
    {
        $report = $this->accounting->trialBalance($c, $f['to'] ?? null, $f['branch_id'] ?? null, $f['financial_year_id'] ?? null);

        return [['Account Code', 'Account Name', 'Debit', 'Credit'], $report['rows']->map(fn ($r) => [$r->code, $r->name, $r->debit_balance, $r->credit_balance])->all()];
    }

    private function summaryRows(array $labels, array $values): array
    {
        return [['Measure', 'Amount'], array_map(fn ($label, $index) => [$label, $values[$index] ?? '0.0000'], $labels, array_keys($labels))];
    }

    private function tableRows(Company $c, string $table, array $columns, array $f, ?string $date = null): array
    {
        $q = DB::table($table)->where('company_id', $c->id);
        if (Schema::hasColumn($table, 'branch_id')) {
            $q->when($f['branch_id'] ?? null, fn ($x, $id) => $x->where('branch_id', $id));
        }
        if (Schema::hasColumn($table, 'financial_year_id')) {
            $q->when($f['financial_year_id'] ?? null, fn ($x, $id) => $x->where('financial_year_id', $id));
        }
        if ($date) {
            $q->when($f['from'] ?? null, fn ($x, $v) => $x->whereDate($date, '>=', $v))->when($f['to'] ?? null, fn ($x, $v) => $x->whereDate($date, '<=', $v));
        }

        return [array_map(fn ($x) => str($x)->replace('_', ' ')->title()->toString(), $columns), $q->orderBy('id')->get($columns)->map(fn ($r) => array_map(fn ($column) => $r->{$column}, $columns))->all()];
    }

    private function bankRegister(Company $c, array $f): array
    {
        if (empty($f['bank_account_id'])) {
            throw ValidationException::withMessages(['bank_account_id' => 'Select a Bank Account.']);
        }
        $account = $c->bankAccounts()->findOrFail($f['bank_account_id']);
        $rows = $this->banking->register($c, $account, $f['from'] ?? null, $f['to'] ?? null, $f['branch_id'] ?? null, $f['financial_year_id'] ?? null);

        return [['Date', 'Reference', 'Description', 'Money In', 'Money Out', 'Balance', 'Source', 'Reconciliation Status'], $rows->map(fn ($r) => [$r->transaction_date, $r->reference, $r->description, $r->money_in, $r->money_out, $r->balance, $r->source_type, $r->reconciliation_status])->all()];
    }

    private function taxRegister(Company $c, array $f): array
    {
        $rows = $this->tax->register($c, $f);

        return [['Date', 'Tax Code', 'Treatment', 'Net', 'Tax', 'Gross', 'Direction'], $rows->map(fn ($r) => [$r->transaction_date, $r->tax_code_snapshot, $r->treatment_snapshot, $r->net_amount, $r->tax_amount, $r->gross_amount, $r->direction])->all()];
    }

    private function taxBreakdown(Company $c, string $direction, array $f): array
    {
        $rows = $this->tax->breakdown($c, $direction, $f);

        return [['Tax Code', 'Treatment', 'Net', 'Tax', 'Gross'], $rows->map(fn ($r) => [$r->tax_code_snapshot, $r->treatment_snapshot, $r->net_amount, $r->tax_amount, $r->gross_amount])->all()];
    }

    private function safeText(string $value): string
    {
        return preg_match('/^[=+\-@]/', $value) ? "'".$value : $value;
    }
}

<?php

namespace App\Services;

use App\Models\Company;
use App\Models\PurchaseOrder;
use App\Models\SupplierBill;
use App\Models\SupplierCredit;
use App\Models\SupplierPayment;
use App\Models\TransactionTaxLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PurchaseService
{
    public function __construct(private DocumentNumberService $numbers, private BranchService $branches, private FinancialYearResolver $years, private JournalService $journals, private AuditLogger $audit, private AccountingLockService $locks, private TaxCalculationService $tax) {}

    public function create(Company $company, string $type, array $data, User $user): Model
    {
        $this->access($company, $user);
        $this->active($company);

        return match ($type) {
            'orders' => $this->saveLines($company, new PurchaseOrder, $type, $data, $user),'bills' => $this->saveLines($company, new SupplierBill, $type, $data, $user),'credits' => $this->saveLines($company, new SupplierCredit, $type, $data, $user),'payments' => $this->savePayment($company, new SupplierPayment, $data, $user),default => abort(404)
        };
    }

    public function update(Company $company, string $type, Model $document, array $data, User $user): Model
    {
        $this->access($company, $user, $document);
        if ($document->status !== 'draft') {
            throw ValidationException::withMessages(['document' => 'Only Draft documents can be edited.']);
        }

        return $type === 'payments' ? $this->savePayment($company, $document, $data, $user) : $this->saveLines($company, $document, $type, $data, $user);
    }

    private function saveLines(Company $company, Model $document, string $type, array $data, User $user): Model
    {
        $dateField = ['orders' => 'order_date', 'bills' => 'bill_date', 'credits' => 'credit_date'][$type];
        if ($type !== 'orders') {
            $this->locks->assertPostingAllowed($company, $data[$dateField], $user);
        }
        $period = $this->years->resolve($company, $data[$dateField], $data['financial_year_id'] ?? null, $data['accounting_period_id'] ?? null, $type !== 'orders');
        $branch = $this->branches->resolve($company, $data['branch_id'] ?? null);
        $supplier = $company->suppliers()->where('is_active', true)->findOrFail($data['supplier_id']);
        $lines = collect($data['lines'])->map(function ($line) use ($company, $data, $dateField, $type) {
            $net = $this->lineAmount($line);
            if ($type === 'orders' || empty($line['tax_code_id'])) {
                return [...$line, 'line_amount' => $net, 'tax_amount' => '0.0000'];
            }
            $result = $this->tax->calculate($company, (int) $line['tax_code_id'], $data[$dateField], $net, (bool) ($line['tax_inclusive'] ?? false));

            return [...$line, 'line_amount' => $result['net'], 'tax_amount' => $result['tax']];
        })->all();
        $this->validateLines($company, $lines);
        $subtotal = collect($lines)->reduce(fn ($sum, $line) => bcadd($sum, $line['line_amount'], 4), '0.0000');
        $tax = collect($lines)->reduce(fn ($sum, $line) => bcadd($sum, $line['tax_amount'], 4), '0.0000');
        $total = bcadd($subtotal, $tax, 4);
        if ($type === 'credits') {
            $bill = $company->supplierBills()->where('supplier_id', $supplier->id)->whereIn('status', ['posted', 'partially_paid', 'paid'])->lockForUpdate()->findOrFail($data['supplier_bill_id']);
            if (bccomp($total, $bill->amount_due, 4) > 0) {
                throw ValidationException::withMessages(['total' => 'Supplier credit exceeds the bill outstanding balance.']);
            }
        }

        return DB::transaction(function () use ($company, $document, $type, $data, $user, $period, $branch, $supplier, $lines, $subtotal, $tax, $total) {
            $values = ['company_id' => $company->id, 'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'financial_year_id' => $period->financial_year_id, 'currency_id' => $company->base_currency_id, 'status' => 'draft', 'updated_by' => $user->id];
            if ($type !== 'orders') {
                $values['accounting_period_id'] = $period->id;
            }
            foreach (['order_date', 'expected_date', 'bill_date', 'due_date', 'credit_date', 'supplier_reference', 'notes', 'purchase_order_id', 'supplier_bill_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    $values[$field] = $data[$field];
                }
            }
            if ($type === 'orders' || $type === 'bills') {
                $values['subtotal'] = $subtotal;
                if ($type === 'bills') {
                    $values['tax_amount'] = $tax;
                    $values['amount_paid'] = $document->amount_paid ?? 0;
                    $values['amount_credited'] = $document->amount_credited ?? 0;
                }
            }
            if ($type === 'credits') {
                $values['tax_amount'] = $tax;
            }
            $values['total'] = $total;
            if (! $document->exists) {
                [$number,$docType,$prefix] = match ($type) {
                    'orders' => ['purchase_order_number', 'purchase_order', 'PO'],'bills' => ['bill_number', 'supplier_bill', 'BILL'],'credits' => ['credit_number', 'supplier_credit', 'SCN']
                };
                $values += [$number => $this->numbers->next($company, $docType, $prefix), 'created_by' => $user->id];
                $document->fill($values)->save();
            } else {
                $document = $document->newQuery()->lockForUpdate()->findOrFail($document->id);
                if ($document->status !== 'draft') {
                    throw ValidationException::withMessages(['document' => 'Only Draft documents can be edited.']);
                }$document->update($values);
                $document->lines()->delete();
            }
            foreach ($lines as $line) {
                if ($type === 'orders') {
                    unset($line['tax_amount'], $line['tax_code_id'], $line['tax_inclusive']);
                }
                $document->lines()->create($line);
            }
            $this->audit->log('purchase_'.$type.'.'.($document->wasRecentlyCreated ? 'created' : 'updated'), $document, $company->id, $user->id);

            return $document->fresh()->load('lines');
        });
    }

    private function savePayment(Company $company, SupplierPayment $payment, array $data, User $user): SupplierPayment
    {
        $this->locks->assertPostingAllowed($company, $data['payment_date'], $user);
        $period = $this->years->resolve($company, $data['payment_date'], $data['financial_year_id'] ?? null, $data['accounting_period_id'] ?? null);
        $branch = $this->branches->resolve($company, $data['branch_id'] ?? null);
        $supplier = $company->suppliers()->where('is_active', true)->findOrFail($data['supplier_id']);
        $account = $company->accounts()->where('is_active', true)->where('type', 'asset')->findOrFail($data['payment_account_id']);
        $allocations = collect($data['allocations'] ?? [])->filter(fn ($row) => bccomp((string) ($row['amount'] ?? 0), '0', 4) > 0);
        $allocated = $allocations->reduce(fn ($sum, $row) => bcadd($sum, (string) $row['amount'], 4), '0.0000');
        if (bccomp($allocated, (string) $data['amount'], 4) !== 0) {
            throw ValidationException::withMessages(['allocations' => 'Supplier payment must be fully allocated in v0.4.']);
        }
        foreach ($allocations as $row) {
            $bill = $company->supplierBills()->where('supplier_id', $supplier->id)->whereIn('status', ['posted', 'partially_paid'])->findOrFail($row['supplier_bill_id']);
            if (bccomp((string) $row['amount'], $bill->amount_due, 4) > 0) {
                throw ValidationException::withMessages(['allocations' => 'Allocation exceeds the supplier bill outstanding balance.']);
            }
        }

        return DB::transaction(function () use ($company, $payment, $data, $user, $period, $branch, $supplier, $account, $allocations) {
            $values = ['company_id' => $company->id, 'supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'financial_year_id' => $period->financial_year_id, 'accounting_period_id' => $period->id, 'currency_id' => $company->base_currency_id, 'payment_date' => $data['payment_date'], 'payment_account_id' => $account->id, 'amount' => $data['amount'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'status' => 'draft', 'updated_by' => $user->id];
            if (! $payment->exists) {
                $values += ['payment_number' => $this->numbers->next($company, 'supplier_payment', 'SPAY'), 'created_by' => $user->id];
                $payment->fill($values)->save();
            } else {
                $payment = $payment->newQuery()->lockForUpdate()->findOrFail($payment->id);
                $payment->update($values);
                $payment->allocations()->delete();
            }foreach ($allocations as $row) {
                $payment->allocations()->create($row);
            }$this->audit->log('supplier_payment.'.($payment->wasRecentlyCreated ? 'created' : 'updated'), $payment, $company->id, $user->id);

            return $payment->fresh()->load('allocations.bill');
        });
    }

    public function convertOrder(Company $company, PurchaseOrder $order, array $data, User $user): SupplierBill
    {
        $this->access($company, $user, $order);

        return DB::transaction(function () use ($company, $order, $data, $user) {
            $order = PurchaseOrder::lockForUpdate()->with('lines')->findOrFail($order->id);
            if ($order->status !== 'draft' || $order->bill()->exists()) {
                throw ValidationException::withMessages(['order' => 'Only an unconverted Draft purchase order can be converted.']);
            }$bill = $this->create($company, 'bills', [...$data, 'purchase_order_id' => $order->id, 'supplier_id' => $order->supplier_id, 'branch_id' => $order->branch_id, 'supplier_reference' => $order->supplier_reference, 'notes' => $order->notes, 'lines' => $order->lines->map(fn ($line) => $line->only(['item_id', 'expense_account_id', 'description', 'quantity', 'unit_price', 'discount']))->all()], $user);
            $order->update(['status' => 'billed', 'updated_by' => $user->id]);
            $this->audit->log('purchase_order.converted', $order, $company->id, $user->id, null, ['supplier_bill_id' => $bill->id]);

            return $bill;
        });
    }

    public function post(Company $company, string $type, Model $document, User $user): Model
    {
        $this->access($company, $user, $document);

        return DB::transaction(function () use ($company, $type, $document, $user) {
            $document = $document->newQuery()->lockForUpdate()->with($type === 'payments' ? ['allocations.bill', 'accountingPeriod', 'supplier'] : ['lines', 'accountingPeriod', 'supplier'])->findOrFail($document->id);
            if ($document->status !== 'draft') {
                throw ValidationException::withMessages(['document' => 'Document must be Draft.']);
            }
            if ($type === 'credits') {
                $bill = SupplierBill::where('company_id', $company->id)
                    ->where('supplier_id', $document->supplier_id)
                    ->lockForUpdate()->findOrFail($document->supplier_bill_id);
                if (bccomp($document->total, $bill->amount_due, 4) > 0) {
                    throw ValidationException::withMessages(['total' => 'Supplier credit cannot exceed the bill outstanding balance.']);
                }
            }
            if ($type === 'payments') {
                foreach ($document->allocations as $allocation) {
                    $bill = SupplierBill::where('company_id', $company->id)
                        ->where('supplier_id', $document->supplier_id)
                        ->whereIn('status', ['posted', 'partially_paid'])
                        ->lockForUpdate()->findOrFail($allocation->supplier_bill_id);
                    if (bccomp($allocation->amount, $bill->amount_due, 4) > 0) {
                        throw ValidationException::withMessages(['allocations' => 'A payment allocation exceeds the current bill outstanding balance.']);
                    }
                }
            }
            if ($type === 'bills') {
                $lines = $document->lines->groupBy('expense_account_id')->map(fn ($rows, $account) => ['account_id' => $account, 'description' => $document->bill_number, 'debit' => $rows->sum('line_amount'), 'credit' => '0'])->values()->all();
                if (bccomp($document->tax_amount, '0', 4) > 0) {
                    $control = $company->taxSetting?->input_tax_account_id;
                    if (! $control) {
                        throw ValidationException::withMessages(['tax' => 'Configure an Input Tax Control Account before posting taxable purchases.']);
                    }
                    $lines[] = ['account_id' => $control, 'description' => $document->bill_number.' input tax', 'debit' => $document->tax_amount, 'credit' => '0'];
                }
                $journalLines = [...$lines, ['account_id' => $document->supplier->payable_account_id, 'description' => $document->bill_number, 'debit' => '0', 'credit' => $document->total]];
                $reference = $document->bill_number;
                $date = $document->bill_date;
            } elseif ($type === 'credits') {
                $lines = $document->lines->groupBy('expense_account_id')->map(fn ($rows, $account) => ['account_id' => $account, 'description' => $document->credit_number, 'debit' => '0', 'credit' => $rows->sum('line_amount')])->values()->all();
                if (bccomp((string) ($document->tax_amount ?? 0), '0', 4) > 0) {
                    $control = $company->taxSetting?->input_tax_account_id;
                    if (! $control) {
                        throw ValidationException::withMessages(['tax' => 'Configure an Input Tax Control Account before posting taxable credits.']);
                    }
                    $lines[] = ['account_id' => $control, 'description' => $document->credit_number.' input tax', 'debit' => '0', 'credit' => $document->tax_amount];
                }
                $journalLines = [['account_id' => $document->supplier->payable_account_id, 'description' => $document->credit_number, 'debit' => $document->total, 'credit' => '0'], ...$lines];
                $reference = $document->credit_number;
                $date = $document->credit_date;
            } elseif ($type === 'payments') {
                $journalLines = [['account_id' => $document->supplier->payable_account_id, 'description' => $document->payment_number, 'debit' => $document->amount, 'credit' => '0'], ['account_id' => $document->payment_account_id, 'description' => $document->payment_number, 'debit' => '0', 'credit' => $document->amount]];
                $reference = $document->payment_number;
                $date = $document->payment_date;
            } else {
                abort(404);
            }
            $journal = $this->journals->create($company, ['branch_id' => $document->branch_id, 'financial_year_id' => $document->financial_year_id, 'accounting_period_id' => $document->accounting_period_id, 'transaction_date' => $date->toDateString(), 'reference' => $reference, 'description' => ucfirst(str_replace('_', ' ', $type)).' '.$reference, 'lines' => $journalLines], $user);
            $this->journals->post($journal, $user);
            if (in_array($type, ['bills', 'credits'], true)) {
                foreach ($document->lines->whereNotNull('tax_code_id') as $line) {
                    $result = $this->tax->calculate($company, $line->tax_code_id, $date->toDateString(), bcadd($line->line_amount, $line->tax_amount, 4), true);
                    $sign = $type === 'credits' ? '-1' : '1';
                    TransactionTaxLine::create(['company_id' => $company->id, 'country_id' => $result['country_id'], 'tax_registration_id' => $result['tax_registration_id'], 'tax_period_id' => $result['tax_period_id'], 'tax_code_id' => $result['tax_code_id'], 'journal_entry_id' => $journal->id, 'source_type' => $document::class, 'source_id' => $document->id, 'source_line_type' => $line::class, 'source_line_id' => $line->id, 'direction' => 'input', 'transaction_date' => $date, 'tax_code_snapshot' => $result['tax_code'], 'tax_type_snapshot' => $result['tax_type'], 'treatment_snapshot' => $result['treatment'], 'registration_number_snapshot' => $result['registration_number'], 'rate_snapshot' => $result['rate'], 'net_amount' => bcmul($line->line_amount, $sign, 4), 'tax_amount' => bcmul($line->tax_amount, $sign, 4), 'gross_amount' => bcmul(bcadd($line->line_amount, $line->tax_amount, 4), $sign, 4)]);
                }
            }
            if ($type === 'credits') {
                $credited = bcadd($bill->amount_credited, $document->total, 4);
                $this->write(fn () => $bill->update(['amount_credited' => $credited, 'status' => $this->billStatus($bill->total, $bill->amount_paid, $credited)]));
            }
            if ($type === 'payments') {
                foreach ($document->allocations as $allocation) {
                    $bill = SupplierBill::lockForUpdate()->findOrFail($allocation->supplier_bill_id);
                    $paid = bcadd($bill->amount_paid, $allocation->amount, 4);
                    $this->write(fn () => $bill->update(['amount_paid' => $paid, 'status' => $this->billStatus($bill->total, $paid, $bill->amount_credited)]));
                }
            }
            $this->write(fn () => $document->update(['status' => 'posted', 'journal_entry_id' => $journal->id, 'updated_by' => $user->id]));
            $this->audit->log('supplier_'.$type.'.posted', $document, $company->id, $user->id);

            return $document->fresh();
        });
    }

    public function deleteDraft(Company $company, Model $document, User $user): void
    {
        $this->access($company, $user, $document);
        DB::transaction(function () use ($company, $document) {
            $locked = $document->newQuery()->lockForUpdate()->findOrFail($document->id);
            if ($locked->status !== 'draft') {
                throw ValidationException::withMessages(['document' => 'Only Draft documents can be deleted.']);
            }DB::table('audit_logs')->where('company_id', $company->id)->where('auditable_type', $locked::class)->where('auditable_id', $locked->id)->delete();
            if (method_exists($locked, 'allocations')) {
                $locked->allocations()->delete();
            }if (method_exists($locked, 'lines')) {
                $locked->lines()->delete();
            }$locked->delete();
        });
    }

    private function validateLines(Company $company, array $lines): void
    {
        if (! $lines) {
            throw ValidationException::withMessages(['lines' => 'At least one line is required.']);
        }foreach ($lines as $i => $line) {
            if (! empty($line['item_id'])) {
                $company->items()->where('is_active', true)->findOrFail($line['item_id']);
            }$account = $company->accounts()->where('is_active', true)->findOrFail($line['expense_account_id']);
            if (! in_array($account->type, ['expense', 'asset'], true) || $account->code === '2000') {
                throw ValidationException::withMessages(["lines.$i.expense_account_id" => 'Select an Expense or Asset account other than Accounts Payable.']);
            }if (bccomp((string) $line['quantity'], '0', 4) <= 0 || bccomp((string) $line['unit_price'], '0', 4) < 0 || bccomp((string) ($line['discount'] ?? 0), bcmul((string) $line['quantity'], (string) $line['unit_price'], 4), 4) > 0) {
                throw ValidationException::withMessages(["lines.$i.quantity" => 'Invalid quantity, price, or discount.']);
            }
        }
    }

    private function lineAmount(array $line): string
    {
        return bcsub(bcmul((string) $line['quantity'], (string) $line['unit_price'], 4), (string) ($line['discount'] ?? 0), 4);
    }

    private function total(array $lines): string
    {
        return collect($lines)->reduce(fn ($sum, $line) => bcadd($sum, $this->lineAmount($line), 4), '0.0000');
    }

    private function billStatus(string $total, string $paid, string $credited): string
    {
        $settled = bcadd($paid, $credited, 4);

        return bccomp($settled, $total, 4) === 0 ? 'paid' : 'partially_paid';
    }

    private function access(Company $company, User $user, ?Model $document = null): void
    {
        abort_unless($user->companies()->whereKey($company->id)->exists() && $company->entity_type !== 'individual' && (! $document || $document->company_id === $company->id), 404);
    }

    private function active(Company $company): void
    {
        if ($company->is_active === false) {
            throw ValidationException::withMessages(['company' => 'Inactive entities cannot create purchase transactions.']);
        }
    }

    private function write(callable $callback): void
    {
        app()->instance('purchases.system-write', true);
        try {
            $callback();
        } finally {
            app()->forgetInstance('purchases.system-write');
        }
    }
}

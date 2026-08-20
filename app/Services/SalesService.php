<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesService
{
    public function __construct(private DocumentNumberService $numbers, private JournalService $journals, private AuditLogger $audit, private BranchService $branches, private FinancialYearResolver $years) {}

    public function createCustomer(Company $company, array $data, User $user): Customer
    {
        $this->access($company, $user);
        $this->active($company);
        $this->account($company, $data['receivable_account_id']);
        $customer = $company->customers()->create([...$data, 'code' => $data['code'] ?? $this->numbers->next($company, 'customer', 'CUS'), 'created_by' => $user->id, 'updated_by' => $user->id]);
        $this->audit->log('customer.created', $customer, $company->id, $user->id, null, $customer->toArray());

        return $customer;
    }

    public function createItem(Company $company, array $data, User $user)
    {
        $this->access($company, $user);
        $this->active($company);
        $this->account($company, $data['revenue_account_id']);

        return $company->items()->create([...$data, 'created_by' => $user->id, 'updated_by' => $user->id]);
    }

    public function createQuotation(Company $c, array $d, User $u): SalesQuotation
    {
        return $this->document($c, $d, $u, SalesQuotation::class, 'quotation', 'QUO', 'quotation_number');
    }

    public function createOrder(Company $c, array $d, User $u): SalesOrder
    {
        return $this->document($c, $d, $u, SalesOrder::class, 'sales_order', 'SO', 'order_number');
    }

    private function document(Company $c, array $data, User $u, string $model, string $type, string $prefix, string $number): object
    {
        $this->access($c, $u);
        $this->active($c);
        $customer = $this->customer($c, $data['customer_id']);
        if (! $customer->is_active) {
            throw ValidationException::withMessages(['customer' => 'Inactive customers cannot be selected for new sales transactions.']);
        }
        $branch = $this->branches->resolve($c, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $dateField = $model === SalesQuotation::class ? 'quotation_date' : 'order_date';
        $period = $this->years->resolve($c, $data[$dateField], isset($data['financial_year_id']) ? (int) $data['financial_year_id'] : null, null, false);
        $data['financial_year_id'] = $period->financial_year_id;

        return DB::transaction(function () use ($c, $data, $u, $model, $type, $prefix, $number, $branch) {
            $lines = $data['lines'];
            unset($data['lines']);
            $total = $this->total($lines);
            $doc = $model::create([...$data, 'company_id' => $c->id, 'branch_id' => $branch->id, $number => $this->numbers->next($c, $type, $prefix), 'subtotal' => $total, 'total' => $total, 'created_by' => $u->id, 'updated_by' => $u->id]);
            foreach ($lines as $line) {
                if (! empty($line['item_id']) && ! $c->items()->whereKey($line['item_id'])->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['item' => 'Inactive or cross-company items cannot be selected.']);
                }
                $doc->lines()->create([...$line, 'line_amount' => $this->lineAmount($line)]);
            }
            $this->audit->log($type.'.created', $doc, $c->id, $u->id);

            return $doc;
        });
    }

    public function createInvoice(Company $c, array $data, User $u): SalesInvoice
    {
        $this->access($c, $u);
        $this->active($c);
        $customer = $this->customer($c, $data['customer_id']);
        if (! $customer->is_active) {
            throw ValidationException::withMessages(['customer' => 'Inactive customers cannot be invoiced.']);
        }
        $branch = $this->branches->resolve($c, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $period = $this->years->resolve($c, $data['invoice_date'], isset($data['financial_year_id']) ? (int) $data['financial_year_id'] : null, isset($data['accounting_period_id']) ? (int) $data['accounting_period_id'] : null);
        $data['financial_year_id'] = $period->financial_year_id;
        $data['accounting_period_id'] = $period->id;

        return DB::transaction(function () use ($c, $data, $u, $customer, $branch) {
            $lines = $data['lines'];
            unset($data['lines']);
            $total = $this->total($lines);
            $invoice = SalesInvoice::create([...$data, 'company_id' => $c->id, 'branch_id' => $branch->id, 'currency_id' => $data['currency_id'] ?? $customer->currency_id, 'invoice_number' => $this->numbers->next($c, 'sales_invoice', 'INV'), 'subtotal' => $total, 'tax_amount' => 0, 'total' => $total, 'amount_paid' => 0, 'status' => 'draft', 'created_by' => $u->id, 'updated_by' => $u->id]);
            foreach ($lines as $line) {
                if (! empty($line['item_id']) && ! $c->items()->whereKey($line['item_id'])->where('is_active', true)->exists()) {
                    throw ValidationException::withMessages(['item' => 'Inactive or cross-company items cannot be selected.']);
                }
                $this->account($c, $line['revenue_account_id']);
                $invoice->lines()->create([...$line, 'tax_amount' => 0, 'line_amount' => $this->lineAmount($line)]);
            }
            $this->audit->log('invoice.created', $invoice, $c->id, $u->id);

            return $invoice;
        });
    }

    public function postInvoice(SalesInvoice $invoice, User $u): SalesInvoice
    {
        return DB::transaction(function () use ($invoice, $u) {
            $invoice = SalesInvoice::query()->lockForUpdate()->with(['customer', 'lines', 'accountingPeriod'])->findOrFail($invoice->id);
            $c = $this->company($invoice->company_id, $u);
            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages(['invoice' => 'Invoice must be Draft.']);
            }
            $credits = [];
            foreach ($invoice->lines->groupBy('revenue_account_id') as $account => $lines) {
                $credits[] = ['account_id' => $account, 'description' => $invoice->invoice_number, 'debit' => '0', 'credit' => $lines->sum('line_amount')];
            }
            $journal = $this->journals->create($c, ['branch_id' => $invoice->branch_id, 'financial_year_id' => $invoice->accountingPeriod->financial_year_id, 'accounting_period_id' => $invoice->accounting_period_id, 'transaction_date' => $invoice->invoice_date->toDateString(), 'reference' => $invoice->invoice_number, 'description' => 'Sales invoice '.$invoice->invoice_number, 'lines' => [['account_id' => $invoice->customer->receivable_account_id, 'description' => $invoice->invoice_number, 'debit' => $invoice->total, 'credit' => '0'], ...$credits]], $u);
            $this->journals->post($journal, $u);
            $this->write(fn () => $invoice->update(['status' => 'posted', 'journal_entry_id' => $journal->id, 'updated_by' => $u->id]));
            $this->audit->log('invoice.posted', $invoice, $c->id, $u->id);

            return $invoice;
        });
    }

    public function postCreditNote(SalesCreditNote $note, User $u): SalesCreditNote
    {
        return DB::transaction(function () use ($note, $u) {
            $note = SalesCreditNote::query()->lockForUpdate()->with(['lines', 'accountingPeriod'])->findOrFail($note->id);
            $c = $this->company($note->company_id, $u);
            $customer = $this->customer($c, $note->customer_id);
            if ($note->status !== 'draft') {
                throw ValidationException::withMessages(['credit_note' => 'Credit note must be Draft.']);
            }
            $branch = $this->branches->resolve($c, $note->branch_id ? (int) $note->branch_id : null);
            if (! $note->branch_id) {
                $note->update(['branch_id' => $branch->id]);
            }
            $debits = [];
            foreach ($note->lines->groupBy('revenue_account_id') as $account => $lines) {
                $debits[] = ['account_id' => $account, 'description' => $note->credit_note_number, 'debit' => $lines->sum('line_amount'), 'credit' => '0'];
            }
            $journal = $this->journals->create($c, ['branch_id' => $branch->id, 'financial_year_id' => $note->accountingPeriod->financial_year_id, 'accounting_period_id' => $note->accounting_period_id, 'transaction_date' => $note->credit_note_date, 'reference' => $note->credit_note_number, 'description' => 'Credit note '.$note->credit_note_number, 'lines' => [...$debits, ['account_id' => $customer->receivable_account_id, 'description' => $note->credit_note_number, 'debit' => '0', 'credit' => $note->total]]], $u);
            $this->journals->post($journal, $u);
            $note->update(['status' => 'posted', 'journal_entry_id' => $journal->id, 'updated_by' => $u->id]);
            $this->audit->log('credit_note.posted', $note, $c->id, $u->id);

            return $note;
        });
    }

    public function postReceipt(CustomerReceipt $receipt, User $u): CustomerReceipt
    {
        return DB::transaction(function () use ($receipt, $u) {
            $receipt = CustomerReceipt::query()->lockForUpdate()->with(['allocations.invoice', 'accountingPeriod'])->findOrFail($receipt->id);
            $c = $this->company($receipt->company_id, $u);
            $customer = $this->customer($c, $receipt->customer_id);
            $branch = $this->branches->resolve($c, $receipt->branch_id ? (int) $receipt->branch_id : null);
            if (! $receipt->branch_id) {
                $receipt->update(['branch_id' => $branch->id]);
            }
            $allocated = $receipt->allocations->sum('amount');
            if (bccomp((string) $allocated, $receipt->amount, 4) > 0) {
                throw ValidationException::withMessages(['allocations' => 'Allocated amount exceeds receipt.']);
            }
            foreach ($receipt->allocations as $a) {
                if ($a->invoice->customer_id !== $customer->id || $a->invoice->status === 'draft' || bccomp($a->amount, $a->invoice->amount_due, 4) > 0) {
                    throw ValidationException::withMessages(['allocations' => 'Allocation is invalid or exceeds invoice balance.']);
                }
            }
            $journal = $this->journals->create($c, ['branch_id' => $branch->id, 'financial_year_id' => $receipt->accountingPeriod->financial_year_id, 'accounting_period_id' => $receipt->accounting_period_id, 'transaction_date' => $receipt->receipt_date, 'reference' => $receipt->receipt_number, 'description' => 'Customer receipt '.$receipt->receipt_number, 'lines' => [['account_id' => $receipt->receiving_account_id, 'description' => $receipt->receipt_number, 'debit' => $receipt->amount, 'credit' => '0'], ['account_id' => $customer->receivable_account_id, 'description' => $receipt->receipt_number, 'debit' => '0', 'credit' => $receipt->amount]]], $u);
            $this->journals->post($journal, $u);
            foreach ($receipt->allocations as $a) {
                $invoice = $a->invoice;
                $paid = bcadd($invoice->amount_paid, $a->amount, 4);
                $this->write(fn () => $invoice->update(['amount_paid' => $paid, 'status' => bccomp($paid, $invoice->total, 4) === 0 ? 'paid' : 'partially_paid']));
            }
            $receipt->update(['status' => 'posted', 'journal_entry_id' => $journal->id, 'updated_by' => $u->id]);
            $this->audit->log('receipt.posted', $receipt, $c->id, $u->id);

            return $receipt;
        });
    }

    private function lineAmount(array $l): string
    {
        return bcsub(bcmul((string) $l['quantity'], (string) $l['unit_price'], 4), (string) ($l['discount'] ?? 0), 4);
    }

    private function total(array $lines): string
    {
        return collect($lines)->reduce(fn ($x, $l) => bcadd($x, $this->lineAmount($l), 4), '0.0000');
    }

    private function access(Company $c, User $u): void
    {
        if (! $u->companies()->whereKey($c->id)->exists()) {
            throw ValidationException::withMessages(['company' => 'Company is not accessible.']);
        }
    }

    private function company(int $id, User $u): Company
    {
        return $u->companies()->findOrFail($id);
    }

    private function customer(Company $c, int $id): Customer
    {
        return Customer::where('company_id', $c->id)->findOrFail($id);
    }

    private function account(Company $c, int $id)
    {
        return $c->accounts()->findOrFail($id);
    }

    private function active(Company $company): void
    {
        if ($company->is_active === false) {
            throw ValidationException::withMessages(['company' => 'Inactive companies cannot accept new sales transactions.']);
        }
    }

    private function write(callable $callback): void
    {
        app()->instance('sales.system-write', true);
        try {
            $callback();
        } finally {
            app()->forgetInstance('sales.system-write');
        }
    }
}

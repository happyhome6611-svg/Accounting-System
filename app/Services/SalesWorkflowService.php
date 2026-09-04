<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SalesWorkflowService
{
    public function __construct(private SalesService $sales, private DocumentNumberService $numbers, private BranchService $branches, private AuditLogger $audit, private FinancialYearResolver $years, private AccountingLockService $locks) {}

    public function create(Company $company, string $type, array $data, User $user): Model
    {
        return match ($type) {
            'quotations' => $this->sales->createQuotation($company, $data, $user),
            'orders' => $this->sales->createOrder($company, $data, $user),
            'invoices' => $this->sales->createInvoice($company, $data, $user),
            'credit-notes' => $this->saveCreditNote($company, new SalesCreditNote, $data, $user),
            'receipts' => $this->saveReceipt($company, new CustomerReceipt, $data, $user),
            default => abort(404),
        };
    }

    public function update(Company $company, string $type, Model $document, array $data, User $user): Model
    {
        $this->access($company, $document, $user);
        if ($document->status !== 'draft') {
            throw ValidationException::withMessages(['document' => 'Only Draft documents can be edited.']);
        }

        return match ($type) {
            'quotations', 'orders', 'invoices' => $this->updateLineDocument($company, $document, $data, $user),
            'credit-notes' => $this->saveCreditNote($company, $document, $data, $user),
            'receipts' => $this->saveReceipt($company, $document, $data, $user),
            default => abort(404),
        };
    }

    public function delete(Company $company, Model $document, User $user): void
    {
        $this->access($company, $document, $user);
        DB::transaction(function () use ($company, $document) {
            $document = $document->newQuery()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== 'draft') {
                throw ValidationException::withMessages(['document' => 'Only Draft documents can be deleted.']);
            }
            DB::table('audit_logs')->where('company_id', $company->id)->where('auditable_type', $document::class)->where('auditable_id', $document->id)->delete();
            if (method_exists($document, 'allocations')) {
                $document->allocations()->delete();
            }
            if (method_exists($document, 'lines')) {
                $document->lines()->delete();
            }
            $document->delete();
        });
    }

    public function quotationToOrder(Company $company, SalesQuotation $quotation, User $user): SalesOrder
    {
        $this->access($company, $quotation, $user);

        return DB::transaction(function () use ($company, $quotation, $user) {
            $quotation = SalesQuotation::lockForUpdate()->with('lines')->findOrFail($quotation->id);
            if ($quotation->status !== 'draft' || $quotation->convertedOrder()->exists()) {
                throw ValidationException::withMessages(['quotation' => 'Only an unconverted Draft quotation can be converted.']);
            }
            $order = $this->sales->createOrder($company, ['sales_quotation_id' => $quotation->id, 'customer_id' => $quotation->customer_id, 'branch_id' => $quotation->branch_id, 'order_date' => today()->toDateString(), 'customer_reference' => $quotation->customer_reference, 'notes' => $quotation->notes, 'status' => 'draft', 'lines' => $this->copyLines($quotation)], $user);
            $quotation->update(['status' => 'converted', 'updated_by' => $user->id]);
            $this->audit->log('quotation.converted', $quotation, $company->id, $user->id, null, ['sales_order_id' => $order->id]);

            return $order;
        });
    }

    public function orderToInvoice(Company $company, SalesOrder $order, array $data, User $user): SalesInvoice
    {
        $this->access($company, $order, $user);

        return DB::transaction(function () use ($company, $order, $data, $user) {
            $order = SalesOrder::lockForUpdate()->with('lines')->findOrFail($order->id);
            if ($order->status !== 'draft' || $order->convertedInvoice()->exists()) {
                throw ValidationException::withMessages(['order' => 'Only an unconverted Draft order can be converted.']);
            }
            $invoice = $this->sales->createInvoice($company, ['sales_order_id' => $order->id, 'customer_id' => $order->customer_id, 'branch_id' => $order->branch_id, 'accounting_period_id' => $data['accounting_period_id'], 'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'], 'customer_reference' => $order->customer_reference, 'notes' => $order->notes, 'lines' => $this->copyLines($order)], $user);
            $order->update(['status' => 'converted', 'converted_invoice_id' => $invoice->id, 'updated_by' => $user->id]);
            $this->audit->log('sales_order.converted', $order, $company->id, $user->id, null, ['sales_invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    private function updateLineDocument(Company $company, Model $document, array $data, User $user): Model
    {
        $dateField = match (true) {
            $document instanceof SalesQuotation => 'quotation_date',
            $document instanceof SalesOrder => 'order_date',
            default => 'invoice_date',
        };
        if (! ($document instanceof SalesQuotation || $document instanceof SalesOrder)) {
            $this->locks->assertPostingAllowed($company, $data[$dateField], $user);
        }
        $period = $this->years->resolve($company, $data[$dateField], isset($data['financial_year_id']) ? (int) $data['financial_year_id'] : null, isset($data['accounting_period_id']) ? (int) $data['accounting_period_id'] : null, ! ($document instanceof SalesQuotation || $document instanceof SalesOrder));
        $data['financial_year_id'] = $period->financial_year_id;
        if ($document instanceof SalesInvoice) {
            $data['accounting_period_id'] = $period->id;
        }
        $branch = $this->branches->resolve($company, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $customer = $company->customers()->where('is_active', true)->findOrFail($data['customer_id']);
        $lines = $data['lines'];
        unset($data['lines']);
        $this->validateLines($company, $lines);
        $total = $this->total($lines);
        DB::transaction(function () use ($document, $data, $lines, $branch, $customer, $total, $user) {
            $document = $document->newQuery()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== 'draft') {
                throw ValidationException::withMessages(['document' => 'Only Draft documents can be edited.']);
            }
            $document->update([...$data, 'customer_id' => $customer->id, 'branch_id' => $branch->id, 'subtotal' => $total, 'total' => $total, 'updated_by' => $user->id]);
            $document->lines()->delete();
            foreach ($lines as $line) {
                $values = [...$line, 'line_amount' => $this->lineAmount($line)];
                if ($document instanceof SalesInvoice) {
                    $values['tax_amount'] = 0;
                }
                $document->lines()->create($values);
            }
            $this->audit->log('sales_document.updated', $document, $document->company_id, $user->id);
        });

        return $document->fresh()->load('lines');
    }

    private function saveCreditNote(Company $company, SalesCreditNote $note, array $data, User $user): SalesCreditNote
    {
        $this->locks->assertPostingAllowed($company, $data['credit_note_date'], $user);
        $period = $this->years->resolve($company, $data['credit_note_date'], isset($data['financial_year_id']) ? (int) $data['financial_year_id'] : null, isset($data['accounting_period_id']) ? (int) $data['accounting_period_id'] : null);
        $data['financial_year_id'] = $period->financial_year_id;
        $data['accounting_period_id'] = $period->id;
        $branch = $this->branches->resolve($company, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $customer = $company->customers()->where('is_active', true)->findOrFail($data['customer_id']);
        $invoice = $company->salesInvoices()->where('customer_id', $customer->id)->whereIn('status', ['posted', 'partially_paid', 'paid'])->findOrFail($data['sales_invoice_id']);
        $lines = $data['lines'];
        $this->validateLines($company, $lines);
        $total = $this->total($lines);
        $credited = SalesCreditNote::where('sales_invoice_id', $invoice->id)->where('status', 'posted')->when($note->exists, fn ($q) => $q->whereKeyNot($note->id))->sum('total');
        $remaining = bcsub(bcsub($invoice->total, $invoice->amount_paid, 4), (string) $credited, 4);
        if (bccomp($total, $remaining, 4) > 0) {
            throw ValidationException::withMessages(['total' => 'Credit note exceeds the invoice remaining balance.']);
        }
        DB::transaction(function () use ($company, $note, $data, $lines, $branch, $customer, $invoice, $total, $user) {
            $values = ['company_id' => $company->id, 'customer_id' => $customer->id, 'branch_id' => $branch->id, 'sales_invoice_id' => $invoice->id, 'financial_year_id' => $data['financial_year_id'], 'accounting_period_id' => $data['accounting_period_id'], 'credit_note_date' => $data['credit_note_date'], 'notes' => $data['notes'] ?? null, 'total' => $total, 'updated_by' => $user->id];
            if (! $note->exists) {
                $values += ['credit_note_number' => $this->numbers->next($company, 'credit_note', 'CN'), 'status' => 'draft', 'created_by' => $user->id];
                $note->fill($values)->save();
            } else {
                $note->update($values);
                $note->lines()->delete();
            }
            foreach ($lines as $line) {
                $note->lines()->create(['revenue_account_id' => $line['revenue_account_id'], 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_amount' => $this->lineAmount($line)]);
            }
            $this->audit->log($note->wasRecentlyCreated ? 'credit_note.created' : 'credit_note.updated', $note, $company->id, $user->id);
        });

        return $note->fresh()->load('lines');
    }

    private function saveReceipt(Company $company, CustomerReceipt $receipt, array $data, User $user): CustomerReceipt
    {
        $this->locks->assertPostingAllowed($company, $data['receipt_date'], $user);
        $period = $this->years->resolve($company, $data['receipt_date'], isset($data['financial_year_id']) ? (int) $data['financial_year_id'] : null, isset($data['accounting_period_id']) ? (int) $data['accounting_period_id'] : null);
        $data['financial_year_id'] = $period->financial_year_id;
        $data['accounting_period_id'] = $period->id;
        $branch = $this->branches->resolve($company, isset($data['branch_id']) ? (int) $data['branch_id'] : null);
        $customer = $company->customers()->where('is_active', true)->findOrFail($data['customer_id']);
        $company->accounts()->where('is_active', true)->findOrFail($data['receiving_account_id']);
        $allocations = collect($data['allocations'] ?? [])->filter(fn ($row) => bccomp((string) ($row['amount'] ?? 0), '0', 4) > 0);
        $allocated = $allocations->reduce(fn ($sum, $row) => bcadd($sum, (string) $row['amount'], 4), '0.0000');
        if (bccomp($allocated, (string) $data['amount'], 4) !== 0) {
            throw ValidationException::withMessages(['allocations' => 'Receipt amount must be fully allocated to outstanding invoices.']);
        }
        foreach ($allocations as $row) {
            $invoice = $company->salesInvoices()->where('customer_id', $customer->id)->whereIn('status', ['posted', 'partially_paid'])->findOrFail($row['sales_invoice_id']);
            if (bccomp((string) $row['amount'], $invoice->amount_due, 4) > 0) {
                throw ValidationException::withMessages(['allocations' => 'An allocation exceeds the selected invoice balance.']);
            }
        }
        DB::transaction(function () use ($company, $receipt, $data, $allocations, $branch, $customer, $user) {
            $values = ['company_id' => $company->id, 'customer_id' => $customer->id, 'branch_id' => $branch->id, 'financial_year_id' => $data['financial_year_id'], 'accounting_period_id' => $data['accounting_period_id'], 'receipt_date' => $data['receipt_date'], 'amount' => $data['amount'], 'payment_method' => $data['payment_method'], 'reference' => $data['reference'] ?? null, 'receiving_account_id' => $data['receiving_account_id'], 'updated_by' => $user->id];
            if (! $receipt->exists) {
                $values += ['receipt_number' => $this->numbers->next($company, 'customer_receipt', 'REC'), 'status' => 'draft', 'created_by' => $user->id];
                $receipt->fill($values)->save();
            } else {
                $receipt->update($values);
                $receipt->allocations()->delete();
            }
            foreach ($allocations as $row) {
                $receipt->allocations()->create($row);
            }
            $this->audit->log($receipt->wasRecentlyCreated ? 'receipt.created' : 'receipt.updated', $receipt, $company->id, $user->id);
        });

        return $receipt->fresh()->load('allocations.invoice');
    }

    private function validateLines(Company $company, array $lines): void
    {
        if (count($lines) < 1) {
            throw ValidationException::withMessages(['lines' => 'At least one line is required.']);
        }
        foreach ($lines as $index => $line) {
            if (! empty($line['item_id']) && ! $company->items()->where('is_active', true)->whereKey($line['item_id'])->exists()) {
                throw ValidationException::withMessages(["lines.$index.item_id" => 'Select an active company product or service.']);
            }
            $company->accounts()->where('is_active', true)->findOrFail($line['revenue_account_id']);
            if (bccomp((string) $line['quantity'], '0', 4) <= 0 || bccomp((string) $line['unit_price'], '0', 4) < 0) {
                throw ValidationException::withMessages(["lines.$index.quantity" => 'Quantity must be positive and price cannot be negative.']);
            }
            $gross = bcmul((string) $line['quantity'], (string) $line['unit_price'], 4);
            if (bccomp((string) ($line['discount'] ?? 0), $gross, 4) > 0) {
                throw ValidationException::withMessages(["lines.$index.discount" => 'Discount amount cannot exceed the line gross amount.']);
            }
        }
    }

    private function copyLines(Model $document): array
    {
        return $document->lines->map(fn ($line) => ['item_id' => $line->item_id, 'revenue_account_id' => $line->revenue_account_id, 'description' => $line->description, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'discount' => $line->discount ?? 0])->all();
    }

    private function lineAmount(array $line): string
    {
        return bcsub(bcmul((string) $line['quantity'], (string) $line['unit_price'], 4), (string) ($line['discount'] ?? 0), 4);
    }

    private function total(array $lines): string
    {
        return collect($lines)->reduce(fn ($sum, $line) => bcadd($sum, $this->lineAmount($line), 4), '0.0000');
    }

    private function access(Company $company, Model $document, User $user): void
    {
        abort_unless($user->companies()->whereKey($company->id)->exists() && $document->company_id === $company->id, 404);
    }
}

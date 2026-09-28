<?php

namespace App\ImportExport;

use App\Models\Account;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Item;
use App\Models\JournalEntry;
use App\Models\SalesInvoice;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CustomerMaintenanceService;
use App\Services\ItemMaintenanceService;
use App\Services\JournalService;
use App\Services\PurchaseService;
use App\Services\SalesWorkflowService;
use App\Services\SupplierMaintenanceService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ImportUndoService
{
    public function __construct(
        private CustomerMaintenanceService $customers,
        private SupplierMaintenanceService $suppliers,
        private ItemMaintenanceService $items,
        private SalesWorkflowService $sales,
        private PurchaseService $purchases,
        private JournalService $journals,
        private AuditLogger $audit,
    ) {}

    public function analysis(Company $company, ImportBatch $batch, User $user): array
    {
        $this->authorize($company, $batch, $user);
        $entries = [];
        foreach ($batch->rows()->whereNotNull('result_id')->get()->unique(fn ($row) => $row->result_model.'#'.$row->result_id) as $row) {
            $entries[] = $this->classify($company, $batch, $row->result_model, (int) $row->result_id);
        }
        if ($batch->data_type === 'opening_balances' && $entries === []) {
            $count = $batch->rows()->whereNull('undone_at')->count();
            if ($count) {
                $entries[] = ['classification' => 'SAFE_TO_REMOVE', 'type' => 'Opening Balance staging rows', 'id' => null, 'reason' => "{$count} unposted staging rows will be removed."];
            }
        }
        $counts = collect($entries)->countBy('classification');

        return [
            'entries' => $entries,
            'safe' => $counts->get('SAFE_TO_REMOVE', 0),
            'reversal' => $counts->get('REVERSAL_REQUIRED', 0),
            'blocked' => $counts->get('BLOCKED', 0),
            'already_undone' => $counts->get('ALREADY_UNDONE', 0),
            'created_records' => collect($entries)->whereNotIn('classification', ['ALREADY_UNDONE'])->count(),
            'affected_journals' => collect($entries)->where('type', 'Manual Journal')->count(),
            'affected_tax_records' => $entries === [] ? 0 : DB::table('transaction_tax_lines')->where('company_id', $company->id)->where(function ($query) use ($entries) {
                foreach ($entries as $entry) {
                    if (in_array($entry['type'], ['Sales Invoice', 'Supplier Bill'], true) && $entry['id']) {
                        $query->orWhere('source_id', $entry['id']);
                    }
                }
            })->count(),
        ];
    }

    public function undo(Company $company, ImportBatch $batch, User $user, string $confirmation, ?string $reversalDate = null): ImportBatch
    {
        if ($confirmation !== 'UNDO') {
            throw ValidationException::withMessages(['confirmation' => 'Type UNDO exactly to confirm.']);
        }
        $preflight = $this->analysis($company, $batch, $user);
        if ($preflight['blocked']) {
            $batch->update(['undo_status' => 'blocked', 'undo_summary' => $preflight]);
            $this->audit->log('import.undo_blocked', $batch, $company->id, $user->id, null, $preflight);
            throw ValidationException::withMessages(['undo' => 'Undo is blocked. Resolve every listed dependency before trying again.']);
        }

        return DB::transaction(function () use ($company, $batch, $user, $reversalDate) {
            $batch = ImportBatch::where('company_id', $company->id)->lockForUpdate()->findOrFail($batch->id);
            $this->authorize($company, $batch, $user);
            if ($batch->undo_status === 'undone') {
                return $batch;
            }
            $analysis = $this->analysis($company, $batch, $user);
            if ($analysis['blocked']) {
                throw ValidationException::withMessages(['undo' => 'Undo is blocked. Resolve every listed dependency before trying again.']);
            }
            if ($analysis['reversal'] && ! $reversalDate) {
                throw ValidationException::withMessages(['reversal_date' => 'Choose a reversal date for posted journals.']);
            }

            foreach ($batch->rows()->whereNotNull('result_id')->lockForUpdate()->get()->unique(fn ($row) => $row->result_model.'#'.$row->result_id) as $row) {
                $model = $row->result_model::find($row->result_id);
                if (! $model) {
                    continue;
                }
                $undoResult = $this->remove($company, $batch, $model, $user, $reversalDate);
                $batch->rows()->where('result_model', $row->result_model)->where('result_id', $row->result_id)->update(['validation_status' => 'skipped', 'undone_at' => now(), 'undo_result_model' => $undoResult ? $undoResult::class : null, 'undo_result_id' => $undoResult?->getKey()]);
            }
            if ($batch->data_type === 'opening_balances') {
                $batch->rows()->whereNull('undone_at')->update(['validation_status' => 'skipped', 'undone_at' => now()]);
            }
            $final = $this->analysis($company, $batch, $user);
            $batch->update(['undo_status' => 'undone', 'undo_summary' => $analysis, 'undone_by' => $user->id, 'undone_at' => now()]);
            $this->audit->log('import.undone', $batch, $company->id, $user->id, null, ['preflight' => $analysis, 'result' => $final]);

            return $batch->fresh();
        });
    }

    public function deleteAttempt(Company $company, ImportBatch $batch, User $user): void
    {
        $this->authorize($company, $batch, $user);
        if ($batch->hasCreatedRecords() || $batch->data_type === 'opening_balances' && $batch->rows()->whereNull('undone_at')->exists()) {
            throw ValidationException::withMessages(['batch' => 'This batch created or staged records. Use Undo Import instead.']);
        }
        DB::transaction(function () use ($company, $batch, $user) {
            $batch = ImportBatch::lockForUpdate()->findOrFail($batch->id);
            $this->audit->log('import.attempt_deleted', $batch, $company->id, $user->id, null, ['filename' => $batch->original_filename, 'status' => $batch->statusLabel()]);
            $batch->delete();
        });
    }

    private function classify(Company $company, ImportBatch $batch, string $class, int $id): array
    {
        $expected = $this->expectedClass($batch->data_type);
        if ($class !== $expected) {
            return ['classification' => 'BLOCKED', 'type' => class_basename($class), 'id' => $id, 'reason' => 'Import provenance does not match the batch data type.'];
        }
        $model = $class::find($id);
        if (! $model) {
            return ['classification' => 'ALREADY_UNDONE', 'type' => class_basename($class), 'id' => $id, 'reason' => 'The imported record has already been removed.'];
        }
        if ((int) $model->company_id !== $company->id) {
            return ['classification' => 'BLOCKED', 'type' => class_basename($class), 'id' => $id, 'reason' => 'The provenance record belongs to another Accounting Entity.'];
        }
        $blockers = match ($class) {
            Customer::class => $this->customers->blockers($model),
            Supplier::class => $this->suppliers->blockers($model),
            Item::class => [...$this->items->blockers($model), ...collect(['purchase_order_lines' => 'purchase orders', 'supplier_bill_lines' => 'supplier bills', 'supplier_credit_lines' => 'supplier credits'])->filter(fn ($label, $table) => DB::table($table)->where('item_id', $model->id)->exists())->values()->all()],
            Account::class => $this->accountBlockers($model),
            SalesInvoice::class => $model->status === 'draft' && ! $model->allocations()->exists() && ! $model->creditNotes()->exists() ? [] : ['Posted or dependent invoices require an explicit correction workflow and cannot be hard-deleted.'],
            SupplierBill::class => $model->status === 'draft' && ! $model->allocations()->exists() && ! $model->credits()->exists() ? [] : ['Posted or dependent bills require an explicit correction workflow and cannot be hard-deleted.'],
            default => [],
        };
        if ($blockers) {
            return ['classification' => 'BLOCKED', 'type' => class_basename($class), 'id' => $id, 'reason' => implode(' ', $blockers)];
        }
        if ($model instanceof JournalEntry && $model->status === 'posted') {
            return ['classification' => 'REVERSAL_REQUIRED', 'type' => 'Manual Journal', 'id' => $id, 'reason' => 'The original posted journal will remain and a controlled reversal will be posted.'];
        }
        if ($model instanceof JournalEntry && $model->status === 'reversed') {
            return ['classification' => 'ALREADY_UNDONE', 'type' => 'Manual Journal', 'id' => $id, 'reason' => 'The journal is already reversed.'];
        }

        return ['classification' => 'SAFE_TO_REMOVE', 'type' => class_basename($class), 'id' => $id, 'reason' => 'Unused draft or master record can be safely removed.'];
    }

    private function remove(Company $company, ImportBatch $batch, Model $model, User $user, ?string $reversalDate): ?Model
    {
        return match ($model::class) {
            Customer::class => tap(null, fn () => $this->customers->delete($company, $model, $user, $model->name)),
            Supplier::class => tap(null, fn () => $this->suppliers->delete($company, $model, $model->name, $user)),
            Item::class => tap(null, fn () => $this->items->delete($company, $model, $user, $model->name)),
            Account::class => tap(null, fn () => $model->forceDelete()),
            SalesInvoice::class => tap(null, fn () => $this->sales->delete($company, $model, $user)),
            SupplierBill::class => tap(null, fn () => $this->purchases->deleteDraft($company, $model, $user)),
            JournalEntry::class => $model->status === 'draft'
                ? tap(null, fn () => $this->journals->deleteDraft($model, $user))
                : $this->journals->reverse($model, $user, $company->financialYears()->whereHas('periods', fn ($query) => $query->whereDate('starts_on', '<=', $reversalDate)->whereDate('ends_on', '>=', $reversalDate)->where('status', 'open'))->firstOrFail()->periods()->whereDate('starts_on', '<=', $reversalDate)->whereDate('ends_on', '>=', $reversalDate)->value('id'), $reversalDate),
            default => throw ValidationException::withMessages(['undo' => 'This imported record type is not supported for undo.']),
        };
    }

    private function expectedClass(string $type): string
    {
        return match ($type) {
            'customers' => Customer::class,
            'suppliers' => Supplier::class,
            'products' => Item::class,
            'chart_of_accounts' => Account::class,
            'sales_invoices' => SalesInvoice::class,
            'supplier_bills' => SupplierBill::class,
            'manual_journals' => JournalEntry::class,
            default => '',
        };
    }

    private function accountBlockers(Account $account): array
    {
        if ($account->is_system) {
            return ['System accounts cannot be removed.'];
        }
        $links = [
            ['journal_lines', 'account_id', 'journal entries'], ['bank_accounts', 'ledger_account_id', 'bank accounts'], ['bank_transactions', 'counterparty_account_id', 'bank transactions'],
            ['customers', 'receivable_account_id', 'customers'], ['suppliers', 'payable_account_id', 'suppliers'], ['items', 'revenue_account_id', 'products'], ['items', 'expense_account_id', 'products'],
            ['sales_quotation_lines', 'revenue_account_id', 'sales quotations'], ['sales_order_lines', 'revenue_account_id', 'sales orders'], ['sales_invoice_lines', 'revenue_account_id', 'sales invoices'], ['sales_credit_note_lines', 'revenue_account_id', 'sales credits'], ['customer_receipts', 'receiving_account_id', 'customer receipts'],
            ['purchase_order_lines', 'expense_account_id', 'purchase orders'], ['supplier_bill_lines', 'expense_account_id', 'supplier bills'], ['supplier_credit_lines', 'expense_account_id', 'supplier credits'], ['supplier_payments', 'payment_account_id', 'supplier payments'],
            ['tax_settings', 'output_tax_account_id', 'tax settings'], ['tax_settings', 'input_tax_account_id', 'tax settings'], ['tax_settings', 'rounding_account_id', 'tax settings'], ['companies', 'retained_earnings_account_id', 'retained earnings configuration'], ['year_end_closures', 'retained_earnings_account_id', 'year-end closings'],
        ];

        return collect($links)->filter(fn ($link) => DB::table($link[0])->where($link[1], $account->id)->exists())->pluck(2)->unique()->values()->all();
    }

    private function authorize(Company $company, ImportBatch $batch, User $user): void
    {
        abort_unless($user->companies()->whereKey($company->id)->exists() && $batch->company_id === $company->id && $batch->country_id === $company->country_id, 404);
    }
}

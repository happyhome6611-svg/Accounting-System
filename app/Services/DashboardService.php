<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class DashboardService
{
    public function __construct(private AccountingReportService $reports) {}

    public function metrics(Company $company, ?int $branchId, ?int $financialYearId = null): array
    {
        abort_if($branchId && ! $company->supportsBranches(), 404);
        abort_if($branchId && ! $company->branches()->whereKey($branchId)->exists(), 404);
        abort_if($financialYearId && ! $company->financialYears()->whereKey($financialYearId)->exists(), 404);
        $scope = fn ($query) => $query->when($branchId, fn ($q) => $q->where('branch_id', $branchId))->when($financialYearId, fn ($q) => $q->where('financial_year_id', $financialYearId));
        $invoices = $scope($company->salesInvoices()->whereIn('status', ['posted', 'partially_paid', 'paid']));
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $open = $scope($company->salesInvoices()->whereIn('status', ['posted', 'partially_paid']))->get();
        $openBills = $scope($company->supplierBills()->whereIn('status', ['posted', 'partially_paid']))->get();
        $payables = $openBills->reduce(fn ($sum, $bill) => bcadd($sum, $bill->amount_due, 4), '0.0000');
        $journalLines = DB::table('journal_lines as line')->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')->join('accounts as account', 'account.id', '=', 'line.account_id')->where('journal.company_id', $company->id)->whereIn('journal.status', ['posted', 'reversed'])->when($branchId, fn ($q) => $q->where('journal.branch_id', $branchId))->when($financialYearId, fn ($q) => $q->where('journal.financial_year_id', $financialYearId));
        $cash = (clone $journalLines)->where('account.type', 'asset')->where(function ($query) {
            $query->where('account.code', 'like', '10%')->orWhere('account.name', 'like', '%Cash%')->orWhere('account.name', 'like', '%Bank%');
        })->selectRaw('COALESCE(SUM(line.debit - line.credit), 0) balance')->value('balance');
        $receivableAccountIds = $company->customers()->whereNotNull('receivable_account_id')->distinct()->pluck('receivable_account_id');
        $receivables = $receivableAccountIds->isEmpty() ? '0.0000' : (string) (clone $journalLines)->whereIn('line.account_id', $receivableAccountIds)->selectRaw('COALESCE(SUM(line.debit - line.credit), 0) balance')->value('balance');
        $expenses = (string) (clone $journalLines)->where('account.type', 'expense')->whereBetween('journal.transaction_date', [$monthStart, $monthEnd])->selectRaw('COALESCE(SUM(line.debit - line.credit), 0) balance')->value('balance');
        $sales = (string) (clone $invoices)->whereBetween('invoice_date', [$monthStart, $monthEnd])->sum('total');
        $profit = $this->reports->profitAndLoss($company, $monthStart, $monthEnd, $branchId, $financialYearId)['net'];
        $branchNames = $company->branches()->pluck('name', 'id');
        $activity = collect()
            ->merge($scope($company->journals()->whereIn('status', ['posted', 'reversed'])->latest())->limit(8)->get()->map(fn ($row) => ['date' => $row->transaction_date, 'type' => 'Journal', 'reference' => $row->journal_number, 'branch' => $branchNames[$row->branch_id] ?? '', 'amount' => null, 'status' => $row->status]))
            ->merge($scope($company->salesInvoices()->whereIn('status', ['posted', 'partially_paid', 'paid'])->latest())->limit(8)->get()->map(fn ($row) => ['date' => $row->invoice_date, 'type' => 'Sales invoice', 'reference' => $row->invoice_number, 'branch' => $branchNames[$row->branch_id] ?? '', 'amount' => $row->total, 'status' => $row->status]))
            ->merge($this->transactionActivity('customer_receipts', 'receipt_date', 'receipt_number', 'Customer receipt', 'amount', $company->id, $branchId, $financialYearId, $branchNames))
            ->merge($this->transactionActivity('sales_credit_notes', 'credit_note_date', 'credit_note_number', 'Credit note', 'total', $company->id, $branchId, $financialYearId, $branchNames))
            ->sortByDesc('date')->take(10);

        return ['cash' => (string) $cash, 'receivables' => $receivables, 'payables' => $payables, 'sales' => $sales, 'expenses' => $expenses, 'profit' => $profit, 'open_invoices' => $open->count(), 'overdue_invoices' => $open->filter(fn ($invoice) => $invoice->due_date?->isPast() && bccomp($invoice->amount_due, '0', 4) > 0)->count(), 'unpaid_bills' => $openBills->count(), 'overdue_bills' => $openBills->filter(fn ($bill) => $bill->due_date?->isPast() && bccomp($bill->amount_due, '0', 4) > 0)->count(), 'activity' => $activity];
    }

    public function emptyMetrics(): array
    {
        return ['cash' => '0.0000', 'receivables' => '0.0000', 'payables' => '0.0000', 'sales' => '0.0000', 'expenses' => '0.0000', 'profit' => '0.0000', 'open_invoices' => 0, 'overdue_invoices' => 0, 'unpaid_bills' => 0, 'overdue_bills' => 0, 'activity' => collect()];
    }

    private function transactionActivity(string $table, string $date, string $reference, string $type, string $amount, int $companyId, ?int $branchId, ?int $financialYearId, $branchNames)
    {
        return DB::table($table)->where('company_id', $companyId)->where('status', 'posted')->when($branchId, fn ($query) => $query->where('branch_id', $branchId))->when($financialYearId, fn ($query) => $query->where('financial_year_id', $financialYearId))->latest($date)->limit(8)->get()->map(fn ($row) => ['date' => $row->{$date}, 'type' => $type, 'reference' => $row->{$reference}, 'branch' => $branchNames[$row->branch_id] ?? '', 'amount' => $row->{$amount}, 'status' => $row->status]);
    }
}

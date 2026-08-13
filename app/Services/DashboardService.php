<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class DashboardService
{
    public function metrics(Company $company, ?int $branchId): array
    {
        $scope = fn ($query) => $query->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $invoices = $scope($company->salesInvoices()->whereIn('status', ['posted', 'partially_paid', 'paid']));
        $monthInvoices = (clone $invoices)->whereBetween('invoice_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()]);
        $open = $scope($company->salesInvoices()->whereIn('status', ['posted', 'partially_paid']))->get();
        $journalLines = DB::table('journal_lines as line')->join('journal_entries as journal', 'journal.id', '=', 'line.journal_entry_id')->join('accounts as account', 'account.id', '=', 'line.account_id')->where('journal.company_id', $company->id)->whereIn('journal.status', ['posted', 'reversed'])->when($branchId, fn ($q) => $q->where('journal.branch_id', $branchId));
        $cash = (clone $journalLines)->where('account.type', 'asset')->where(function ($q) {
            $q->where('account.code', 'like', '10%')->orWhere('account.name', 'like', '%Cash%')->orWhere('account.name', 'like', '%Bank%');
        })->selectRaw('COALESCE(SUM(line.debit - line.credit), 0) balance')->value('balance');
        $expenses = (clone $journalLines)->where('account.type', 'expense')->whereBetween('journal.transaction_date', [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()])->selectRaw('COALESCE(SUM(line.debit - line.credit), 0) balance')->value('balance');
        $sales = (string) $monthInvoices->sum('total');
        $ar = $open->reduce(fn ($total, $invoice) => bcadd($total, bcsub($invoice->total, $invoice->amount_paid, 4), 4), '0.0000');
        $activity = collect()
            ->merge($scope($company->journals()->latest())->limit(6)->get()->map(fn ($row) => ['date' => $row->transaction_date, 'type' => 'Journal', 'reference' => $row->journal_number, 'status' => $row->status]))
            ->merge($scope($company->salesInvoices()->latest())->limit(6)->get()->map(fn ($row) => ['date' => $row->invoice_date, 'type' => 'Sales invoice', 'reference' => $row->invoice_number, 'status' => $row->status]))
            ->sortByDesc('date')->take(8);

        return ['cash' => (string) $cash, 'receivables' => $ar, 'sales' => $sales, 'expenses' => (string) $expenses, 'open_invoices' => $open->count(), 'overdue_invoices' => $open->filter(fn ($invoice) => $invoice->due_date?->isPast())->count(), 'activity' => $activity];
    }
}

<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class AccountingReportService
{
    private function balances(Company $c, ?string $from = null, ?string $to = null): Collection
    {
        $q = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->join('accounts as a', 'a.id', '=', 'l.account_id')->where('j.company_id', $c->id)->whereIn('j.status', ['posted', 'reversed'])->select('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance', DB::raw('SUM(l.debit) as debits'), DB::raw('SUM(l.credit) as credits'))->groupBy('a.id', 'a.code', 'a.name', 'a.type', 'a.normal_balance')->orderBy('a.code');
        if ($from) {
            $q->whereDate('j.transaction_date', '>=', $from);
        }if ($to) {
            $q->whereDate('j.transaction_date', '<=', $to);
        }

return $q->get();
    }

    public function generalLedger(Company $c, int $accountId, ?string $from = null, ?string $to = null): Collection
    {
        $account = $c->accounts()->findOrFail($accountId);
        $q = DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.company_id', $c->id)->where('l.account_id', $account->id)->whereIn('j.status', ['posted', 'reversed'])->orderBy('j.transaction_date')->orderBy('j.id')->select('j.transaction_date', 'j.journal_number', 'j.reference', 'j.description', 'l.debit', 'l.credit');
        if ($from) {
            $q->whereDate('j.transaction_date', '>=', $from);
        }if ($to) {
            $q->whereDate('j.transaction_date', '<=', $to);
        }$running = '0.0000';

        return $q->get()->map(function ($r) use (&$running, $account) {
            $change = in_array($account->type, ['asset', 'expense']) ? bcsub($r->debit, $r->credit, 4) : bcsub($r->credit, $r->debit, 4);
            $running = bcadd($running, $change, 4);
            $r->running_balance = $running;

            return $r;
        });
    }

    public function trialBalance(Company $c, ?string $to = null): array
    {
        $rows = $this->balances($c, null, $to)->map(function ($r) {
            $net = bcsub($r->debits, $r->credits, 4);
            $r->debit_balance = bccomp($net, '0', 4) > 0 ? $net : '0.0000';
            $r->credit_balance = bccomp($net, '0', 4) < 0 ? bcmul($net, '-1', 4) : '0.0000';

            return $r;
        });

        return ['rows' => $rows, 'debit' => $rows->reduce(fn ($x, $r) => bcadd($x, $r->debit_balance, 4), '0.0000'), 'credit' => $rows->reduce(fn ($x, $r) => bcadd($x, $r->credit_balance, 4), '0.0000')];
    }

    public function profitAndLoss(Company $c, ?string $from = null, ?string $to = null): array
    {
        $rows = $this->balances($c, $from, $to);
        $revenue = $rows->where('type', 'revenue')->reduce(fn ($x, $r) => bcadd($x, bcsub($r->credits, $r->debits, 4), 4), '0.0000');
        $expenses = $rows->where('type', 'expense')->reduce(fn ($x, $r) => bcadd($x, bcsub($r->debits, $r->credits, 4), 4), '0.0000');

        return compact('revenue', 'expenses') + ['net' => bcsub($revenue, $expenses, 4)];
    }

    public function balanceSheet(Company $c, ?string $to = null): array
    {
        $rows = $this->balances($c, null, $to);
        $sum = fn ($type, $debit) => $rows->where('type', $type)->reduce(fn ($x, $r) => bcadd($x, $debit ? bcsub($r->debits, $r->credits, 4) : bcsub($r->credits,$r->debits,4), 4), '0.0000');
        $assets = $sum('asset',true);
        $liabilities = $sum('liability',false);
        $equity = $sum('equity',false);
        $earnings = $this->profitAndLoss($c,null,$to)['net'];

        return compact('assets','liabilities','equity','earnings') + ['liabilities_and_equity' => bcadd(bcadd($liabilities,$equity,4),$earnings,4)];
    }
}

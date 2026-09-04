<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierCredit;
use App\Models\SupplierPayment;
use Illuminate\Support\Collection;

final class PayablesReportService
{
    public function outstanding(Company $c, ?int $supplier = null, ?int $branch = null, ?int $year = null): Collection
    {
        return $c->supplierBills()->with('supplier')->whereIn('status', ['posted', 'partially_paid'])->when($supplier, fn ($q) => $q->where('supplier_id', $supplier))->when($branch, fn ($q) => $q->where('branch_id', $branch))->when($year, fn ($q) => $q->where('financial_year_id', $year))->orderBy('due_date')->get()->filter(fn ($b) => bccomp($b->amount_due, '0', 4) > 0);
    }

    public function aging(Company $c, ?string $asOf = null, ?int $branch = null, ?int $year = null): array
    {
        $date = now()->parse($asOf ?? today());
        $rows = $this->outstanding($c, null, $branch, $year)->map(function ($b) use ($date) {
            $days = max(0, $b->due_date->diffInDays($date, false));
            $b->bucket = $days === 0 ? 'Current' : ($days <= 30 ? '1–30' : ($days <= 60 ? '31–60' : ($days <= 90 ? '61–90' : '90+')));

            return $b;
        });
        $totals = [];
        foreach (['Current', '1–30', '31–60', '61–90', '90+'] as $bucket) {
            $totals[$bucket] = $rows->where('bucket', $bucket)->reduce(fn ($sum, $b) => bcadd($sum, $b->amount_due, 4), '0.0000');
        }

        return compact('rows', 'totals');
    }

    public function statement(Company $c, Supplier $supplier, ?string $from = null, ?string $to = null, ?int $branch = null, ?int $year = null): array
    {
        $entries = collect();
        $range = fn ($q, $date) => $q
            ->when($from, fn ($x) => $x->whereDate($date, '>=', $from))
            ->when($to, fn ($x) => $x->whereDate($date, '<=', $to))
            ->when($branch, fn ($x) => $x->where('branch_id', $branch))
            ->when($year, fn ($x) => $x->where('financial_year_id', $year));
        foreach ($range(SupplierBill::where('company_id', $c->id)->where('supplier_id', $supplier->id)->whereNot('status', 'draft'), 'bill_date')->get() as $b) {
            $entries->push((object) ['date' => $b->bill_date, 'type' => 'Supplier Bill', 'number' => $b->bill_number, 'debit' => '0.0000', 'credit' => $b->total]);
        }foreach ($range(SupplierCredit::where('company_id', $c->id)->where('supplier_id', $supplier->id)->where('status', 'posted'), 'credit_date')->get() as $x) {
            $entries->push((object) ['date' => $x->credit_date, 'type' => 'Supplier Credit', 'number' => $x->credit_number, 'debit' => $x->total, 'credit' => '0.0000']);
        }foreach ($range(SupplierPayment::where('company_id', $c->id)->where('supplier_id', $supplier->id)->where('status', 'posted'), 'payment_date')->get() as $x) {
            $entries->push((object) ['date' => $x->payment_date, 'type' => 'Supplier Payment', 'number' => $x->payment_number, 'debit' => $x->amount, 'credit' => '0.0000']);
        }$running = '0.0000';
        $entries = $entries->sortBy('date')->values()->map(function ($e) use (&$running) {
            $running = bcadd($running, bcsub($e->credit, $e->debit, 4), 4);
            $e->balance = $running;

            return $e;
        });

        return ['opening' => '0.0000', 'entries' => $entries, 'closing' => $running];
    }
}

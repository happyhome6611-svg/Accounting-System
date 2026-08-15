<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use Illuminate\Support\Collection;

final class ReceivablesReportService
{
    public function outstanding(Company $c, ?int $customer = null): Collection
    {
        $q = $c->hasMany(SalesInvoice::class)->with('customer')->whereIn('status', ['posted', 'partially_paid']);
        if ($customer) {
            $q->where('customer_id', $customer);
        }

        return $q->orderBy('due_date')->get()->filter(fn ($invoice) => bccomp($invoice->amount_due, '0', 4) > 0)->map(function ($i) {
            $i->outstanding = $i->amount_due;
            $i->is_overdue = $i->due_date->isPast();

            return $i;
        });
    }

    public function aging(Company $c, ?string $asOf = null): array
    {
        $date = now()->parse($asOf ?? today());
        $rows = $this->outstanding($c)->map(function ($i) use ($date) {
            $days = max(0, $i->due_date->diffInDays($date, false));
            $i->bucket = $days === 0 ? 'Current' : ($days <= 30 ? '1–30' : ($days <= 60 ? '31–60' : ($days <= 90 ? '61–90' : '90+')));

            return $i;
        });
        $totals = [];
        foreach (['Current', '1–30', '31–60', '61–90', '90+'] as $b) {
            $totals[$b] = $rows->where('bucket', $b)->reduce(fn ($x, $i) => bcadd($x, $i->outstanding, 4), '0.0000');
        }

        return compact('rows', 'totals');
    }

    public function statement(Company $c, Customer $customer, ?string $from = null, ?string $to = null): array
    {
        $invoices = $customer->invoices()->where('company_id', $c->id)->whereNot('status', 'draft');
        if ($to) {
            $invoices->whereDate('invoice_date', '<=', $to);
        }$all = $invoices->get();
        $opening = $from ? $all->where('invoice_date', '<', $from)->reduce(fn ($x, $i) => bcadd($x, $i->total, 4), '0.0000') : '0.0000';
        $entries = collect();
        foreach ($all->filter(fn ($i) => ! $from || $i->invoice_date->gte($from)) as $i) {
            $entries->push((object) ['date' => $i->invoice_date, 'type' => 'Invoice', 'number' => $i->invoice_number, 'debit' => $i->total, 'credit' => '0.0000']);
        }$credits = SalesCreditNote::where('company_id', $c->id)->where('customer_id', $customer->id)->where('status', 'posted');
        if ($from) {
            $credits->whereDate('credit_note_date', '>=', $from);
        }
        if ($to) {
            $credits->whereDate('credit_note_date', '<=', $to);
        }
        foreach ($credits->get() as $credit) {
            $entries->push((object) ['date' => $credit->credit_note_date, 'type' => 'Credit Note', 'number' => $credit->credit_note_number, 'debit' => '0.0000', 'credit' => $credit->total]);
        }
        $receipts = CustomerReceipt::where('company_id', $c->id)->where('customer_id', $customer->id)->where('status', 'posted');
        if ($from) {
            $receipts->whereDate('receipt_date', '>=', $from);
        }if ($to) {
            $receipts->whereDate('receipt_date', '<=', $to);
        }foreach ($receipts->get() as $r) {
            $entries->push((object) ['date' => $r->receipt_date, 'type' => 'Receipt', 'number' => $r->receipt_number, 'debit' => '0.0000', 'credit' => $r->amount]);
        }$running = $opening;
        $entries = $entries->sortBy('date')->values()->map(function ($e) use (&$running) {
            $running = bcadd($running, bcsub($e->debit, $e->credit, 4), 4);
            $e->balance = $running;

            return $e;
        });

        return ['opening' => $opening, 'entries' => $entries, 'closing' => $running];
    }
}

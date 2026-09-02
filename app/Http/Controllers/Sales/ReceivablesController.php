<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\ReceivablesReportService;
use Illuminate\Http\Request;

class ReceivablesController extends Controller
{
    public function __construct(private ReceivablesReportService $reports) {}

    private function company(Request $r)
    {
        return $r->user()->companies()->findOrFail($r->integer('company_id'));
    }

    public function ar(Request $r)
    {
        $c = $this->company($r);

        return view('sales.reports.ar', compact('c') + ['rows' => $this->reports->outstanding($c, $r->integer('customer_id') ?: null)]);
    }

    public function aging(Request $r)
    {
        $c = $this->company($r);

        return view('sales.reports.aging', compact('c') + ['report' => $this->reports->aging($c, $r->as_of)]);
    }

    public function statement(Request $r)
    {
        $c = $this->company($r);
        $customer = Customer::where('company_id', $c->id)->findOrFail($r->integer('customer_id'));

        return view('sales.reports.statement', compact('c', 'customer') + ['report' => $this->reports->statement($c, $customer, $r->from, $r->to)]);
    }
}

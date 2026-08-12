<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerReceipt;
use App\Models\Item;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Services\SalesService;
use Illuminate\Http\Request;

class SalesController extends Controller
{
    private function company(Request $r, Company $company): Company
    {
        return $r->user()->companies()->findOrFail($company->id);
    }

    public function index(Request $r)
    {
        return view('sales.index', ['companies' => $r->user()->companies()->get()]);
    }

    public function customers(Request $r, Company $company)
    {
        $company = $this->company($r, $company);

        return view('sales.customers', compact('company') + ['customers' => Customer::where('company_id', $company->id)->orderBy('code')->get()]);
    }

    public function storeCustomer(Request $r, Company $company, SalesService $s)
    {
        $company = $this->company($r, $company);
        $data = $r->validate(['code' => 'nullable|string|max:40', 'name' => 'required|string', 'legal_name' => 'nullable|string', 'email' => 'nullable|email', 'phone' => 'nullable|string', 'currency_id' => 'required|integer', 'receivable_account_id' => 'required|integer', 'payment_terms_days' => 'required|integer|min:0', 'credit_limit' => 'required|numeric|min:0']);
        $s->createCustomer($company, $data + ['is_active' => true], $r->user());

        return back();
    }

    public function items(Request $r, Company $company)
    {
        $company = $this->company($r, $company);

        return view('sales.items', compact('company') + ['items' => Item::where('company_id', $company->id)->orderBy('code')->get()]);
    }

    public function storeItem(Request $r, Company $company, SalesService $s)
    {
        $company = $this->company($r, $company);
        $data = $r->validate(['code' => 'required|string|max:40', 'name' => 'required|string', 'type' => 'required|in:product,service', 'unit' => 'required|string', 'sales_price' => 'required|numeric|min:0', 'revenue_account_id' => 'required|integer']);
        $s->createItem($company, $data + ['is_active' => true], $r->user());

        return back();
    }

    public function invoices(Request $r, Company $company)
    {
        $company = $this->company($r, $company);

        return view('sales.invoices', compact('company') + ['invoices' => SalesInvoice::where('company_id', $company->id)->with('customer')->latest()->get()]);
    }

    public function documents(Request $r, Company $company, string $type)
    {
        $company = $this->company($r, $company);
        $types = [
            'quotations' => [SalesQuotation::class, 'Sales Quotations', 'quotation_number', 'quotation_date'],
            'orders' => [SalesOrder::class, 'Sales Orders', 'order_number', 'order_date'],
            'credit-notes' => [SalesCreditNote::class, 'Credit Notes', 'credit_note_number', 'credit_note_date'],
            'receipts' => [CustomerReceipt::class, 'Customer Receipts', 'receipt_number', 'receipt_date'],
        ];
        abort_unless(isset($types[$type]), 404);
        [$model, $title, $number, $date] = $types[$type];
        $documents = $model::where('company_id', $company->id)->latest()->get();

        return view('sales.documents', compact('company', 'documents', 'title', 'number', 'date'));
    }

    public function postInvoice(Request $r, Company $company, SalesInvoice $invoice, SalesService $s)
    {
        $company = $this->company($r, $company);
        abort_unless($invoice->company_id === $company->id, 404);
        $s->postInvoice($invoice, $r->user());

        return back();
    }
}

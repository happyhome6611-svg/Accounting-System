<?php

namespace App\Http\Controllers\Sales;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CustomerReceipt;
use App\Models\SalesCreditNote;
use App\Models\SalesInvoice;
use App\Models\SalesOrder;
use App\Models\SalesQuotation;
use App\Services\MoneyFormatter;
use App\Services\SalesService;
use App\Services\SalesWorkflowService;
use Illuminate\Http\Request;

class SalesTransactionController extends Controller
{
    private const TYPES = [
        'quotations' => [SalesQuotation::class, 'Sales Quotations', 'Quotation', 'quotation_number', 'quotation_date'],
        'orders' => [SalesOrder::class, 'Sales Orders', 'Sales Order', 'order_number', 'order_date'],
        'invoices' => [SalesInvoice::class, 'Sales Invoices', 'Sales Invoice', 'invoice_number', 'invoice_date'],
        'credit-notes' => [SalesCreditNote::class, 'Credit Notes', 'Credit Note', 'credit_note_number', 'credit_note_date'],
        'receipts' => [CustomerReceipt::class, 'Customer Receipts', 'Customer Receipt', 'receipt_number', 'receipt_date'],
    ];

    public function index(Request $request, Company $company, string $type, MoneyFormatter $money)
    {
        $company = $this->company($request, $company)->load('baseCurrency');
        [$model, $title, $singular, $number, $date] = $this->definition($type);
        $documents = $model::where('company_id', $company->id)->with(['customer', 'branch'])->latest()->get();

        return view('sales.transactions.index', compact('company', 'type', 'title', 'singular', 'number', 'date', 'documents', 'money'));
    }

    public function invoicesIndex(Request $request, Company $company, MoneyFormatter $money)
    {
        return $this->index($request, $company, 'invoices', $money);
    }

    public function legacyIndex(Request $request, Company $company, string $type, MoneyFormatter $money)
    {
        return $this->index($request, $company, $type, $money);
    }

    public function create(Request $request, Company $company, string $type)
    {
        $company = $this->company($request, $company);
        $this->definition($type);

        return view('sales.transactions.form', $this->formData($company, $type) + ['document' => null]);
    }

    public function store(Request $request, Company $company, string $type, SalesWorkflowService $workflow)
    {
        $company = $this->company($request, $company);
        $document = $workflow->create($company, $type, $request->validate($this->rules($type)), $request->user());

        return redirect()->route('sales.transactions.show', [$company, $type, $document]);
    }

    public function show(Request $request, Company $company, string $type, int $document, MoneyFormatter $money)
    {
        $company = $this->company($request, $company)->load('baseCurrency');
        [$model, $title, $singular, $number, $date] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->with(['customer', 'branch'])->findOrFail($document);
        if (method_exists($document, 'lines')) {
            $document->load('lines');
        }
        if (method_exists($document, 'allocations')) {
            $document->load('allocations.invoice');
        }

        return view('sales.transactions.show', compact('company', 'type', 'title', 'singular', 'number', 'date', 'document', 'money') + $this->formData($company, $type));
    }

    public function edit(Request $request, Company $company, string $type, int $document)
    {
        $company = $this->company($request, $company);
        [$model] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->findOrFail($document);
        abort_unless($document->status === 'draft', 403);
        if (method_exists($document, 'lines')) {
            $document->load('lines');
        }
        if (method_exists($document, 'allocations')) {
            $document->load('allocations');
        }

        return view('sales.transactions.form', $this->formData($company, $type) + compact('document'));
    }

    public function update(Request $request, Company $company, string $type, int $document, SalesWorkflowService $workflow)
    {
        $company = $this->company($request, $company);
        [$model] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->findOrFail($document);
        $workflow->update($company, $type, $document, $request->validate($this->rules($type)), $request->user());

        return redirect()->route('sales.transactions.show', [$company, $type, $document])->with('success', 'Draft updated.');
    }

    public function destroy(Request $request, Company $company, string $type, int $document, SalesWorkflowService $workflow)
    {
        $company = $this->company($request, $company);
        [$model, , , $number] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->findOrFail($document);
        $data = $request->validate(['confirmation' => 'required|string']);
        abort_unless(hash_equals($document->{$number}, $data['confirmation']), 422);
        $workflow->delete($company, $document, $request->user());

        return redirect()->route('sales.transactions.index', [$company, $type])->with('success', 'Draft permanently deleted.');
    }

    public function convertQuotation(Request $request, Company $company, SalesQuotation $quotation, SalesWorkflowService $workflow)
    {
        $company = $this->company($request, $company);
        abort_unless($quotation->company_id === $company->id, 404);
        $order = $workflow->quotationToOrder($company, $quotation, $request->user());

        return redirect()->route('sales.transactions.show', [$company, 'orders', $order]);
    }

    public function convertOrder(Request $request, Company $company, SalesOrder $order, SalesWorkflowService $workflow)
    {
        $company = $this->company($request, $company);
        abort_unless($order->company_id === $company->id, 404);
        $data = $request->validate(['accounting_period_id' => 'required|integer', 'invoice_date' => 'required|date', 'due_date' => 'required|date|after_or_equal:invoice_date']);
        $invoice = $workflow->orderToInvoice($company, $order, $data, $request->user());

        return redirect()->route('sales.transactions.show', [$company, 'invoices', $invoice]);
    }

    public function post(Request $request, Company $company, string $type, int $document, SalesService $sales)
    {
        $company = $this->company($request, $company);
        [$model] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->findOrFail($document);
        match ($type) {
            'invoices' => $sales->postInvoice($document, $request->user()), 'credit-notes' => $sales->postCreditNote($document, $request->user()), 'receipts' => $sales->postReceipt($document, $request->user()), default => abort(404)
        };

        return back()->with('success', $type === 'receipts' ? 'Receipt posted.' : 'Document posted.');
    }

    private function formData(Company $company, string $type): array
    {
        [, $title, $singular] = $this->definition($type);

        return compact('company', 'type', 'title', 'singular') + ['company' => $company->load('baseCurrency'), 'branches' => $company->branches()->where('is_active', true)->get(), 'customers' => $company->customers()->where('is_active', true)->orderBy('name')->get(), 'items' => $company->items()->where('is_active', true)->with('revenueAccount')->orderBy('name')->get(), 'accounts' => $company->accounts()->where('is_active', true)->orderBy('code')->get(), 'years' => $company->financialYears()->with(['periods' => fn ($q) => $q->where('status', 'open')])->get(), 'invoices' => $company->salesInvoices()->whereIn('status', ['posted', 'partially_paid'])->with('customer')->get()];
    }

    private function rules(string $type): array
    {
        $common = ['customer_id' => 'required|integer', 'branch_id' => 'nullable|integer', 'financial_year_id' => 'nullable|integer'];
        if ($type === 'receipts') {
            return $common + ['accounting_period_id' => 'required|integer', 'receipt_date' => 'required|date', 'amount' => 'required|numeric|min:0.01', 'payment_method' => 'required|string|max:32', 'reference' => 'nullable|string|max:255', 'receiving_account_id' => 'required|integer', 'allocations' => 'nullable|array', 'allocations.*.sales_invoice_id' => 'required|integer', 'allocations.*.amount' => 'required|numeric|min:0'];
        }
        $lineRules = ['lines' => 'required|array|min:1', 'lines.*.item_id' => 'nullable|integer', 'lines.*.revenue_account_id' => 'required|integer', 'lines.*.description' => 'required|string', 'lines.*.quantity' => 'required|numeric|min:0.0001', 'lines.*.unit_price' => 'required|numeric|min:0', 'lines.*.discount' => 'nullable|numeric|min:0'];

        return match ($type) {
            'quotations' => $common + ['quotation_date' => 'required|date', 'expiry_date' => 'nullable|date|after_or_equal:quotation_date', 'customer_reference' => 'nullable|string|max:255', 'notes' => 'nullable|string'] + $lineRules,
            'orders' => $common + ['order_date' => 'required|date', 'customer_reference' => 'nullable|string|max:255', 'notes' => 'nullable|string'] + $lineRules,
            'invoices' => $common + ['accounting_period_id' => 'required|integer', 'invoice_date' => 'required|date', 'due_date' => 'required|date|after_or_equal:invoice_date', 'customer_reference' => 'nullable|string|max:255', 'notes' => 'nullable|string'] + $lineRules,
            'credit-notes' => $common + ['sales_invoice_id' => 'required|integer', 'accounting_period_id' => 'required|integer', 'credit_note_date' => 'required|date', 'notes' => 'nullable|string'] + $lineRules,
            default => abort(404),
        };
    }

    private function company(Request $request, Company $company): Company
    {
        return $request->user()->companies()->findOrFail($company->id);
    }

    private function definition(string $type): array
    {
        abort_unless(isset(self::TYPES[$type]), 404);

        return self::TYPES[$type];
    }
}

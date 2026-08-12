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
use App\Services\CustomerMaintenanceService;
use App\Services\ItemMaintenanceService;
use App\Services\MoneyFormatter;
use App\Services\SalesService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    public function customers(Request $r, Company $company, CustomerMaintenanceService $maintenance, MoneyFormatter $money)
    {
        $company = $this->company($r, $company)->load('baseCurrency');
        $customers = Customer::where('company_id', $company->id)->orderBy('code')->get();
        $deletable = $customers->mapWithKeys(fn ($customer) => [$customer->id => $maintenance->isDeletable($customer)]);

        return view('sales.customers', compact('company', 'customers', 'deletable', 'money'));
    }

    public function editCustomer(Request $r, Company $company, Customer $customer, CustomerMaintenanceService $maintenance, MoneyFormatter $money)
    {
        $company = $this->company($r, $company)->load('baseCurrency');
        abort_unless($customer->company_id === $company->id, 404);

        return view('sales.customer-edit', compact('company', 'customer', 'money') + ['deletable' => $maintenance->isDeletable($customer), 'blockers' => $maintenance->blockers($customer)]);
    }

    public function updateCustomer(Request $r, Company $company, Customer $customer, CustomerMaintenanceService $service)
    {
        $company = $this->company($r, $company);
        abort_unless($customer->company_id === $company->id, 404);
        $data = $r->validate($this->customerRules($company, $customer));
        $service->update($company, $customer, $data, $r->user());

        return redirect()->route('sales.customers', $company)->with('success', 'Customer updated.');
    }

    public function customerStatus(Request $r, Company $company, Customer $customer, CustomerMaintenanceService $service)
    {
        $company = $this->company($r, $company);
        abort_unless($customer->company_id === $company->id, 404);
        $data = $r->validate(['is_active' => ['required', 'boolean']]);
        $service->setActive($company, $customer, (bool) $data['is_active'], $r->user());

        return back()->with('success', $data['is_active'] ? 'Customer reactivated.' : 'Customer deactivated.');
    }

    public function confirmCustomerDelete(Request $r, Company $company, Customer $customer, CustomerMaintenanceService $service)
    {
        $company = $this->company($r, $company);
        abort_unless($customer->company_id === $company->id, 404);
        $blockers = $service->blockers($customer);

        return view('sales.customer-delete', compact('company', 'customer', 'blockers'));
    }

    public function destroyCustomer(Request $r, Company $company, Customer $customer, CustomerMaintenanceService $service)
    {
        $company = $this->company($r, $company);
        abort_unless($customer->company_id === $company->id, 404);
        $data = $r->validate(['confirmation_name' => ['required', 'string']]);
        $service->delete($company, $customer, $r->user(), $data['confirmation_name']);

        return redirect()->route('sales.customers', $company)->with('success', 'Unused customer permanently deleted.');
    }

    private function customerRules(Company $company, Customer $customer): array
    {
        return ['code' => ['required', 'string', 'max:40', Rule::unique('customers')->where('company_id', $company->id)->ignore($customer->id)], 'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'string', 'max:255'], 'type' => ['required', Rule::in(['business', 'individual'])], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:40'], 'billing_address' => ['nullable', 'string'], 'shipping_address' => ['nullable', 'string'], 'country_id' => ['nullable', 'exists:countries,id'], 'currency_id' => ['required', 'exists:currencies,id'], 'tax_identifiers' => ['nullable', 'array'], 'payment_terms_days' => ['required', 'integer', 'min:0'], 'credit_limit' => ['required', 'numeric', 'min:0'], 'receivable_account_id' => ['required', 'integer']];
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
        $company = $this->company($r, $company)->load('baseCurrency');
        $items = Item::where('company_id', $company->id)->orderBy('code')->get();
        $maintenance = app(ItemMaintenanceService::class);
        $deletable = $items->mapWithKeys(fn ($item) => [$item->id => $maintenance->isDeletable($item)]);

        return view('sales.items', compact('company', 'items', 'deletable') + ['money' => app(MoneyFormatter::class)]);
    }

    public function editItem(Request $r, Company $company, Item $item, ItemMaintenanceService $service)
    {
        $company = $this->company($r, $company)->load('baseCurrency');
        abort_unless($item->company_id === $company->id, 404);

        return view('sales.item-edit', compact('company', 'item') + ['money' => app(MoneyFormatter::class), 'deletable' => $service->isDeletable($item)]);
    }

    public function updateItem(Request $r, Company $company, Item $item, ItemMaintenanceService $service)
    {
        $company = $this->company($r, $company);
        abort_unless($item->company_id === $company->id, 404);
        $data = $r->validate($this->itemRules($company, $item));
        $service->update($company, $item, $data, $r->user());

        return redirect()->route('sales.items', $company)->with('success', 'Item updated.');
    }

    public function itemStatus(Request $r, Company $company, Item $item, ItemMaintenanceService $service)
    {
        $company = $this->company($r, $company);
        abort_unless($item->company_id === $company->id, 404);
        $data = $r->validate(['is_active' => 'required|boolean']);
        $service->setActive($company, $item, (bool) $data['is_active'], $r->user());

        return back();
    }

    public function confirmItemDelete(Request $r, Company $company, Item $item, ItemMaintenanceService $service)
    {
        $company = $this->company($r, $company);
        abort_unless($item->company_id === $company->id, 404);

        return view('sales.item-delete', compact('company', 'item') + ['blockers' => $service->blockers($item)]);
    }

    public function destroyItem(Request $r, Company $company, Item $item, ItemMaintenanceService $service)
    {
        $company = $this->company($r, $company);
        abort_unless($item->company_id === $company->id, 404);
        $data = $r->validate(['confirmation_name' => 'required|string']);
        $service->delete($company, $item, $r->user(), $data['confirmation_name']);

        return redirect()->route('sales.items', $company);
    }

    private function itemRules(Company $company, Item $item): array
    {
        return ['code' => ['required', 'string', 'max:40', Rule::unique('items')->where('company_id', $company->id)->ignore($item->id)], 'name' => 'required|string|max:255', 'description' => 'nullable|string', 'type' => ['required', Rule::in(['product', 'service'])], 'unit' => 'required|string|max:20', 'sales_price' => 'required|numeric|min:0', 'revenue_account_id' => 'required|integer', 'tax_category' => 'nullable|string'];
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

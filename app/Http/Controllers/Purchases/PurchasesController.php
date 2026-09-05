<?php

namespace App\Http\Controllers\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBill;
use App\Models\SupplierCredit;
use App\Models\SupplierPayment;
use App\Services\CountryJurisdictionService;
use App\Services\MoneyFormatter;
use App\Services\PayablesReportService;
use App\Services\PurchaseService;
use App\Services\SupplierMaintenanceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchasesController extends Controller
{
    private const TYPES = ['orders' => [PurchaseOrder::class, 'Purchase Orders', 'purchase_order_number', 'order_date'], 'bills' => [SupplierBill::class, 'Supplier Bills', 'bill_number', 'bill_date'], 'credits' => [SupplierCredit::class, 'Supplier Credits', 'credit_number', 'credit_date'], 'payments' => [SupplierPayment::class, 'Supplier Payments', 'payment_number', 'payment_date']];

    public function index(Request $r, CountryJurisdictionService $j)
    {
        $countries = $j->countriesFor($r->user(), false);
        $country = $r->filled('country_id') ? $j->country($r->integer('country_id')) : $countries->first();
        abort_if($country && ! $countries->contains('id', $country->id), 404);
        $companies = $country ? $j->entities($r->user(), $country, true) : collect();

        return view('purchases.index', compact('countries', 'country', 'companies'));
    }

    public function suppliers(Request $r, Company $company, SupplierMaintenanceService $s)
    {
        $company = $this->company($r, $company)->load('baseCurrency');
        $suppliers = $company->suppliers()->orderBy('code')->get();
        $deletable = $suppliers->mapWithKeys(fn ($x) => [$x->id => $s->blockers($x) === []]);

        return view('purchases.suppliers', compact('company', 'suppliers', 'deletable') + $this->masterData($company));
    }

    public function storeSupplier(Request $r, Company $company, SupplierMaintenanceService $s)
    {
        $company = $this->company($r, $company);
        $s->create($company, $r->validate($this->supplierRules($company)), $r->user());

        return back()->with('success', 'Supplier created.');
    }

    public function editSupplier(Request $r, Company $company, Supplier $supplier)
    {
        $company = $this->company($r, $company);
        abort_unless($supplier->company_id === $company->id, 404);

        return view('purchases.supplier-edit', compact('company', 'supplier') + $this->masterData($company));
    }

    public function updateSupplier(Request $r, Company $company, Supplier $supplier, SupplierMaintenanceService $s)
    {
        $company = $this->company($r, $company);
        abort_unless($supplier->company_id === $company->id, 404);
        $s->update($company, $supplier, $r->validate($this->supplierRules($company, $supplier)), $r->user());

        return redirect()->route('purchases.suppliers', $company)->with('success', 'Supplier updated.');
    }

    public function supplierStatus(Request $r, Company $company, Supplier $supplier, SupplierMaintenanceService $s)
    {
        $company = $this->company($r, $company);
        abort_unless($supplier->company_id === $company->id, 404);
        $s->setActive($company, $supplier, $r->boolean('is_active'), $r->user());

        return back();
    }

    public function deleteSupplier(Request $r, Company $company, Supplier $supplier, SupplierMaintenanceService $s)
    {
        $company = $this->company($r, $company);
        abort_unless($supplier->company_id === $company->id, 404);
        $s->delete($company, $supplier, $r->validate(['confirmation_name' => 'required|string'])['confirmation_name'], $r->user());

        return back()->with('success', 'Unused supplier deleted.');
    }

    public function documents(Request $r, Company $company, string $type, MoneyFormatter $money)
    {
        $company = $this->company($r, $company)->load('baseCurrency');
        [$model,$title,$number,$date] = $this->definition($type);
        $documents = $model::where('company_id', $company->id)->with(['supplier'])->latest()->get();

        return view('purchases.documents', compact('company', 'type', 'title', 'number', 'date', 'documents', 'money'));
    }

    public function createDocument(Request $r, Company $company, string $type)
    {
        $company = $this->company($r, $company);
        $this->definition($type);

        return view('purchases.form', $this->formData($company, $type) + ['document' => null]);
    }

    public function storeDocument(Request $r, Company $company, string $type, PurchaseService $s)
    {
        $company = $this->company($r, $company);
        $doc = $s->create($company, $type, $r->validate($this->rules($type)), $r->user());

        return redirect()->route('purchases.documents.show', [$company, $type, $doc]);
    }

    public function showDocument(Request $r, Company $company, string $type, int $document, MoneyFormatter $money)
    {
        $company = $this->company($r, $company)->load('baseCurrency');
        [$model,$title,$number,$date] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->with(['supplier'])->findOrFail($document);
        if (method_exists($document, 'lines')) {
            $document->load('lines');
        }if (method_exists($document, 'allocations')) {
            $document->load('allocations.bill');
        }

        return view('purchases.show', compact('company', 'type', 'title', 'number', 'date', 'document', 'money'));
    }

    public function editDocument(Request $r, Company $company, string $type, int $document)
    {
        $company = $this->company($r, $company);
        [$model] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->findOrFail($document);
        abort_unless($document->status === 'draft', 403);
        if (method_exists($document, 'lines')) {
            $document->load('lines');
        }if (method_exists($document, 'allocations')) {
            $document->load('allocations');
        }

        return view('purchases.form', $this->formData($company, $type) + compact('document'));
    }

    public function updateDocument(Request $r, Company $company, string $type, int $document, PurchaseService $s)
    {
        $company = $this->company($r, $company);
        [$model] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->findOrFail($document);
        $doc = $s->update($company, $type, $document, $r->validate($this->rules($type)), $r->user());

        return redirect()->route('purchases.documents.show', [$company, $type, $doc]);
    }

    public function destroyDocument(Request $r, Company $company, string $type, int $document, PurchaseService $s)
    {
        $company = $this->company($r, $company);
        [$model,,$number] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->findOrFail($document);
        abort_unless(hash_equals($document->{$number}, $r->validate(['confirmation' => 'required|string'])['confirmation']), 422);
        $s->deleteDraft($company, $document, $r->user());

        return redirect()->route('purchases.documents', [$company, $type]);
    }

    public function postDocument(Request $r, Company $company, string $type, int $document, PurchaseService $s)
    {
        $company = $this->company($r, $company);
        [$model] = $this->definition($type);
        $document = $model::where('company_id', $company->id)->findOrFail($document);
        $s->post($company, $type, $document, $r->user());

        return back()->with('success', 'Document posted.');
    }

    public function convertOrder(Request $r, Company $company, PurchaseOrder $order, PurchaseService $s)
    {
        $company = $this->company($r, $company);
        abort_unless($order->company_id === $company->id, 404);
        $data = $r->validate(['bill_date' => 'required|date', 'due_date' => 'required|date|after_or_equal:bill_date', 'financial_year_id' => 'nullable|integer', 'accounting_period_id' => 'nullable|integer']);
        $bill = $s->convertOrder($company, $order, $data, $r->user());

        return redirect()->route('purchases.documents.show', [$company, 'bills', $bill]);
    }

    public function report(Request $r, string $type, PayablesReportService $reports, MoneyFormatter $money)
    {
        $company = $this->companyId($r)->load('baseCurrency');
        $branch = $r->filled('branch_id') ? $company->branches()->findOrFail($r->integer('branch_id'))->id : null;
        $year = $r->filled('financial_year_id') ? $company->financialYears()->findOrFail($r->integer('financial_year_id'))->id : null;
        if ($type === 'ap') {
            $data = ['rows' => $reports->outstanding($company, $r->integer('supplier_id') ?: null, $branch, $year)];
        } elseif ($type === 'aging') {
            $data = $reports->aging($company, $r->input('as_of'), $branch, $year);
        } elseif ($type === 'statement') {
            $supplier = $company->suppliers()->findOrFail($r->integer('supplier_id'));
            $data = $reports->statement($company, $supplier, $r->from, $r->to, $branch, $year) + compact('supplier');
        } else {
            abort(404);
        }

        return view('purchases.report', compact('company', 'type', 'money') + $data);
    }

    private function company(Request $r, Company $c): Company
    {
        return $r->user()->companies()->where('entity_type', '!=', 'individual')->findOrFail($c->id);
    }

    private function companyId(Request $r): Company
    {
        return $r->user()->companies()->where('entity_type', '!=', 'individual')->findOrFail($r->integer('company_id'));
    }

    private function definition(string $t): array
    {
        abort_unless(isset(self::TYPES[$t]), 404);

        return self::TYPES[$t];
    }

    private function masterData(Company $c): array
    {
        return ['currencies' => Currency::where('is_active', true)->get(), 'countries' => Country::where('is_active', true)->get(), 'accounts' => $c->accounts()->where('is_active', true)->get()];
    }

    private function supplierRules(Company $c, ?Supplier $s = null): array
    {
        return ['code' => ['nullable', 'string', 'max:40', Rule::unique('suppliers')->where('company_id', $c->id)->ignore($s?->id)], 'name' => 'required|string|max:255', 'legal_name' => 'nullable|string|max:255', 'type' => ['required', Rule::in(['business', 'individual', 'contractor', 'other'])], 'email' => 'nullable|email', 'phone' => 'nullable|string|max:40', 'address' => 'nullable|string', 'country_id' => 'nullable|exists:countries,id', 'currency_id' => 'required|integer', 'payment_terms_days' => 'required|integer|min:0', 'credit_limit' => 'required|numeric|min:0', 'payable_account_id' => 'required|integer', 'notes' => 'nullable|string', 'is_active' => 'sometimes|boolean', 'default_purchase_tax_code_id' => 'nullable|integer'];
    }

    private function formData(Company $c, string $t): array
    {
        return ['company' => $c->load('baseCurrency'), 'type' => $t, 'title' => self::TYPES[$t][1], 'suppliers' => $c->suppliers()->where('is_active', true)->get(), 'branches' => $c->branches()->where('is_active', true)->get(), 'items' => $c->items()->where('is_active', true)->get(), 'accounts' => $c->accounts()->where('is_active', true)->whereIn('type', ['expense', 'asset'])->get(), 'taxCodes' => $c->taxCodes()->where('is_active', true)->orderBy('code')->get(), 'years' => $c->financialYears()->with('periods')->get(), 'bills' => $c->supplierBills()->whereIn('status', ['posted', 'partially_paid'])->with('supplier')->get()];
    }

    private function rules(string $t): array
    {
        $common = ['supplier_id' => 'required|integer', 'branch_id' => 'nullable|integer', 'financial_year_id' => 'nullable|integer'];
        if ($t === 'payments') {
            return $common + ['accounting_period_id' => 'nullable|integer', 'payment_date' => 'required|date', 'payment_account_id' => 'required|integer', 'amount' => 'required|numeric|min:0.0001', 'reference' => 'nullable|string', 'notes' => 'nullable|string', 'allocations' => 'required|array|min:1', 'allocations.*.supplier_bill_id' => 'required|integer', 'allocations.*.amount' => 'required|numeric|min:0'];
        }$lines = ['lines' => 'required|array|min:1', 'lines.*.item_id' => 'nullable|integer', 'lines.*.expense_account_id' => 'required|integer', 'lines.*.description' => 'required|string', 'lines.*.quantity' => 'required|numeric|min:0.0001', 'lines.*.unit_price' => 'required|numeric|min:0', 'lines.*.discount' => 'nullable|numeric|min:0', 'lines.*.tax_code_id' => 'nullable|integer', 'lines.*.tax_inclusive' => 'nullable|boolean'];

        return match ($t) {
            'orders' => $common + ['order_date' => 'required|date', 'expected_date' => 'nullable|date|after_or_equal:order_date', 'supplier_reference' => 'nullable|string', 'notes' => 'nullable|string'] + $lines,'bills' => $common + ['accounting_period_id' => 'nullable|integer', 'bill_date' => 'required|date', 'due_date' => 'required|date|after_or_equal:bill_date', 'purchase_order_id' => 'nullable|integer', 'supplier_reference' => 'nullable|string', 'notes' => 'nullable|string'] + $lines,'credits' => $common + ['accounting_period_id' => 'nullable|integer', 'supplier_bill_id' => 'required|integer', 'credit_date' => 'required|date', 'supplier_reference' => 'nullable|string', 'notes' => 'nullable|string'] + $lines,default => abort(404)
        };
    }
}

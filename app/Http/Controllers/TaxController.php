<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\TaxCode;
use App\Models\TaxPeriod;
use App\Models\TaxRegistration;
use App\Services\CountryJurisdictionService;
use App\Services\TaxAdjustmentService;
use App\Services\TaxConfigurationService;
use App\Services\TaxPeriodService;
use App\Services\TaxReportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaxController extends Controller
{
    public function index(Request $request, CountryJurisdictionService $jurisdictions)
    {
        return view('tax.index', ['countries' => $jurisdictions->countriesFor($request->user(), false)]);
    }

    public function country(Request $request, string $country, CountryJurisdictionService $jurisdictions)
    {
        $country = $jurisdictions->country($country);

        return view('tax.country', ['country' => $country, 'companies' => $jurisdictions->entities($request->user(), $country)]);
    }

    public function workspace(Request $request, string $country, Company $company, TaxReportService $reports)
    {
        $company = $this->context($request, $country, $company)->load(['country', 'taxSetting']);
        $registrations = $company->taxRegistrations()->with('periods')->orderBy('tax_type')->get();
        $codes = $company->taxCodes()->with('rates')->orderBy('code')->get();
        $register = $reports->register($company, $request->only(['tax_period_id', 'tax_code_id', 'treatment']));
        $summary = $reports->summary($company, $request->only('tax_period_id'));
        $accounts = $company->accounts()->where('is_active', true)->orderBy('code')->get();
        $branches = $company->supportsBranches() ? $company->branches()->where('is_active', true)->orderBy('name')->get() : collect();

        return view('tax.workspace', compact('company', 'registrations', 'codes', 'register', 'summary', 'accounts', 'branches'));
    }

    public function storeRegistration(Request $request, string $country, Company $company, TaxConfigurationService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->registration($company, $request->validate(['tax_type' => ['required', Rule::in(['GST', 'VAT', 'Sales Tax', 'Other Indirect Tax'])], 'registration_number' => 'required|string|max:100', 'registration_name' => 'required|string|max:255', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'filing_frequency' => ['required', Rule::in(['monthly', 'two_monthly', 'quarterly', 'six_monthly', 'annual', 'other'])], 'accounting_basis' => ['required', Rule::in(['accrual', 'cash'])], 'status' => ['required', Rule::in(['draft', 'active', 'inactive', 'cancelled'])], 'notes' => 'nullable|string', 'name' => 'required|string|max:255']), $request->user());

        return back()->with('success', 'Tax Registration created.');
    }

    public function storeCode(Request $request, string $country, Company $company, TaxConfigurationService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->code($company, $request->validate(['tax_registration_id' => 'required|integer', 'tax_type' => 'required|string|max:40', 'code' => 'required|string|max:40', 'name' => 'required|string|max:255', 'description' => 'nullable|string', 'treatment' => ['required', Rule::in(['taxable', 'zero_rated', 'exempt', 'out_of_scope'])], 'recoverability_type' => ['required', Rule::in(['full', 'partial', 'none'])], 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'is_active' => 'nullable|boolean']), $request->user());

        return back()->with('success', 'Tax Code created.');
    }

    public function storeRate(Request $request, string $country, Company $company, TaxCode $code, TaxConfigurationService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->rate($company, $code, $request->validate(['rate' => 'required|decimal:0,6|min:0', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from', 'is_active' => 'nullable|boolean']), $request->user());

        return back()->with('success', 'Effective Tax Rate created.');
    }

    public function settings(Request $request, string $country, Company $company, TaxConfigurationService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->settings($company, $request->validate(['output_tax_account_id' => 'nullable|integer', 'input_tax_account_id' => 'nullable|integer', 'rounding_account_id' => 'nullable|integer', 'default_sales_tax_code_id' => 'nullable|integer', 'default_purchase_tax_code_id' => 'nullable|integer', 'rounding_method' => 'required|in:per_line']), $request->user());

        return back()->with('success', 'Tax Settings updated.');
    }

    public function generatePeriods(Request $request, string $country, Company $company, TaxRegistration $registration, TaxConfigurationService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->generatePeriods($company, $registration, $request->user());

        return back()->with('success', 'Tax Periods generated.');
    }

    public function preparePeriod(Request $request, string $country, Company $company, int $period, TaxPeriodService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->prepare($company, TaxPeriod::findOrFail($period), $request->user());

        return back()->with('success', 'Tax Period prepared and snapshotted.');
    }

    public function filePeriod(Request $request, string $country, Company $company, int $period, TaxPeriodService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->file($company, TaxPeriod::findOrFail($period), $request->user());

        return back()->with('success', 'Tax Period marked Filed.');
    }

    public function adjustment(Request $request, string $country, Company $company, TaxAdjustmentService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->post($company, $request->validate(['tax_registration_id' => 'required|integer', 'tax_period_id' => 'required|integer', 'tax_code_id' => 'required|integer', 'adjustment_date' => 'required|date', 'amount' => 'required|decimal:0,4|not_in:0,0.0,0.00,0.000,0.0000', 'direction' => 'required|in:output,input', 'offset_account_id' => 'required|integer', 'branch_id' => 'nullable|integer', 'reason' => 'required|string|min:10', 'reference' => 'nullable|string|max:255']), $request->user());

        return back()->with('success', 'Controlled Tax Adjustment posted.');
    }

    private function context(Request $request, string $country, Company $company): Company
    {
        $jurisdictions = app(CountryJurisdictionService::class);

        return $jurisdictions->entity($request->user(), $jurisdictions->country($country), $company->id);
    }
}

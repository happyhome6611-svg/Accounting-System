<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Models\Country;
use App\Models\Currency;
use App\Services\CompanyCreator;
use App\Services\CompanyDeletionService;
use App\Services\CompanyMaintenanceService;
use App\Services\CountryJurisdictionService;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(CountryJurisdictionService $jurisdictions)
    {
        $countries = $jurisdictions->countriesFor(auth()->user());
        $countries->each(fn ($country) => $country->setAttribute('default_currency_code', $jurisdictions->defaultCurrencyCode($country)));

        return view('companies.jurisdictions', compact('countries'));
    }

    public function country(string $country, CountryJurisdictionService $jurisdictions, CompanyDeletionService $deletion)
    {
        $country = $jurisdictions->country($country);
        $companies = auth()->user()->companies()->where('country_id', $country->id)->with(['country', 'baseCurrency', 'financialYears' => fn ($q) => $q->where('is_current', true)])->get();
        $deletable = $companies->mapWithKeys(fn ($company) => [$company->id => $company->pivot->role === 'owner' && $deletion->isEligible($company)]);

        return view('companies.index', compact('country', 'companies', 'deletable'));
    }

    public function create()
    {
        return view('companies.create', ['countries' => Country::where('is_active', true)->orderBy('name')->get(), 'currencies' => Currency::where('is_active', true)->orderBy('code')->get()]);
    }

    public function createInCountry(string $country, CountryJurisdictionService $jurisdictions)
    {
        $country = $jurisdictions->country($country);
        $currencies = Currency::where('is_active', true)->orderBy('code')->get();
        $defaultCurrencyCode = $jurisdictions->defaultCurrencyCode($country);

        return view('companies.create', compact('country', 'currencies', 'defaultCurrencyCode'));
    }

    public function store(StoreCompanyRequest $request, CompanyCreator $creator)
    {
        $company = $creator->create($request->validated(), $request->user());

        return redirect()->route('companies.show', $company);
    }

    public function storeInCountry(StoreCompanyRequest $request, string $country, CountryJurisdictionService $jurisdictions, CompanyCreator $creator)
    {
        $country = $jurisdictions->country($country);
        abort_unless((int) $request->validated('country_id') === $country->id, 422, 'The accounting entity country must match the active jurisdiction.');
        $company = $creator->create($request->validated(), $request->user());

        return redirect()->route('companies.show', $company);
    }

    public function show(int $company)
    {
        $company = auth()->user()->companies()->with(['country', 'baseCurrency', 'financialYears' => fn ($q) => $q->where('is_current', true), 'accounts'])->findOrFail($company);

        return view('companies.show', compact('company'));
    }

    public function edit(Request $request, int $company)
    {
        $company = $request->user()->companies()->findOrFail($company);
        abort_unless($company->pivot->role === 'owner', 403);

        return view('companies.edit', compact('company') + ['countries' => Country::where('is_active', true)->get(), 'currencies' => Currency::where('is_active', true)->get()]);
    }

    public function update(Request $request, int $company, CompanyMaintenanceService $service)
    {
        $company = $request->user()->companies()->findOrFail($company);
        $data = $request->validate(['name' => 'required|string|max:255', 'legal_name' => 'required|string|max:255', 'country_id' => 'required|exists:countries,id', 'base_currency_id' => 'required|exists:currencies,id', 'timezone' => 'required|timezone:all', 'address' => 'nullable|string', 'email' => 'nullable|email', 'phone' => 'nullable|string|max:40', 'registration_identifiers' => 'nullable|array']);
        $service->update($company, $data, $request->user());

        return redirect()->route('companies.show', $company)->with('success', 'Company updated.');
    }

    public function status(Request $request, int $company, CompanyMaintenanceService $service)
    {
        $company = $request->user()->companies()->findOrFail($company);
        $data = $request->validate(['is_active' => 'required|boolean']);
        $service->setActive($company, (bool) $data['is_active'], $request->user());

        return back()->with('success', $data['is_active'] ? 'Company reactivated.' : 'Company deactivated.');
    }

    public function confirmDelete(Request $request, int $company, CompanyDeletionService $deletion)
    {
        $company = $request->user()->companies()->findOrFail($company);
        abort_unless($company->pivot->role === 'owner', 403);
        $blockers = $deletion->blockers($company);
        abort_if($blockers !== [], 422, 'This company contains business or accounting data and cannot be permanently deleted.');

        return view('companies.delete', compact('company'));
    }

    public function destroy(Request $request, int $company, CompanyDeletionService $deletion)
    {
        $company = $request->user()->companies()->findOrFail($company);
        $data = $request->validate(['confirmation_name' => ['required', 'string']]);
        $deletion->delete($company, $request->user(), $data['confirmation_name']);

        return redirect()->route('companies.index')->with('success', 'The unused company was permanently deleted.');
    }
}

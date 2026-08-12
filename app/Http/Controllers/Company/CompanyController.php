<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Models\Country;
use App\Models\Currency;
use App\Services\CompanyCreator;
use App\Services\CompanyDeletionService;
use App\Services\CompanyMaintenanceService;
use Illuminate\Http\Request;

class CompanyController extends Controller
{
    public function index(CompanyDeletionService $deletion)
    {
        $companies = auth()->user()->companies()->with(['country', 'baseCurrency', 'financialYears' => fn ($q) => $q->where('is_current', true)])->get();
        $deletable = $companies->mapWithKeys(fn ($company) => [$company->id => $company->pivot->role === 'owner' && $deletion->isEligible($company)]);

        return view('companies.index', compact('companies', 'deletable'));
    }

    public function create()
    {
        return view('companies.create', ['countries' => Country::where('is_active', true)->orderBy('name')->get(), 'currencies' => Currency::where('is_active', true)->orderBy('code')->get()]);
    }

    public function store(StoreCompanyRequest $request, CompanyCreator $creator)
    {
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

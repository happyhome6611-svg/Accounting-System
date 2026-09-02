<?php

namespace App\Http\Controllers;

use App\Services\CountryJurisdictionService;
use App\Services\DashboardService;
use App\Services\MoneyFormatter;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, MoneyFormatter $money, CountryJurisdictionService $jurisdictions)
    {
        $countries = $jurisdictions->countriesFor($request->user(), false);
        $country = $request->filled('country_id') ? $jurisdictions->country($request->integer('country_id')) : $countries->first();
        if ($country && ! $countries->contains('id', $country->id)) {
            abort(404);
        }
        $companies = $country ? $request->user()->companies()->where('country_id', $country->id)->with('baseCurrency')->orderBy('name')->get() : collect();
        $company = $request->filled('company_id') && $country ? $jurisdictions->entity($request->user(), $country, $request->integer('company_id'))->load('baseCurrency') : $companies->first();
        if (! $company) {
            return view('dashboard.index', compact('countries', 'country', 'companies', 'company', 'money'));
        }
        $supportsBranches = $company->supportsBranches();
        $branches = $supportsBranches ? $company->branches()->where('is_active', true)->orderByDesc('is_main_branch')->get() : collect();
        $branchId = $supportsBranches && $request->filled('branch_id') ? $company->branches()->where('is_active', true)->findOrFail($request->integer('branch_id'))->id : null;
        $financialYears = $company->financialYears()->orderByDesc('starts_on')->get();
        $financialYear = $request->filled('financial_year_id') ? $company->financialYears()->findOrFail($request->integer('financial_year_id')) : $company->financialYears()->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->first();
        $metrics = $financialYear ? $dashboard->metrics($company, $branchId, $financialYear->id) : $dashboard->emptyMetrics();
        $period = $financialYear?->periods()->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->first();

        return view('dashboard.index', compact('countries', 'country', 'companies', 'company', 'supportsBranches', 'branches', 'branchId', 'metrics', 'financialYears', 'financialYear', 'period', 'money'));
    }
}

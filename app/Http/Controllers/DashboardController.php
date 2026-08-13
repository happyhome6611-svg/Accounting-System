<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use App\Services\MoneyFormatter;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard, MoneyFormatter $money)
    {
        $companies = $request->user()->companies()->with('baseCurrency')->orderBy('name')->get();
        $company = $request->filled('company_id') ? $request->user()->companies()->with('baseCurrency')->findOrFail($request->integer('company_id')) : $companies->first();
        if (! $company) {
            return view('dashboard.index', compact('companies', 'company', 'money'));
        }
        $branches = $company->branches()->where('is_active', true)->orderByDesc('is_main_branch')->get();
        $branchId = $request->filled('branch_id') ? $company->branches()->where('is_active', true)->findOrFail($request->integer('branch_id'))->id : null;
        $metrics = $dashboard->metrics($company, $branchId);
        $financialYear = $company->financialYears()->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->first();
        $period = $financialYear?->periods()->whereDate('starts_on', '<=', today())->whereDate('ends_on', '>=', today())->first();

        return view('dashboard.index', compact('companies', 'company', 'branches', 'branchId', 'metrics', 'financialYear', 'period', 'money'));
    }
}

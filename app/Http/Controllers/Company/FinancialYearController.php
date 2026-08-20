<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Services\FinancialYearService;
use Illuminate\Http\Request;

class FinancialYearController extends Controller
{
    public function index(Request $request, Company $company)
    {
        $company = $this->entity($request, $company);

        return view('companies.financial-years.index', ['company' => $company, 'years' => $company->financialYears()->with(['periods', 'taxYears'])->orderByDesc('starts_on')->get()]);
    }

    public function store(Request $request, Company $company, FinancialYearService $service)
    {
        $company = $this->entity($request, $company);
        $data = $request->validate(['name' => 'required|string|max:100', 'starts_on' => 'required|date', 'ends_on' => 'required|date|after:starts_on']);
        $service->create($company, $data, $request->user());

        return back()->with('success', 'Financial Year created.');
    }

    public function close(Request $request, Company $company, FinancialYear $financialYear, FinancialYearService $service)
    {
        $company = $this->entity($request, $company);
        abort_unless($financialYear->company_id === $company->id, 404);
        $service->close($financialYear, $request->validate(['reason' => 'required|string|min:10'])['reason'], $request->user());

        return back()->with('success', 'Financial Year closed and periods locked.');
    }

    public function reopen(Request $request, Company $company, FinancialYear $financialYear, FinancialYearService $service)
    {
        $company = $this->entity($request, $company);
        abort_unless($financialYear->company_id === $company->id, 404);
        $service->reopen($financialYear, $request->validate(['reason' => 'required|string|min:10'])['reason'], $request->user());

        return back()->with('success', 'Financial Year reopened; periods remain individually controlled.');
    }

    private function entity(Request $request, Company $company): Company
    {
        $company = $request->user()->companies()->findOrFail($company->id);
        abort_unless($company->pivot->role === 'owner', 403);

        return $company;
    }
}

<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\AccountingPeriod;
use App\Models\Company;
use App\Models\FinancialYear;
use App\Services\AccountingPeriodService;
use App\Services\FinancialYearService;
use Illuminate\Http\Request;

class FinancialYearController extends Controller
{
    public function index(Request $request, Company $company)
    {
        $company = $this->entity($request, $company);

        $years = $company->financialYears()->with(['periods', 'taxYears'])->orderByDesc('starts_on')->get();

        return view('companies.financial-years.index', compact('company', 'years'));
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
        $service->close($financialYear, $request->validate(['reason' => 'required|string|min:10', 'confirmation' => 'required|in:CLOSE YEAR'])['reason'], $request->user());

        return back()->with('success', 'Financial Year closed and periods locked.');
    }

    public function beginClosing(Request $request, Company $company, FinancialYear $financialYear, FinancialYearService $service)
    {
        $company = $this->entity($request, $company);
        abort_unless($financialYear->company_id === $company->id, 404);
        $service->beginClosing($financialYear, $request->validate(['reason' => 'required|string|min:10', 'confirmation' => 'required|in:BEGIN CLOSING'])['reason'], $request->user());

        return back()->with('success', 'Financial Year entered Closing. Normal posting is now blocked.');
    }

    public function reopen(Request $request, Company $company, FinancialYear $financialYear, FinancialYearService $service)
    {
        $company = $this->entity($request, $company);
        abort_unless($financialYear->company_id === $company->id, 404);
        $service->reopen($financialYear, $request->validate(['reason' => 'required|string|min:10', 'confirmation' => 'required|in:REOPEN YEAR'])['reason'], $request->user());

        return back()->with('success', 'Financial Year reopened; periods remain individually controlled.');
    }

    public function cancelClosing(Request $request, Company $company, FinancialYear $financialYear, FinancialYearService $service)
    {
        $company = $this->entity($request, $company);
        abort_unless($financialYear->company_id === $company->id, 404);
        $service->cancelClosing($financialYear, $request->validate(['reason' => 'required|string|min:10', 'confirmation' => 'required|in:CANCEL CLOSING'])['reason'], $request->user());

        return back()->with('success', 'Financial Year closing cancelled. Closed Accounting Periods remain closed.');
    }

    public function markFiled(Request $request, Company $company, FinancialYear $financialYear, FinancialYearService $service)
    {
        $company = $this->entity($request, $company);
        abort_unless($financialYear->company_id === $company->id, 404);
        $data = $request->validate(['reference' => 'required|string|max:255', 'confirmation' => 'required|in:MARK FILED']);
        $service->markFiled($financialYear, $data['reference'], $request->user());

        return back()->with('success', 'Financial Year marked Filed. Normal reopening is permanently blocked.');
    }

    public function closePeriod(Request $request, Company $company, FinancialYear $financialYear, AccountingPeriod $period, AccountingPeriodService $service)
    {
        $company = $this->entity($request, $company);
        abort_unless($financialYear->company_id === $company->id && $period->company_id === $company->id && $period->financial_year_id === $financialYear->id, 404);
        $data = $request->validate(['reason' => 'required|string|min:10', 'confirmation' => 'required|in:CLOSE PERIOD']);
        $service->close($period, $data['reason'], $request->user());

        return back()->with('success', "Accounting Period {$period->name} closed.");
    }

    public function reopenPeriod(Request $request, Company $company, FinancialYear $financialYear, AccountingPeriod $period, AccountingPeriodService $service)
    {
        $company = $this->entity($request, $company);
        abort_unless($financialYear->company_id === $company->id && $period->company_id === $company->id && $period->financial_year_id === $financialYear->id, 404);
        $data = $request->validate(['reason' => 'required|string|min:10', 'confirmation' => 'required|in:REOPEN PERIOD']);
        $service->reopen($period, $data['reason'], $request->user());

        return back()->with('success', "Accounting Period {$period->name} reopened.");
    }

    private function entity(Request $request, Company $company): Company
    {
        $company = $request->user()->companies()->findOrFail($company->id);
        abort_unless($company->pivot->role === 'owner', 403);

        return $company;
    }
}

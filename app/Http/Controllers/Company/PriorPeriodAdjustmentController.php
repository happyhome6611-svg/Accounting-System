<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\PriorPeriodAdjustmentService;
use Illuminate\Http\Request;

class PriorPeriodAdjustmentController extends Controller
{
    public function create(Request $request, Company $company)
    {
        $company = $this->entity($request, $company);
        $origin = $company->financialYears()->whereIn('status', ['closed', 'filed'])->findOrFail($request->integer('origin_financial_year_id'));
        $adjustmentYears = $company->financialYears()->where('status', 'open')->whereKeyNot($origin->id)->orderByDesc('starts_on')->get();

        return view('companies.prior-adjustments.create', compact('company', 'origin', 'adjustmentYears'));
    }

    public function store(Request $request, Company $company, PriorPeriodAdjustmentService $service)
    {
        $company = $this->entity($request, $company);
        $data = $request->validate(['origin_financial_year_id' => 'required|integer', 'adjustment_financial_year_id' => 'required|integer', 'adjustment_type' => 'required|in:accounting_correction,source_document_omission,opening_balance_correction,other', 'reason' => 'required|string|min:10', 'source_reference' => 'nullable|string|max:255']);
        $adjustment = $service->create($company, $data, $request->user());

        return redirect()->route('companies.financial-years.index', $company)->with('success', "Prior-Period Adjustment #{$adjustment->id} created as Draft. No journal was created automatically.");
    }

    private function entity(Request $request, Company $company): Company
    {
        $company = $request->user()->companies()->findOrFail($company->id);
        abort_unless($company->pivot->role === 'owner', 403);

        return $company;
    }
}

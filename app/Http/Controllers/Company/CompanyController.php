<?php

namespace App\Http\Controllers\Company;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCompanyRequest;
use App\Models\Country;
use App\Models\Currency;
use App\Services\CompanyCreator;

class CompanyController extends Controller
{
    public function index()
    {
        return view('companies.index', ['companies' => auth()->user()->companies()->with(['country', 'baseCurrency', 'financialYears' => fn ($q) => $q->where('is_current', true)])->get()]);
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
}

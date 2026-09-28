<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CountryJurisdictionService;
use Illuminate\Http\Request;

class OpeningBalanceController extends Controller
{
    public function __invoke(Request $request, string $country, Company $company, CountryJurisdictionService $jurisdictions)
    {
        $country = $jurisdictions->country($country);
        $company = $jurisdictions->entity($request->user(), $country, $company->id)->load(['country', 'baseCurrency']);
        $stagings = $company->openingBalanceStagings()
            ->with(['branch', 'importBatch', 'lines.account'])
            ->latest('balance_date')
            ->latest('id')
            ->get();
        $lines = $stagings->flatMap->lines;
        $totalDebit = $lines->reduce(fn (string $total, $line) => bcadd($total, $line->debit, 4), '0.0000');
        $totalCredit = $lines->reduce(fn (string $total, $line) => bcadd($total, $line->credit, 4), '0.0000');

        return view('accounting.opening-balances', compact('company', 'stagings', 'totalDebit', 'totalCredit'));
    }
}

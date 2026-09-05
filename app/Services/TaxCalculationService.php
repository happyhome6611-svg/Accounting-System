<?php

namespace App\Services;

use App\Models\Company;
use App\Models\TaxCode;
use App\Models\TaxPeriod;
use App\Models\TaxRate;
use App\Models\TaxRegistration;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

final class TaxCalculationService
{
    public function calculate(Company $company, int $taxCodeId, string $date, string $amount, bool $inclusive = false): array
    {
        $date = CarbonImmutable::parse($date)->toDateString();
        $code = TaxCode::where('company_id', $company->id)->where('country_id', $company->country_id)->where('is_active', true)
            ->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->findOrFail($taxCodeId);
        $registration = TaxRegistration::where('company_id', $company->id)->where('country_id', $company->country_id)->where('status', 'active')
            ->whereDate('effective_from', '<=', $date)->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->findOrFail($code->tax_registration_id);
        $rate = TaxRate::where('tax_code_id', $code->id)->where('is_active', true)->whereDate('effective_from', '<=', $date)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date))->orderByDesc('effective_from')->first();
        if ($code->treatment === 'taxable' && ! $rate) {
            throw ValidationException::withMessages(['tax_code_id' => 'No effective Tax Rate exists for this Tax Code and transaction date.']);
        }
        $rateValue = $rate?->rate ?? '0.000000';
        if ($code->treatment !== 'taxable') {
            $rateValue = '0.000000';
        }
        if (bccomp($amount, '0', 4) < 0) {
            throw ValidationException::withMessages(['amount' => 'Tax calculation amount cannot be negative.']);
        }
        if ($inclusive && bccomp($rateValue, '0', 6) > 0) {
            $gross = $this->round($amount);
            $net = $this->round(bcdiv($amount, bcadd('1', bcdiv($rateValue, '100', 10), 10), 10));
            $tax = bcsub($gross, $net, 4);
        } else {
            $net = $this->round($amount);
            $tax = $this->round(bcdiv(bcmul($net, $rateValue, 10), '100', 10));
            $gross = bcadd($net, $tax, 4);
        }
        $period = TaxPeriod::where('company_id', $company->id)->where('tax_obligation_id', $registration->id)
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();
        if ($period && $period->status === 'filed') {
            throw ValidationException::withMessages(['tax_period' => 'The resolved Tax Period is Filed and cannot accept new tax transactions.']);
        }

        return ['tax_code_id' => $code->id, 'tax_registration_id' => $registration->id, 'tax_period_id' => $period?->id, 'country_id' => $company->country_id, 'tax_code' => $code->code, 'tax_type' => $code->tax_type, 'treatment' => $code->treatment, 'registration_number' => $registration->registration_number, 'rate' => $rateValue, 'net' => $net, 'tax' => $tax, 'gross' => $gross, 'inclusive' => $inclusive];
    }

    private function round(string $value): string
    {
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-');
        [$whole, $fraction] = array_pad(explode('.', $absolute, 2), 2, '');
        $fraction = str_pad($fraction, 5, '0');
        $base = $whole.'.'.substr($fraction, 0, 4);
        if ((int) $fraction[4] >= 5) {
            $base = bcadd($base, '0.0001', 4);
        }

        return $negative ? bcmul($base, '-1', 4) : $base;
    }
}

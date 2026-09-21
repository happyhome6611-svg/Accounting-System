<?php

namespace App\ImportExport;

use App\Models\Currency;

/** Compare import evidence at the currency's display precision, never at ledger precision. */
final class SourceTaxComparator
{
    public function warning(?string $sourceTax, string $calculatedTax, Currency $currency): ?string
    {
        if ($sourceTax === null || $sourceTax === '') {
            return null;
        }

        $places = (int) $currency->decimal_places;
        $calculated = $this->rounded($calculatedTax, $places);
        if (! is_numeric($sourceTax)) {
            return "Source tax {$sourceTax} is not numeric. Arua's calculated tax {$calculated} will be used.";
        }

        $source = $this->rounded($sourceTax, $places);
        if (bccomp($source, $calculated, $places) === 0) {
            return null;
        }

        return "Source tax {$source} differs from Arua calculated tax {$calculated}. Arua's calculated tax will be used.";
    }

    private function rounded(string $value, int $places): string
    {
        $halfUnit = '0.'.str_repeat('0', max(0, $places)).'5';
        $adjustment = str_starts_with($value, '-') ? '-'.$halfUnit : $halfUnit;

        return bcadd($value, $adjustment, $places);
    }
}

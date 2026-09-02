<?php

namespace App\Services;

use App\Models\Currency;

final class MoneyFormatter
{
    private const SYMBOLS = ['NZD' => 'NZ$', 'INR' => '₹', 'AUD' => 'A$', 'GBP' => '£', 'SGD' => 'S$', 'USD' => 'US$', 'EUR' => '€'];

    public function symbol(Currency $currency): string
    {
        return self::SYMBOLS[$currency->code] ?? ($currency->symbol ?: $currency->code.' ');
    }

    public function label(Currency $currency): string
    {
        return $this->symbol($currency).' ('.$currency->code.')';
    }

    public function format(string|int|float|null $amount, Currency $currency, int $precision = 2, bool $absolute = false): string
    {
        $value = (float) ($amount ?? 0);
        if ($absolute) {
            $value = abs($value);
        }

        return $this->symbol($currency).number_format($value, $precision, '.', ',');
    }
}

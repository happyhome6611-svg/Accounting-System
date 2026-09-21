<?php

namespace Tests\Unit;

use App\ImportExport\SourceTaxComparator;
use App\Models\Currency;
use PHPUnit\Framework\TestCase;

class SourceTaxComparatorTest extends TestCase
{
    public function test_currency_rounding_removes_only_false_source_tax_warnings(): void
    {
        $comparator = new SourceTaxComparator;
        $nzd = new Currency(['decimal_places' => 2]);

        $this->assertNull($comparator->warning('19.24', '19.2375', $nzd));
        $this->assertNull($comparator->warning('64.13', '64.1250', $nzd));
        $this->assertNull($comparator->warning('0.00', '0.0000', $nzd));
        $this->assertSame("Source tax 19.30 differs from Arua calculated tax 19.24. Arua's calculated tax will be used.", $comparator->warning('19.30', '19.2375', $nzd));
        $this->assertSame("Source tax invalid is not numeric. Arua's calculated tax 19.24 will be used.", $comparator->warning('invalid', '19.2375', $nzd));

        $wholeUnit = new Currency(['decimal_places' => 0]);
        $this->assertNull($comparator->warning('65', '64.5000', $wholeUnit));
        $this->assertSame("Source tax 66 differs from Arua calculated tax 65. Arua's calculated tax will be used.", $comparator->warning('66', '64.5000', $wholeUnit));
    }
}

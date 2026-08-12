<?php

namespace App\Countries\UnitedKingdom;

use App\Tax\AbstractCountryModule;

final class UnitedKingdomModule extends AbstractCountryModule
{
    public function countryCode(): string
    {
        return 'GB';
    }
}

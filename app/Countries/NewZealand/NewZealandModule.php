<?php

namespace App\Countries\NewZealand;

use App\Tax\AbstractCountryModule;

final class NewZealandModule extends AbstractCountryModule
{
    public function countryCode(): string
    {
        return 'NZ';
    }
}

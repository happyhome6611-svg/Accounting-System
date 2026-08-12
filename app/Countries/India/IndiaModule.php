<?php

namespace App\Countries\India;

use App\Tax\AbstractCountryModule;

final class IndiaModule extends AbstractCountryModule
{
    public function countryCode(): string
    {
        return 'IN';
    }
}

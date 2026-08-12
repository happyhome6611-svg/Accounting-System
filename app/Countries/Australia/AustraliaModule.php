<?php

namespace App\Countries\Australia;

use App\Tax\AbstractCountryModule;

final class AustraliaModule extends AbstractCountryModule
{
    public function countryCode(): string
    {
        return 'AU';
    }
}

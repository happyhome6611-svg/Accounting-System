<?php

namespace App\Countries\Singapore;

use App\Tax\AbstractCountryModule;

final class SingaporeModule extends AbstractCountryModule
{
    public function countryCode(): string
    {
        return 'SG';
    }
}

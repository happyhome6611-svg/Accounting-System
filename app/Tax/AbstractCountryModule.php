<?php

namespace App\Tax;

abstract class AbstractCountryModule implements CountryModule
{
    public function taxIdentifiers(): array
    {
        return [];
    }

    public function invoiceRequirements(): array
    {
        return [];
    }

    public function reportingRequirements(): array
    {
        return [];
    }
}

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

    public function supportedEntityTypes(): array
    {
        return ['company', 'individual', 'sole_trader'];
    }

    public function taxProfile(string $entityType): array
    {
        return ['country' => $this->countryCode(), 'entity_type' => $entityType, 'calculation_engine' => null];
    }
}

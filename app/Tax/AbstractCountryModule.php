<?php

namespace App\Tax;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

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
        return $this->taxProfileAt($entityType, CarbonImmutable::now());
    }

    public function taxProfileAt(string $entityType, CarbonInterface|string $effectiveDate): array
    {
        if (! in_array($entityType, $this->supportedEntityTypes(), true)) {
            throw new InvalidArgumentException("Unsupported entity type {$entityType} for {$this->countryCode()}.");
        }

        $date = $effectiveDate instanceof CarbonInterface ? $effectiveDate : CarbonImmutable::parse($effectiveDate);

        return ['country' => $this->countryCode(), 'entity_type' => $entityType, 'effective_date' => $date->toDateString(), 'calculation_engine' => null];
    }
}

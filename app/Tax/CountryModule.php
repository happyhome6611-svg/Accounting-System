<?php

namespace App\Tax;

use Carbon\CarbonInterface;

interface CountryModule
{
    public function countryCode(): string;

    public function taxIdentifiers(): array;

    public function invoiceRequirements(): array;

    public function reportingRequirements(): array;

    public function supportedEntityTypes(): array;

    public function taxProfile(string $entityType): array;

    public function taxProfileAt(string $entityType, CarbonInterface|string $effectiveDate): array;
}

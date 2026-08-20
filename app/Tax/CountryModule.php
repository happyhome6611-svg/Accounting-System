<?php

namespace App\Tax;

interface CountryModule
{
    public function countryCode(): string;

    public function taxIdentifiers(): array;

    public function invoiceRequirements(): array;

    public function reportingRequirements(): array;

    public function supportedEntityTypes(): array;

    public function taxProfile(string $entityType): array;
}

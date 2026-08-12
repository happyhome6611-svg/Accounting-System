<?php

namespace App\Tax;

use InvalidArgumentException;

final class CountryModuleRegistry
{
    public function __construct(private readonly array $modules) {}

    public function resolve(string $countryCode): CountryModule
    {
        $key = strtoupper($countryCode);
        if (! isset($this->modules[$key])) {
            throw new InvalidArgumentException("No country module registered for {$key}.");
        }

        return app($this->modules[$key]);
    }
}

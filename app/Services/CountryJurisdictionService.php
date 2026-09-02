<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Country;
use App\Models\User;
use App\Tax\CountryModuleRegistry;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

final class CountryJurisdictionService
{
    public function __construct(private CountryModuleRegistry $modules) {}

    public function countriesFor(User $user, bool $includeEmpty = true): Collection
    {
        $query = Country::query()->where('is_active', true)
            ->withCount(['companies as accessible_entities_count' => fn ($query) => $query->whereHas('users', fn ($users) => $users->whereKey($user->id))])
            ->orderBy('name');

        if (! $includeEmpty) {
            $query->whereHas('companies.users', fn ($users) => $users->whereKey($user->id));
        }

        return $query->get();
    }

    public function country(string|int $identifier): Country
    {
        return Country::query()->where('is_active', true)
            ->when(is_numeric($identifier), fn ($query) => $query->whereKey((int) $identifier), fn ($query) => $query->where('code', strtoupper((string) $identifier)))
            ->firstOrFail();
    }

    public function entities(User $user, Country $country, bool $businessOnly = false): Collection
    {
        return $user->companies()->where('country_id', $country->id)
            ->when($businessOnly, fn ($query) => $query->where('entity_type', '!=', 'individual'))
            ->orderBy('name')->get();
    }

    public function entity(User $user, Country $country, int $companyId): Company
    {
        return $user->companies()->where('country_id', $country->id)->findOrFail($companyId);
    }

    public function defaultCurrencyCode(Country $country): ?string
    {
        return config("countries.default_currencies.{$country->code}");
    }

    public function defaultTimezone(Country $country): ?string
    {
        return config("countries.default_timezones.{$country->code}");
    }

    public function defaults(): array
    {
        return Country::query()->where('is_active', true)->get()->mapWithKeys(fn (Country $country) => [$country->id => [
            'currency' => $this->defaultCurrencyCode($country),
            'timezone' => $this->defaultTimezone($country),
        ]])->all();
    }

    public function taxProfile(Country $country, string $entityType, CarbonInterface|string $effectiveDate): array
    {
        return $this->modules->resolve($country->code)->taxProfileAt($entityType, $effectiveDate);
    }
}

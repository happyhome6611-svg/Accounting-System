<?php

namespace App\Providers;

use App\Tax\CountryModuleRegistry;
use Illuminate\Support\ServiceProvider;

class CountryModuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CountryModuleRegistry::class, fn () => new CountryModuleRegistry(config('countries.modules')));
    }
}

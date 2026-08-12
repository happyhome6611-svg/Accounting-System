# Accounting Pro v0.1

Accounting Pro is a Laravel 12 and PostgreSQL multi-company accounting foundation. It uses one shared accounting engine and independently resolved country modules for India, New Zealand, Australia, the United Kingdom, and Singapore.

## Local setup

Requirements: PHP 8.2+, Composer, PostgreSQL, and Node.js (optional for future compiled assets).

1. Copy `.env.example` to `.env` and set the PostgreSQL credentials.
2. Run `php composer.phar install` (or `composer install`).
3. Run `php artisan key:generate`.
4. Create the configured empty database, then run `php artisan migrate:fresh --seed`.
5. Run `php artisan serve` and register the first user.

## Validation

Run `php artisan test` and `vendor/bin/pint --test`. The automated suite covers authentication, company creation, financial periods, the chart of accounts, company isolation, idempotent master seeders, and country-provider resolution.

Tax calculation, invoices, inventory, payroll, returns, and government integrations are intentionally outside v0.1.

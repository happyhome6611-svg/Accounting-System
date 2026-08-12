# Arua Accounting System v0.2

Arua is a Laravel 12 and PostgreSQL multi-company accounting platform. It uses one shared accounting engine and independently resolved country modules for India, New Zealand, Australia, the United Kingdom, and Singapore.

Version 0.2 adds an exact-decimal double-entry journal engine, immutable posting, reversing journals, period controls, audit events, General Ledger, Trial Balance, Profit & Loss, and Balance Sheet reports. Posted journal lines remain the accounting source of truth; no duplicate balance table is maintained.

## Local setup

Requirements: PHP 8.2+, Composer, PostgreSQL, and Node.js (optional for future compiled assets).

1. Copy `.env.example` to `.env` and set the PostgreSQL credentials.
2. Run `php composer.phar install` (or `composer install`).
3. Run `php artisan key:generate`.
4. Create the configured empty database, then run `php artisan migrate:fresh --seed`.
5. Run `php artisan serve` and register the first user.

## Validation

Run `php artisan test`, `vendor/bin/pint --test`, and `git diff --check`. The automated suite covers the v0.1 foundation plus journal validation, posting, immutability, reversal, company isolation, and report calculations.

Tax calculation, invoices, inventory, payroll, returns, and government integrations remain intentionally outside v0.2.

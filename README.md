# Arua Accounting System v0.3

Arua is a Laravel 12 and PostgreSQL multi-company accounting platform. It uses one shared accounting engine and independently resolved country modules for India, New Zealand, Australia, the United Kingdom, and Singapore.

Version 0.3 adds company-isolated customer and product/service masters, quotations, sales orders, sales invoices, credit notes, customer receipts and allocations, Accounts Receivable, customer statements, and AR aging. Posted sales documents use the v0.2 journal engine; quotations and orders do not affect accounting.

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

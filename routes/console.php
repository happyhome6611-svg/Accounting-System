<?php

use App\Models\Company;
use App\Services\DemoTradingSetupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('arua:setup-demo-trading {--entity= : Explicit existing Accounting Entity ID}', function (DemoTradingSetupService $setup) {
    if (app()->environment('production')) {
        $this->error('This sample setup command is disabled in production.');

        return 1;
    }

    $id = $this->option('entity');
    if ($id !== null && (! ctype_digit((string) $id) || (int) $id < 1)) {
        $this->error('--entity must be a positive numeric ID.');

        return 1;
    }
    $matches = Company::where('name', 'Arua Demo Trading Ltd')->whereHas('country', fn ($query) => $query->where('code', 'NZ'))->when($id !== null, fn ($query) => $query->whereKey((int) $id))->get();
    if ($matches->count() !== 1) {
        $this->error($matches->isEmpty() ? 'No matching New Zealand Arua Demo Trading Ltd entity exists. Import 00_accounting_entity_import.csv first.' : 'Multiple matching entities exist. Specify --entity=<id>.');

        return 1;
    }

    $company = $matches->first();
    try {
        $result = $setup->setup($company);
    } catch (ValidationException $exception) {
        $this->error(implode(' ', $exception->validator->errors()->all()));

        return 1;
    }
    $this->line("{$company->name} | Entity ID: {$result['entity_id']} | {$company->country->code}");
    $this->line('Branches: AKL Ready; WLG Ready (Head Office preserved)');
    $this->line('Tax Registration: DEMO-NZ-001 Ready');
    $this->line('Tax Control Accounts: 2100 Output Tax Payable Ready; 1200 Input Tax Recoverable Ready');
    $this->line('Tax Codes: STANDARD 15% Ready; ZERO 0% Ready');
    $this->line("Tax Periods: {$result['periods']} Ready");
    $this->info('Sample prerequisites: READY (existing setup reused; no duplicates created)');

    return 0;
})->purpose('Configure existing local Arua Demo Trading sample prerequisites');

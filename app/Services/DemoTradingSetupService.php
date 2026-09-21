<?php

namespace App\Services;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Development fixture setup; never invoked by normal accounting workflows. */
final class DemoTradingSetupService
{
    public function __construct(private BranchService $branches, private TaxConfigurationService $tax) {}

    public function setup(Company $company): array
    {
        if ($company->name !== 'Arua Demo Trading Ltd' || $company->country?->code !== 'NZ' || $company->entity_type !== 'company') {
            throw ValidationException::withMessages(['entity' => 'Select the New Zealand Arua Demo Trading Ltd company.']);
        }

        $user = $company->users()->whereKey($company->created_by)->first() ?? $company->users()->first();
        if (! $user instanceof User) {
            throw ValidationException::withMessages(['entity' => 'The demo entity must have an authorized user.']);
        }

        $output = $company->accounts()->where('code', '2100')->where('name', 'Output Tax Payable')->where('type', 'liability')->where('is_active', true)->first();
        $input = $company->accounts()->where('code', '1200')->where('name', 'Input Tax Recoverable')->where('type', 'asset')->where('is_active', true)->first();
        if (! $output || ! $input) {
            throw ValidationException::withMessages(['accounts' => 'Import 03_chart_of_accounts.csv before running demo setup. Required accounts: 2100 Output Tax Payable and 1200 Input Tax Recoverable.']);
        }
        if (! $company->taxYears()->whereDate('starts_on', '<=', '2025-04-01')->whereDate('ends_on', '>=', '2026-03-31')->exists()) {
            throw ValidationException::withMessages(['tax_year' => 'The demo entity needs a Tax Year covering 2025-04-01 through 2026-03-31.']);
        }

        return DB::transaction(function () use ($company, $user, $output, $input) {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            foreach (['AKL' => 'Auckland', 'WLG' => 'Wellington'] as $code => $name) {
                $branch = $company->branches()->where('code', $code)->first();
                if ($branch && ($branch->name !== $name || $branch->timezone !== 'Pacific/Auckland' || ! $branch->is_active)) {
                    throw ValidationException::withMessages(['branches' => "Existing branch {$code} differs from the demo reference; review it manually."]);
                }
                if (! $branch) {
                    $this->branches->create($company, ['code' => $code, 'name' => $name, 'timezone' => 'Pacific/Auckland', 'is_active' => true, 'is_main_branch' => false], $user);
                }
            }

            $registration = $company->taxRegistrations()->where('registration_number', 'DEMO-NZ-001')->first();
            if ($registration && ($registration->country_id !== $company->country_id || $registration->tax_type !== 'GST' || $registration->status !== 'active' || $registration->registration_name !== 'Arua Demo Trading Ltd' || $registration->accounting_basis !== 'accrual' || $registration->filing_frequency !== 'two_monthly' || $registration->effective_from->toDateString() !== '2025-04-01' || $registration->effective_to?->toDateString() !== '2026-03-31')) {
                throw ValidationException::withMessages(['tax_registration' => 'Existing DEMO-NZ-001 registration differs from the demo reference; review it manually.']);
            }
            $registration ??= $this->tax->registration($company, ['tax_type' => 'GST', 'name' => 'Generic GST', 'registration_number' => 'DEMO-NZ-001', 'registration_name' => 'Arua Demo Trading Ltd', 'effective_from' => '2025-04-01', 'effective_to' => '2026-03-31', 'filing_frequency' => 'two_monthly', 'accounting_basis' => 'accrual', 'status' => 'active'], $user);

            foreach (['STANDARD' => 'taxable', 'ZERO' => 'zero_rated'] as $value => $treatment) {
                $code = $company->taxCodes()->where('code', $value)->first();
                if ($code && ($code->tax_registration_id !== $registration->id || $code->country_id !== $company->country_id || $code->tax_type !== 'GST' || $code->treatment !== $treatment || ! $code->is_active || $code->effective_from->toDateString() !== '2025-04-01' || ($code->effective_to && $code->effective_to->toDateString() !== '2026-03-31'))) {
                    throw ValidationException::withMessages(['tax_code' => "Existing {$value} code differs from the demo reference; review it manually."]);
                }
                $code ??= $this->tax->code($company, ['tax_registration_id' => $registration->id, 'tax_type' => 'GST', 'code' => $value, 'name' => $value === 'STANDARD' ? 'Standard' : 'Zero-rated', 'treatment' => $treatment, 'recoverability_type' => 'full', 'effective_from' => '2025-04-01', 'effective_to' => '2026-03-31', 'is_active' => true], $user);
                $rates = $code->rates()->get();
                if ($value === 'STANDARD' && $rates->isEmpty()) {
                    $this->tax->rate($company, $code, ['rate' => '15.00', 'effective_from' => '2025-04-01', 'effective_to' => '2026-03-31', 'is_active' => true], $user);
                } elseif ($value === 'STANDARD' && ($rates->count() !== 1 || ! $rates->first()->is_active || bccomp((string) $rates->first()->rate, '15', 6) !== 0 || $rates->first()->effective_from->toDateString() !== '2025-04-01' || $rates->first()->effective_to?->toDateString() !== '2026-03-31')) {
                    throw ValidationException::withMessages(['tax_rate' => 'Existing STANDARD rate differs from the demo reference; review it manually.']);
                } elseif ($value === 'ZERO' && $rates->isNotEmpty()) {
                    throw ValidationException::withMessages(['tax_rate' => 'Existing ZERO rate differs from the demo reference; review it manually.']);
                }
            }

            $settings = $company->taxSetting;
            if ($settings && (($settings->output_tax_account_id && $settings->output_tax_account_id !== $output->id) || ($settings->input_tax_account_id && $settings->input_tax_account_id !== $input->id))) {
                throw ValidationException::withMessages(['tax_settings' => 'Existing tax control accounts differ from the demo reference; review them manually.']);
            }
            if (! $settings || $settings->output_tax_account_id !== $output->id || $settings->input_tax_account_id !== $input->id) {
                $this->tax->settings($company, ['output_tax_account_id' => $output->id, 'input_tax_account_id' => $input->id, 'rounding_method' => $settings?->rounding_method ?? 'per_line'], $user);
            }
            $this->tax->generatePeriods($company, $registration, $user);

            return ['entity_id' => $company->id, 'periods' => $registration->periods()->count()];
        });
    }
}

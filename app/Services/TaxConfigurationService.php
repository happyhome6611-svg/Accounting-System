<?php

namespace App\Services;

use App\Models\Company;
use App\Models\TaxCode;
use App\Models\TaxPeriod;
use App\Models\TaxRate;
use App\Models\TaxRegistration;
use App\Models\TaxSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaxConfigurationService
{
    public function __construct(private AccountingLockService $authorization, private AuditLogger $audit) {}

    public function registration(Company $company, array $data, User $user): TaxRegistration
    {
        $this->authorization->authorize($company, $user);
        if ((int) ($data['country_id'] ?? $company->country_id) !== $company->country_id) {
            throw ValidationException::withMessages(['country_id' => 'Tax Registration jurisdiction must match the Accounting Entity.']);
        }
        $registration = $company->taxRegistrations()->create($data + ['country_id' => $company->country_id, 'created_by' => $user->id, 'updated_by' => $user->id, 'configuration' => []]);
        $this->audit->log('tax_registration.created', $registration, $company->id, $user->id);

        return $registration;
    }

    public function code(Company $company, array $data, User $user): TaxCode
    {
        $this->authorization->authorize($company, $user);
        $registration = $company->taxRegistrations()->findOrFail($data['tax_registration_id']);
        if ($registration->tax_type !== $data['tax_type']) {
            throw ValidationException::withMessages(['tax_type' => 'Tax Code type must match its registration.']);
        }
        $code = $company->taxCodes()->create($data + ['country_id' => $company->country_id, 'created_by' => $user->id, 'updated_by' => $user->id]);
        $this->audit->log('tax_code.created', $code, $company->id, $user->id);

        return $code;
    }

    public function rate(Company $company, TaxCode $code, array $data, User $user): TaxRate
    {
        $this->authorization->authorize($company, $user);
        abort_unless($code->company_id === $company->id, 404);

        return DB::transaction(function () use ($company, $code, $data, $user) {
            TaxCode::whereKey($code->id)->lockForUpdate()->firstOrFail();
            $from = CarbonImmutable::parse($data['effective_from'])->toDateString();
            $to = isset($data['effective_to']) ? CarbonImmutable::parse($data['effective_to'])->toDateString() : null;
            if ($to && $to < $from) {
                throw ValidationException::withMessages(['effective_to' => 'Effective To must be on or after Effective From.']);
            }
            $overlap = $code->rates()->whereDate('effective_from', '<=', $to ?? '9999-12-31')->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => 'Tax Rate periods for one Tax Code cannot overlap.']);
            }
            $rate = $code->rates()->create($data + ['created_by' => $user->id, 'updated_by' => $user->id]);
            $this->audit->log('tax_rate.created', $rate, $company->id, $user->id);

            return $rate;
        });
    }

    public function settings(Company $company, array $data, User $user): TaxSetting
    {
        $this->authorization->authorize($company, $user);
        if (! empty($data['output_tax_account_id'])) {
            $company->accounts()->where('is_active', true)->where('type', 'liability')->findOrFail($data['output_tax_account_id']);
        }
        if (! empty($data['input_tax_account_id'])) {
            $company->accounts()->where('is_active', true)->where('type', 'asset')->findOrFail($data['input_tax_account_id']);
        }
        foreach (['default_sales_tax_code_id', 'default_purchase_tax_code_id'] as $field) {
            if (! empty($data[$field])) {
                $company->taxCodes()->where('country_id', $company->country_id)->findOrFail($data[$field]);
            }
        }
        $before = $company->taxSetting?->toArray();
        $settings = TaxSetting::updateOrCreate(['company_id' => $company->id], $data + ['updated_by' => $user->id]);
        $this->audit->log('tax_settings.updated', $settings, $company->id, $user->id, $before, $settings->toArray());

        return $settings;
    }

    public function generatePeriods(Company $company, TaxRegistration $registration, User $user): void
    {
        $this->authorization->authorize($company, $user);
        abort_unless($registration->company_id === $company->id, 404);
        $months = ['monthly' => 1, 'two_monthly' => 2, 'quarterly' => 3, 'six_monthly' => 6, 'annual' => 12][$registration->filing_frequency] ?? null;
        if (! $months || ! $registration->effective_from) {
            throw ValidationException::withMessages(['filing_frequency' => 'A supported filing frequency and Effective From date are required.']);
        }
        $end = $registration->effective_to ?? $registration->effective_from->addYear()->subDay();
        $cursor = $registration->effective_from->toImmutable();
        while ($cursor <= $end) {
            $periodEnd = $cursor->addMonths($months)->subDay()->min($end);
            $taxYear = $company->taxYears()->whereDate('starts_on', '<=', $cursor)->whereDate('ends_on', '>=', $periodEnd)->firstOrFail();
            TaxPeriod::firstOrCreate(['tax_obligation_id' => $registration->id, 'starts_on' => $cursor, 'ends_on' => $periodEnd], ['company_id' => $company->id, 'tax_year_id' => $taxYear->id, 'name' => $cursor->format('d M Y').' – '.$periodEnd->format('d M Y'), 'due_on' => null, 'status' => 'open', 'created_by' => $user->id, 'updated_by' => $user->id]);
            $cursor = $periodEnd->addDay();
        }
    }
}

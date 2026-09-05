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

    public function updateRegistration(Company $company, TaxRegistration $registration, array $data, User $user): TaxRegistration
    {
        $this->authorization->authorize($company, $user);
        abort_unless($registration->company_id === $company->id && $registration->country_id === $company->country_id, 404);

        return DB::transaction(function () use ($company, $registration, $data, $user) {
            $registration = TaxRegistration::where('company_id', $company->id)->lockForUpdate()->findOrFail($registration->id);
            $firstTaxDate = $company->transactionTaxLines()->where('tax_registration_id', $registration->id)->min('transaction_date');
            $lastTaxDate = $company->transactionTaxLines()->where('tax_registration_id', $registration->id)->max('transaction_date');
            if ($firstTaxDate && (($data['effective_from'] ?? $registration->effective_from->toDateString()) > $firstTaxDate || (($data['effective_to'] ?? null) && $data['effective_to'] < $lastTaxDate))) {
                throw ValidationException::withMessages(['effective_from' => 'Registration dates cannot exclude historical posted tax transactions.']);
            }
            if ($firstTaxDate && isset($data['tax_type']) && $data['tax_type'] !== $registration->tax_type) {
                throw ValidationException::withMessages(['tax_type' => 'Tax Type cannot change after posted tax activity exists.']);
            }
            $before = $registration->toArray();
            $registration->update($data + ['updated_by' => $user->id]);
            $this->audit->log('tax_registration.updated', $registration, $company->id, $user->id, $before, $registration->fresh()->toArray());

            return $registration->fresh();
        });
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

    public function updateCode(Company $company, TaxCode $code, array $data, User $user): TaxCode
    {
        $this->authorization->authorize($company, $user);
        abort_unless($code->company_id === $company->id && $code->country_id === $company->country_id, 404);

        return DB::transaction(function () use ($company, $code, $data, $user) {
            $code = TaxCode::where('company_id', $company->id)->lockForUpdate()->findOrFail($code->id);
            $firstTaxDate = $company->transactionTaxLines()->where('tax_code_id', $code->id)->min('transaction_date');
            $lastTaxDate = $company->transactionTaxLines()->where('tax_code_id', $code->id)->max('transaction_date');
            if ($firstTaxDate && (($data['effective_from'] ?? $code->effective_from->toDateString()) > $firstTaxDate || (($data['effective_to'] ?? null) && $data['effective_to'] < $lastTaxDate))) {
                throw ValidationException::withMessages(['effective_from' => 'Tax Code dates cannot exclude historical posted tax transactions.']);
            }
            foreach (['tax_registration_id', 'tax_type', 'code', 'treatment'] as $field) {
                if ($firstTaxDate && array_key_exists($field, $data) && (string) $data[$field] !== (string) $code->{$field}) {
                    throw ValidationException::withMessages([$field => 'Historical Tax Code identity and treatment are immutable.']);
                }
            }
            if (isset($data['tax_registration_id'])) {
                $registration = $company->taxRegistrations()->findOrFail($data['tax_registration_id']);
                if ($registration->tax_type !== ($data['tax_type'] ?? $code->tax_type)) {
                    throw ValidationException::withMessages(['tax_type' => 'Tax Code type must match its registration.']);
                }
            }
            $before = $code->toArray();
            $code->update($data + ['updated_by' => $user->id]);
            $this->audit->log('tax_code.updated', $code, $company->id, $user->id, $before, $code->fresh()->toArray());

            return $code->fresh();
        });
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

    public function updateRate(Company $company, TaxCode $code, TaxRate $rate, array $data, User $user): TaxRate
    {
        $this->authorization->authorize($company, $user);
        abort_unless($code->company_id === $company->id && $rate->tax_code_id === $code->id, 404);

        return DB::transaction(function () use ($company, $code, $rate, $data, $user) {
            TaxCode::whereKey($code->id)->lockForUpdate()->firstOrFail();
            $rate = TaxRate::where('tax_code_id', $code->id)->lockForUpdate()->findOrFail($rate->id);
            $used = $company->transactionTaxLines()->where('tax_code_id', $code->id)->where('rate_snapshot', $rate->rate)->whereDate('transaction_date', '>=', $rate->effective_from)->when($rate->effective_to, fn ($query, $to) => $query->whereDate('transaction_date', '<=', $to))->exists();
            if ($used && (bccomp((string) $data['rate'], (string) $rate->rate, 6) !== 0 || $data['effective_from'] !== $rate->effective_from->toDateString() || ($data['effective_to'] ?? null) !== $rate->effective_to?->toDateString() || (bool) ($data['is_active'] ?? false) !== $rate->is_active)) {
                throw ValidationException::withMessages(['rate' => 'An effective Tax Rate used by posted transactions is immutable. Add a future effective rate instead.']);
            }
            $from = CarbonImmutable::parse($data['effective_from'])->toDateString();
            $to = isset($data['effective_to']) ? CarbonImmutable::parse($data['effective_to'])->toDateString() : null;
            if ($to && $to < $from) {
                throw ValidationException::withMessages(['effective_to' => 'Effective To must be on or after Effective From.']);
            }
            $overlap = $code->rates()->whereKeyNot($rate->id)->whereDate('effective_from', '<=', $to ?? '9999-12-31')->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $from))->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['effective_from' => 'Tax Rate periods for one Tax Code cannot overlap.']);
            }
            $before = $rate->toArray();
            $rate->update($data + ['updated_by' => $user->id]);
            $this->audit->log('tax_rate.updated', $rate, $company->id, $user->id, $before, $rate->fresh()->toArray());

            return $rate->fresh();
        });
    }

    public function settings(Company $company, array $data, User $user): TaxSetting
    {
        $this->authorization->authorize($company, $user);
        if (! empty($data['output_tax_account_id'])) {
            $account = $company->accounts()->where('is_active', true)->where('type', 'liability')->findOrFail($data['output_tax_account_id']);
            if ($account->code === '2000' || $company->suppliers()->where('payable_account_id', $account->id)->exists()) {
                throw ValidationException::withMessages(['output_tax_account_id' => 'Accounts Payable cannot be used as the Output Tax Control Account.']);
            }
        }
        if (! empty($data['input_tax_account_id'])) {
            $account = $company->accounts()->where('is_active', true)->where('type', 'asset')->findOrFail($data['input_tax_account_id']);
            $bankAccount = DB::table('bank_accounts')->where('company_id', $company->id)->where('ledger_account_id', $account->id)->exists();
            if (in_array($account->code, ['1000', '1100'], true) || $bankAccount || $company->customers()->where('receivable_account_id', $account->id)->exists()) {
                throw ValidationException::withMessages(['input_tax_account_id' => 'Bank and Accounts Receivable accounts cannot be used as the Input Tax Control Account.']);
            }
        }
        if (! empty($data['rounding_account_id'])) {
            $company->accounts()->where('is_active', true)->findOrFail($data['rounding_account_id']);
        }
        foreach (['default_sales_tax_code_id', 'default_purchase_tax_code_id'] as $field) {
            if (! empty($data[$field])) {
                $company->taxCodes()->where('country_id', $company->country_id)->where('is_active', true)->findOrFail($data[$field]);
            }
        }
        $before = $company->taxSetting?->toArray();
        $settings = TaxSetting::updateOrCreate(['company_id' => $company->id], $data + ['updated_by' => $user->id]);
        $company->setRelation('taxSetting', $settings);
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
        $end = $registration->effective_to ?? $company->taxYears()->max('ends_on');
        if (! $end) {
            throw ValidationException::withMessages(['tax_period' => 'Create a Tax Year before generating Tax Periods.']);
        }
        $end = CarbonImmutable::parse($end);
        $cursor = $registration->effective_from->toImmutable();
        while ($cursor <= $end) {
            $periodEnd = $cursor->addMonths($months)->subDay()->min($end);
            $taxYear = $company->taxYears()->whereDate('starts_on', '<=', $cursor)->whereDate('ends_on', '>=', $periodEnd)->firstOrFail();
            TaxPeriod::firstOrCreate(['tax_obligation_id' => $registration->id, 'starts_on' => $cursor, 'ends_on' => $periodEnd], ['company_id' => $company->id, 'tax_year_id' => $taxYear->id, 'name' => $cursor->format('d M Y').' – '.$periodEnd->format('d M Y'), 'due_on' => null, 'status' => 'open', 'created_by' => $user->id, 'updated_by' => $user->id]);
            $cursor = $periodEnd->addDay();
        }
    }
}

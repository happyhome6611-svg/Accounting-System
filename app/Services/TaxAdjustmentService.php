<?php

namespace App\Services;

use App\Models\Company;
use App\Models\TaxAdjustment;
use App\Models\TaxPeriod;
use App\Models\TransactionTaxLine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TaxAdjustmentService
{
    public function __construct(private AccountingLockService $authorization, private JournalService $journals, private AuditLogger $audit) {}

    public function post(Company $company, array $data, User $user): TaxAdjustment
    {
        $this->authorization->authorize($company, $user);
        if (mb_strlen(trim($data['reason'])) < 10 || bccomp((string) $data['amount'], '0', 4) === 0) {
            throw ValidationException::withMessages(['adjustment' => 'A non-zero amount and clear reason are required.']);
        }

        return DB::transaction(function () use ($company, $data, $user) {
            $registration = $company->taxRegistrations()->where('status', 'active')->findOrFail($data['tax_registration_id']);
            $period = TaxPeriod::where('company_id', $company->id)->where('tax_obligation_id', $registration->id)->where('status', '!=', 'filed')->findOrFail($data['tax_period_id']);
            $code = $company->taxCodes()->where('tax_registration_id', $registration->id)->findOrFail($data['tax_code_id']);
            $control = $data['direction'] === 'output' ? $company->taxSetting?->output_tax_account_id : $company->taxSetting?->input_tax_account_id;
            $company->accounts()->where('is_active', true)->findOrFail($control);
            $offset = $company->accounts()->where('is_active', true)->findOrFail($data['offset_account_id']);
            $amount = ltrim((string) $data['amount'], '-');
            $positive = bccomp((string) $data['amount'], '0', 4) > 0;
            $controlDebit = ($data['direction'] === 'input') === $positive;
            $journal = $this->journals->create($company, ['journal_type' => 'adjusting', 'branch_id' => $data['branch_id'] ?? null, 'transaction_date' => $data['adjustment_date'], 'description' => 'Tax adjustment '.($data['reference'] ?? ''), 'reason' => $data['reason'], 'lines' => [['account_id' => $control, 'description' => $data['reason'], 'debit' => $controlDebit ? $amount : '0', 'credit' => $controlDebit ? '0' : $amount], ['account_id' => $offset->id, 'description' => $data['reason'], 'debit' => $controlDebit ? '0' : $amount, 'credit' => $controlDebit ? $amount : '0']]], $user);
            $this->journals->post($journal, $user);
            $adjustment = TaxAdjustment::create(['company_id' => $company->id, 'tax_registration_id' => $registration->id, 'tax_period_id' => $period->id, 'tax_code_id' => $code?->id, 'adjustment_date' => $data['adjustment_date'], 'amount' => $data['amount'], 'reason' => $data['reason'], 'reference' => $data['reference'] ?? null, 'status' => 'posted', 'journal_entry_id' => $journal->id, 'created_by' => $user->id]);
            TransactionTaxLine::create(['company_id' => $company->id, 'country_id' => $company->country_id, 'tax_registration_id' => $registration->id, 'tax_period_id' => $period->id, 'tax_code_id' => $code->id, 'journal_entry_id' => $journal->id, 'source_type' => TaxAdjustment::class, 'source_id' => $adjustment->id, 'source_line_type' => TaxAdjustment::class, 'source_line_id' => $adjustment->id, 'direction' => 'adjustment', 'transaction_date' => $data['adjustment_date'], 'tax_code_snapshot' => $code->code, 'tax_type_snapshot' => $registration->tax_type, 'treatment_snapshot' => $code->treatment, 'registration_number_snapshot' => $registration->registration_number, 'rate_snapshot' => '0', 'net_amount' => '0', 'tax_amount' => $data['amount'], 'gross_amount' => $data['amount']]);
            $this->audit->log('tax_adjustment.posted', $adjustment, $company->id, $user->id);

            return $adjustment;
        });
    }
}

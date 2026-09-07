<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class OpeningBalanceImportAdapter extends AbstractAdapter
{
    public function type(): string
    {
        return 'opening_balances';
    }

    public function fields(): array
    {
        return ['opening_ref' => ['label' => 'Opening Balance Reference', 'required' => true, 'aliases' => ['reference']], 'date' => ['label' => 'Date', 'required' => true, 'aliases' => []], 'account' => ['label' => 'Account Code', 'required' => true, 'aliases' => ['account_code']], 'debit' => ['label' => 'Debit', 'required' => false, 'aliases' => []], 'credit' => ['label' => 'Credit', 'required' => false, 'aliases' => []], 'description' => ['label' => 'Description', 'required' => true, 'aliases' => ['memo']]];
    }

    public function validate(Company $company, array $values, array $options): array
    {
        $errors = $this->required($values);
        if (! $this->account($company, $values['account'] ?? null)) {
            $errors[] = 'Account Code is invalid or inactive.';
        }
        $debit = (string) ($values['debit'] ?: 0);
        $credit = (string) ($values['credit'] ?: 0);
        if (! is_numeric($debit) || ! is_numeric($credit) || ((bccomp($debit, '0', 4) > 0) === (bccomp($credit, '0', 4) > 0))) {
            $errors[] = 'Each opening-balance line needs one positive Debit or Credit.';
        }

        return $this->result($errors, ['Opening balances are staging-only in v0.8; no ledger posting will occur.']);
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        throw new \LogicException('Opening Balance posting is intentionally not enabled in v0.8.');
    }
}

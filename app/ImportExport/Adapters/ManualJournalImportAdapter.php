<?php

namespace App\ImportExport\Adapters;

use App\Models\Company;
use App\Models\User;
use App\Services\JournalService;
use Illuminate\Database\Eloquent\Model;

final class ManualJournalImportAdapter extends AbstractAdapter
{
    public function __construct(private JournalService $journals) {}

    public function type(): string
    {
        return 'manual_journals';
    }

    public function fields(): array
    {
        return ['journal_ref' => ['label' => 'Journal Reference', 'required' => true, 'aliases' => ['journalref', 'reference']], 'date' => ['label' => 'Date', 'required' => true, 'aliases' => ['journal_date']], 'account' => ['label' => 'Account Code', 'required' => true, 'aliases' => ['account_code']], 'debit' => ['label' => 'Debit', 'required' => false, 'aliases' => []], 'credit' => ['label' => 'Credit', 'required' => false, 'aliases' => []], 'description' => ['label' => 'Description', 'required' => true, 'aliases' => ['memo']], 'branch' => ['label' => 'Branch Code', 'required' => false, 'aliases' => ['branch_code']]];
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
            $errors[] = 'Each journal line needs one positive Debit or Credit.';
        }
        if ($company->entity_type === 'individual' && ($values['branch'] ?? '')) {
            $errors[] = 'Individual imports cannot specify a branch.';
        }

        return $this->result($errors);
    }

    public function import(Company $company, array $values, array $options, User $user): Model
    {
        throw new \LogicException('Manual Journals are imported as grouped documents by ImportEngine.');
    }

    public function importGroup(Company $company, array $rows, array $options, User $user): Model
    {
        $first = $rows[0];
        $branchId = empty($first['branch']) ? ($options['default_branch_id'] ?? null) : $company->branches()->where('code', $first['branch'])->value('id');
        $journal = $this->journals->create($company, ['branch_id' => $branchId, 'transaction_date' => $first['date'], 'reference' => $first['journal_ref'], 'description' => $first['description'], 'lines' => array_map(fn ($row) => ['account_id' => $this->account($company, $row['account']), 'description' => $row['description'], 'debit' => $row['debit'] ?: '0', 'credit' => $row['credit'] ?: '0'], $rows)], $user);
        if (($options['posting_mode'] ?? 'draft') === 'post') {
            $this->journals->post($journal, $user);
        }

        return $journal;
    }
}

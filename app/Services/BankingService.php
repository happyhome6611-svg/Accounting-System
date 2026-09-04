<?php

namespace App\Services;

use App\Models\BankAccount;
use App\Models\BankingTransaction;
use App\Models\BankReconciliation;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\BankTransactionMatch;
use App\Models\Company;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BankingService
{
    public function __construct(private JournalService $journals, private AuditLogger $audit) {}

    public function createAccount(Company $company, array $data, User $user): BankAccount
    {
        $this->access($company, $user);
        $ledger = $company->accounts()->where('type', 'asset')->where('code', '!=', '1100')->findOrFail($data['ledger_account_id']);
        if ((int) $data['currency_id'] !== $company->base_currency_id) {
            throw ValidationException::withMessages(['currency_id' => 'Bank account currency must equal entity base currency.']);
        }
        $account = $company->bankAccounts()->create([...$data, 'ledger_account_id' => $ledger->id, 'created_by' => $user->id, 'updated_by' => $user->id]);
        $this->audit->log('bank_account.created', $account, $company->id, $user->id);

        return $account;
    }

    public function updateAccount(Company $company, BankAccount $account, array $data, User $user): BankAccount
    {
        $this->access($company, $user, $account);

        return DB::transaction(function () use ($company, $account, $data, $user) {
            $account = BankAccount::where('company_id', $company->id)->lockForUpdate()->findOrFail($account->id);
            $used = $this->isUsed($account);
            if ($used && ((isset($data['ledger_account_id']) && (int) $data['ledger_account_id'] !== $account->ledger_account_id) || (isset($data['currency_id']) && (int) $data['currency_id'] !== $account->currency_id))) {
                throw ValidationException::withMessages(['account' => 'Linked ledger account and currency cannot change after financial activity exists.']);
            }if (isset($data['ledger_account_id'])) {
                $company->accounts()->where('type', 'asset')->where('code', '!=', '1100')->findOrFail($data['ledger_account_id']);
            }if (isset($data['currency_id']) && (int) $data['currency_id'] !== $company->base_currency_id) {
                throw ValidationException::withMessages(['currency_id' => 'Currency must equal entity base currency.']);
            }$account->update([...$data, 'updated_by' => $user->id]);
            $this->audit->log('bank_account.updated', $account, $company->id, $user->id);

            return $account;
        });
    }

    public function setActive(Company $c, BankAccount $a, bool $active, User $u): void
    {
        $this->access($c, $u, $a);
        $a->update(['is_active' => $active, 'updated_by' => $u->id]);
        $this->audit->log($active ? 'bank_account.reactivated' : 'bank_account.deactivated', $a, $c->id, $u->id);
    }

    public function deleteAccount(Company $c, BankAccount $a, User $u): void
    {
        $this->access($c, $u, $a);
        DB::transaction(function () use ($c, $a) {
            $a = BankAccount::where('company_id', $c->id)->lockForUpdate()->findOrFail($a->id);
            if ($this->isUsed($a)) {
                throw ValidationException::withMessages(['account' => 'Used Bank/Cash accounts cannot be deleted. Deactivate instead.']);
            }DB::table('audit_logs')->where('auditable_type', BankAccount::class)->where('auditable_id', $a->id)->delete();
            $a->delete();
        });
    }

    public function transact(Company $c, array $d, User $u): BankingTransaction
    {
        $this->access($c, $u);
        if (bccomp((string) $d['amount'], '0', 4) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Amount must be positive.']);
        }

        return DB::transaction(function () use ($c, $d, $u) {
            $bank = $c->bankAccounts()->where('is_active', true)->lockForUpdate()->findOrFail($d['bank_account_id']);
            $type = $d['type'];
            $destination = null;
            $counter = null;
            if ($type === 'transfer') {
                $destination = $c->bankAccounts()->where('is_active', true)->lockForUpdate()->findOrFail($d['destination_bank_account_id']);
                if ($destination->id === $bank->id) {
                    throw ValidationException::withMessages(['destination_bank_account_id' => 'Transfer destination must differ from source.']);
                }$lines = [['account_id' => $destination->ledger_account_id, 'description' => $d['description'], 'debit' => $d['amount'], 'credit' => '0'], ['account_id' => $bank->ledger_account_id, 'description' => $d['description'], 'debit' => '0', 'credit' => $d['amount']]];
            } else {
                $allowed = in_array($type, ['interest_received', 'direct_income'], true) ? ['revenue', 'income', 'equity'] : ['expense', 'asset'];
                $counter = $c->accounts()->whereIn('type', $allowed)->where('is_active', true)->findOrFail($d['counterparty_account_id']);
                $incoming = in_array($type, ['interest_received', 'direct_income'], true);
                $lines = [['account_id' => $bank->ledger_account_id, 'description' => $d['description'], 'debit' => $incoming ? $d['amount'] : '0', 'credit' => $incoming ? '0' : $d['amount']], ['account_id' => $counter->id, 'description' => $d['description'], 'debit' => $incoming ? '0' : $d['amount'], 'credit' => $incoming ? $d['amount'] : '0']];
            }
            $journal = $this->journals->create($c, ['branch_id' => $d['branch_id'] ?? null, 'financial_year_id' => $d['financial_year_id'] ?? null, 'accounting_period_id' => $d['accounting_period_id'] ?? null, 'transaction_date' => $d['transaction_date'], 'reference' => $d['reference'] ?? null, 'description' => $d['description'], 'lines' => $lines], $u);
            $this->journals->post($journal, $u);
            $tx = BankingTransaction::create(['company_id' => $c->id, 'branch_id' => $journal->branch_id, 'financial_year_id' => $journal->financial_year_id, 'accounting_period_id' => $journal->accounting_period_id, 'bank_account_id' => $bank->id, 'destination_bank_account_id' => $destination?->id, 'counterparty_account_id' => $counter?->id, 'journal_entry_id' => $journal->id, 'type' => $type, 'transaction_date' => $d['transaction_date'], 'amount' => $d['amount'], 'reference' => $d['reference'] ?? null, 'description' => $d['description'], 'status' => 'posted', 'created_by' => $u->id]);
            $this->audit->log('bank_transaction.'.$type, $tx, $c->id, $u->id);

            return $tx;
        });
    }

    public function register(Company $c, BankAccount $a, ?string $from = null, ?string $to = null, ?int $branch = null, ?int $year = null): Collection
    {
        $balance = '0.0000';

        return DB::table('journal_lines as l')->join('journal_entries as j', 'j.id', '=', 'l.journal_entry_id')->where('j.company_id', $c->id)->where('l.account_id', $a->ledger_account_id)->whereIn('j.status', ['posted', 'reversed'])->when($from, fn ($q) => $q->whereDate('j.transaction_date', '>=', $from))->when($to, fn ($q) => $q->whereDate('j.transaction_date', '<=', $to))->when($branch, fn ($q) => $q->where('j.branch_id', $branch))->when($year, fn ($q) => $q->where('j.financial_year_id', $year))->orderBy('j.transaction_date')->orderBy('j.id')->select('l.id as journal_line_id', 'j.id as journal_entry_id', 'j.transaction_date', 'j.reference', 'j.description', 'l.debit as money_in', 'l.credit as money_out')->get()->map(function ($r) use (&$balance) {
            $balance = bcadd($balance, bcsub($r->money_in, $r->money_out, 4), 4);
            $r->balance = $balance;

            return $r;
        });
    }

    public function import(Company $c, BankAccount $a, string $name, string $csv, User $u, bool $override = false): BankStatementImport
    {
        $this->access($c, $u, $a);
        $lines = preg_split('/\R/', trim($csv));
        $header = array_map('strtolower', str_getcsv(array_shift($lines)));
        $batch = BankStatementImport::create(['company_id' => $c->id, 'bank_account_id' => $a->id, 'file_name' => basename($name), 'file_hash' => hash('sha256', $csv), 'row_count' => count($lines), 'status' => 'preview', 'imported_by' => $u->id]);
        $duplicates = 0;
        $errors = 0;
        $imported = 0;
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }$row = array_combine($header, str_getcsv($line));
            try {
                $date = now()->parse($row['date'])->toDateString();
                $in = (string) ($row['credit'] ?? 0);
                $out = (string) ($row['debit'] ?? 0);
                if (isset($row['amount'])) {
                    if (bccomp((string) $row['amount'], '0', 4) >= 0) {
                        $in = (string) $row['amount'];
                        $out = '0';
                    } else {
                        $in = '0';
                        $out = ltrim((string) $row['amount'], '-');
                    }
                }if ((bccomp($in, '0', 4) > 0) === (bccomp($out, '0', 4) > 0)) {
                    throw new \RuntimeException;
                }$fp = hash('sha256', implode('|', [$date, $in, $out, $row['reference'] ?? '', $row['description'] ?? '']));
                $exists = $a->imports()->whereHas('rows', fn ($q) => $q->where('fingerprint', $fp))->exists();
                if ($exists && ! $override) {
                    $duplicates++;

                    continue;
                }BankStatementTransaction::create(['company_id' => $c->id, 'bank_account_id' => $a->id, 'bank_statement_import_id' => $batch->id, 'transaction_date' => $date, 'description' => $row['description'] ?? '', 'reference' => $row['reference'] ?? null, 'money_in' => $in, 'money_out' => $out, 'fingerprint' => $override && $exists ? hash('sha256', $fp.'|'.$batch->id) : $fp]);
                $imported++;
            } catch (\Throwable) {
                $errors++;
            }
        }
        $batch->update(['imported_count' => $imported, 'duplicate_count' => $duplicates, 'error_count' => $errors, 'status' => 'imported', 'imported_at' => now()]);
        $this->audit->log('bank_statement.imported', $batch, $c->id, $u->id);

        return $batch->load('rows');
    }

    public function match(Company $c, BankStatementTransaction $row, JournalLine $line, User $u): BankTransactionMatch
    {
        $this->access($c, $u);

        return DB::transaction(function () use ($c, $row, $line, $u) {
            $row = BankStatementTransaction::where('company_id', $c->id)->lockForUpdate()->findOrFail($row->id);
            $line = JournalLine::where('company_id', $c->id)->lockForUpdate()->findOrFail($line->id);
            $bank = BankAccount::where('company_id', $c->id)->findOrFail($row->bank_account_id);
            if ($line->account_id !== $bank->ledger_account_id || bccomp(bcsub($line->debit, $line->credit, 4), bcsub($row->money_in, $row->money_out, 4), 4) !== 0) {
                throw ValidationException::withMessages(['match' => 'Bank account and signed amount must match exactly.']);
            }$match = BankTransactionMatch::create(['company_id' => $c->id, 'bank_statement_transaction_id' => $row->id, 'journal_line_id' => $line->id, 'matched_by' => $u->id, 'matched_at' => now()]);
            $row->update(['status' => 'matched']);
            $this->audit->log('bank_statement.matched', $row, $c->id, $u->id);

            return $match;
        });
    }

    public function createFromStatement(Company $c, BankStatementTransaction $row, array $data, User $u): BankingTransaction
    {
        $this->access($c, $u);
        $row = BankStatementTransaction::where('company_id', $c->id)->where('status', 'unmatched')->findOrFail($row->id);
        $incoming = bccomp($row->money_in, '0', 4) > 0;
        if (($incoming && ! in_array($data['type'], ['interest_received', 'direct_income'], true)) || (! $incoming && ! in_array($data['type'], ['bank_fee', 'interest_paid', 'direct_expense'], true))) {
            throw ValidationException::withMessages(['type' => 'Transaction type must match the statement money direction.']);
        }
        $transaction = $this->transact($c, [...$data, 'bank_account_id' => $row->bank_account_id, 'transaction_date' => $row->transaction_date->toDateString(), 'amount' => $incoming ? $row->money_in : $row->money_out, 'reference' => $row->reference, 'description' => $row->description], $u);
        $line = JournalLine::where('journal_entry_id', $transaction->journal_entry_id)->where('account_id', BankAccount::findOrFail($row->bank_account_id)->ledger_account_id)->firstOrFail();
        $this->match($c, $row, $line, $u);

        return $transaction;
    }

    public function reconcile(Company $c, BankAccount $a, array $d, User $u): BankReconciliation
    {
        $this->access($c, $u, $a);
        $book = $this->register($c, $a, null, $d['statement_end_date'])->last()?->balance ?? '0.0000';
        $difference = bcsub((string) $d['statement_closing_balance'], $book, 4);
        $r = BankReconciliation::create(['company_id' => $c->id, 'bank_account_id' => $a->id, 'statement_start_date' => $d['statement_start_date'] ?? null, 'statement_end_date' => $d['statement_end_date'], 'statement_closing_balance' => $d['statement_closing_balance'], 'book_balance' => $book, 'reconciled_balance' => $book, 'difference' => $difference, 'status' => 'draft', 'prepared_by' => $u->id, 'prepared_at' => now(), 'notes' => $d['notes'] ?? null]);
        $this->audit->log('bank_reconciliation.created', $r, $c->id, $u->id);

        return $r;
    }

    public function complete(Company $c, BankReconciliation $r, User $u): void
    {
        $this->access($c, $u);
        DB::transaction(function () use ($c, $r, $u) {
            $r = BankReconciliation::where('company_id', $c->id)->lockForUpdate()->findOrFail($r->id);
            if (bccomp($r->difference, '0', 4) !== 0) {
                throw ValidationException::withMessages(['difference' => 'Reconciliation difference must be zero before completion.']);
            }$r->update(['status' => 'completed', 'completed_at' => now()]);
            $this->audit->log('bank_reconciliation.completed', $r, $c->id, $u->id);
        });
    }

    private function isUsed(BankAccount $a): bool
    {
        return DB::table('journal_lines')->where('account_id', $a->ledger_account_id)->exists() || $a->imports()->exists() || BankingTransaction::where('bank_account_id', $a->id)->orWhere('destination_bank_account_id', $a->id)->exists();
    }

    private function access(Company $c, User $u, ?BankAccount $a = null): void
    {
        abort_unless($u->companies()->whereKey($c->id)->exists() && (! $a || $a->company_id === $c->id), 404);
    }
}

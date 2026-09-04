<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankAccount extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['opening_date' => 'date', 'is_active' => 'boolean'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function ledgerAccount()
    {
        return $this->belongsTo(Account::class, 'ledger_account_id');
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function imports()
    {
        return $this->hasMany(BankStatementImport::class);
    }

    public function reconciliations()
    {
        return $this->hasMany(BankReconciliation::class);
    }
}

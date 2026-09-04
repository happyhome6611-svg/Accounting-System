<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'money_in' => 'decimal:4', 'money_out' => 'decimal:4'];
    }

    public function match()
    {
        return $this->hasOne(BankTransactionMatch::class);
    }
}

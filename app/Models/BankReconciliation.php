<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BankReconciliation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['statement_start_date' => 'date', 'statement_end_date' => 'date', 'prepared_at' => 'datetime', 'completed_at' => 'datetime', 'statement_closing_balance' => 'decimal:4', 'book_balance' => 'decimal:4', 'reconciled_balance' => 'decimal:4', 'difference' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if ($r->getOriginal('status') === 'completed') {
                throw new LogicException('Completed reconciliations are immutable.');
            }
        });
    }
}

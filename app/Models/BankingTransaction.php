<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class BankingTransaction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'amount' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted banking transactions are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted banking transactions cannot be deleted.'));
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}

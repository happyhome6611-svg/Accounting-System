<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class JournalLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    protected static function booted(): void
    {
        $protectPosted = function (self $line) {
            if ($line->journal()->where('status', '!=', 'draft')->exists() && ! app()->bound('accounting.system-write')) {
                throw new LogicException('Posted journal lines are immutable.');
            }
        };
        static::updating($protectPosted);
        static::deleting($protectPosted);
    }
}

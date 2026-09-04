<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class YearEndClosure extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['net_result' => 'decimal:4', 'completed_at' => 'datetime'];
    }

    public function closingJournal()
    {
        return $this->belongsTo(JournalEntry::class, 'closing_journal_id');
    }
}

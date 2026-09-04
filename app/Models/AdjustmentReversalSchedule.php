<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdjustmentReversalSchedule extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reversal_date' => 'date', 'posted_at' => 'datetime'];
    }

    public function adjustmentJournal()
    {
        return $this->belongsTo(JournalEntry::class, 'adjustment_journal_id');
    }

    public function reversalJournal()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_id');
    }
}

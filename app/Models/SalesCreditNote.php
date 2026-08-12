<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesCreditNote extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['credit_note_date' => 'date', 'total' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(SalesCreditNoteLine::class);
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}

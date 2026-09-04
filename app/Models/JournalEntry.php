<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class JournalEntry extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'posted_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    public function period()
    {
        return $this->belongsTo(AccountingPeriod::class, 'accounting_period_id');
    }

    public function financialYear()
    {
        return $this->belongsTo(FinancialYear::class);
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function reversalSchedule()
    {
        return $this->hasOne(AdjustmentReversalSchedule::class, 'adjustment_journal_id');
    }

    protected static function booted(): void
    {
        static::updating(function (self $j) {
            if ($j->getOriginal('status') !== 'draft' && ! app()->bound('accounting.system-write')) {
                throw new LogicException('Posted journals are immutable.');
            }
        });
        static::deleting(function (self $j) {
            if ($j->status !== 'draft') {
                throw new LogicException('Posted journals cannot be deleted.');
            }
        });
    }
}

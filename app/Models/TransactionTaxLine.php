<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class TransactionTaxLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['transaction_date' => 'date', 'rate_snapshot' => 'decimal:6', 'net_amount' => 'decimal:4', 'tax_amount' => 'decimal:4', 'gross_amount' => 'decimal:4'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted transaction tax snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Posted transaction tax snapshots cannot be deleted.'));
    }

    public function registration()
    {
        return $this->belongsTo(TaxRegistration::class, 'tax_registration_id');
    }

    public function period()
    {
        return $this->belongsTo(TaxPeriod::class, 'tax_period_id');
    }

    public function source()
    {
        return $this->morphTo(__FUNCTION__, 'source_type', 'source_id');
    }
}

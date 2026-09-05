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
}

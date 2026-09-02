<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccountingPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'locked_at' => 'datetime', 'reopened_at' => 'datetime'];
    }

    public function financialYear()
    {
        return $this->belongsTo(FinancialYear::class);
    }
}

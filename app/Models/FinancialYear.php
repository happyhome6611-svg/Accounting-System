<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialYear extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_current' => 'boolean'];
    }

    public function periods()
    {
        return $this->hasMany(AccountingPeriod::class);
    }
}

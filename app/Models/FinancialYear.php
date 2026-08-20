<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialYear extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_current' => 'boolean', 'closed_at' => 'datetime', 'reopened_at' => 'datetime', 'filed_at' => 'datetime'];
    }

    public function periods()
    {
        return $this->hasMany(AccountingPeriod::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function taxYears()
    {
        return $this->hasMany(TaxYear::class);
    }
}

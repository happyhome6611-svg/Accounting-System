<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxYear extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function filingPeriods()
    {
        return $this->hasMany(TaxFilingPeriod::class);
    }
}

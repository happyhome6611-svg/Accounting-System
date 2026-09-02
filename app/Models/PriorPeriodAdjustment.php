<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriorPeriodAdjustment extends Model
{
    protected $guarded = [];

    public function originFinancialYear()
    {
        return $this->belongsTo(FinancialYear::class, 'origin_financial_year_id');
    }

    public function adjustmentFinancialYear()
    {
        return $this->belongsTo(FinancialYear::class, 'adjustment_financial_year_id');
    }
}

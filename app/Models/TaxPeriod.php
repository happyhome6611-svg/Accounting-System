<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxPeriod extends Model
{
    protected $table = 'tax_filing_periods';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'due_on' => 'date', 'prepared_snapshot' => 'array', 'prepared_at' => 'datetime', 'filed_at' => 'datetime'];
    }

    public function registration()
    {
        return $this->belongsTo(TaxRegistration::class, 'tax_obligation_id');
    }
}

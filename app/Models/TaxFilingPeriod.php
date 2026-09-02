<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxFilingPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'due_on' => 'date'];
    }
}

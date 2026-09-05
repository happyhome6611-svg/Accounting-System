<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxAdjustment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['adjustment_date' => 'date', 'amount' => 'decimal:4'];
    }
}

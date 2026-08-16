<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesQuotationLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount' => 'decimal:4', 'line_amount' => 'decimal:4'];
    }
}

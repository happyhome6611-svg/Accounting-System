<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesQuotation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['quotation_date' => 'date', 'expiry_date' => 'date', 'subtotal' => 'decimal:4', 'total' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(SalesQuotationLine::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'subtotal' => 'decimal:4', 'total' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class);
    }
}

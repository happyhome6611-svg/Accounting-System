<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CarryForward extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['original_amount' => 'decimal:4', 'amount_used' => 'decimal:4', 'amount_remaining' => 'decimal:4'];
    }
}

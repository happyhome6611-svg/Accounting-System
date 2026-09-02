<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sales_price' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function revenueAccount()
    {
        return $this->belongsTo(Account::class);
    }
}

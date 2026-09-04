<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankTransactionMatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['matched_at' => 'datetime'];
    }
}

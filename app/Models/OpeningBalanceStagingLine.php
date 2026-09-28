<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningBalanceStagingLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['debit' => 'decimal:4', 'credit' => 'decimal:4'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}

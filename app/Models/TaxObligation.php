<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxObligation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['configuration' => 'array'];
    }
}

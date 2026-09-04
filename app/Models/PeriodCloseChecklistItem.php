<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PeriodCloseChecklistItem extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean', 'is_system_check' => 'boolean', 'completed_at' => 'datetime'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportLog extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['filters' => 'array', 'generated_at' => 'datetime'];
    }
}

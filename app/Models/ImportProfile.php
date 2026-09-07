<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportProfile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_headers' => 'array', 'mapping' => 'array', 'options' => 'array'];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_main_branch' => 'boolean'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}

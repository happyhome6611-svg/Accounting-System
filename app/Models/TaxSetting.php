<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxSetting extends Model
{
    protected $guarded = [];

    public function company()
    {
        return $this->belongsTo(Company::class);
    }
}

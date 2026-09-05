<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxCode extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'effective_from' => 'date', 'effective_to' => 'date'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function registration()
    {
        return $this->belongsTo(TaxRegistration::class, 'tax_registration_id');
    }

    public function rates()
    {
        return $this->hasMany(TaxRate::class);
    }
}

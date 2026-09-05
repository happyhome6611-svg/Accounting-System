<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRegistration extends Model
{
    protected $table = 'tax_obligations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_to' => 'date', 'configuration' => 'array'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function taxCodes()
    {
        return $this->hasMany(TaxCode::class);
    }

    public function periods()
    {
        return $this->hasMany(TaxPeriod::class, 'tax_obligation_id');
    }
}

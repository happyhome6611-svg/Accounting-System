<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'legal_name', 'country_id', 'base_currency_id', 'timezone', 'accounting_configuration', 'tax_configuration', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['accounting_configuration' => 'array', 'tax_configuration' => 'array'];
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function financialYears()
    {
        return $this->hasMany(FinancialYear::class);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityImportBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_headers' => 'array', 'mapping' => 'array', 'raw_values' => 'array', 'mapped_values' => 'array', 'warnings' => 'array', 'errors' => 'array', 'confirmed_at' => 'datetime'];
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function resultCompany()
    {
        return $this->belongsTo(Company::class, 'result_company_id');
    }
}

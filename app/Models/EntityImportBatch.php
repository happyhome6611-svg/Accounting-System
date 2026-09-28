<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EntityImportBatch extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_headers' => 'array', 'mapping' => 'array', 'raw_values' => 'array', 'mapped_values' => 'array', 'warnings' => 'array', 'errors' => 'array', 'undo_summary' => 'array', 'confirmed_at' => 'datetime', 'undone_at' => 'datetime'];
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function resultCompany()
    {
        return $this->belongsTo(Company::class, 'result_company_id');
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

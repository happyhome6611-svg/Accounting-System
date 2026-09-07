<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_headers' => 'array', 'mapping' => 'array', 'options' => 'array', 'uploaded_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function rows()
    {
        return $this->hasMany(ImportRow::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

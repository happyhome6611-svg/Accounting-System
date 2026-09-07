<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportRow extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['raw_values' => 'array', 'mapped_values' => 'array', 'warnings' => 'array', 'errors' => 'array'];
    }

    public function batch()
    {
        return $this->belongsTo(ImportBatch::class, 'import_batch_id');
    }
}

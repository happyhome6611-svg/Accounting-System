<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningBalanceStaging extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['balance_date' => 'date'];
    }

    public function lines()
    {
        return $this->hasMany(OpeningBalanceStagingLine::class);
    }

    public function importBatch()
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}

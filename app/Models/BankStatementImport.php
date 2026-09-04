<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['imported_at' => 'datetime'];
    }

    public function rows()
    {
        return $this->hasMany(BankStatementTransaction::class);
    }
}

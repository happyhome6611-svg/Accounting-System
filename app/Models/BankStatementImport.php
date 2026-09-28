<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BankStatementImport extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['undo_summary' => 'array', 'imported_at' => 'datetime', 'undone_at' => 'datetime'];
    }

    public function rows()
    {
        return $this->hasMany(BankStatementTransaction::class);
    }
}

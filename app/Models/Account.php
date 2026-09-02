<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Account extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_system' => 'boolean', 'is_active' => 'boolean'];
    }

    public function journalLines()
    {
        return $this->hasMany(JournalLine::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $account) {
            if ($account->journalLines()->whereHas('journal', fn ($q) => $q->whereIn('status', ['posted', 'reversed']))->exists()) {
                throw new \LogicException('Accounts used in posted journals cannot be deleted.');
            }
        });
    }
}

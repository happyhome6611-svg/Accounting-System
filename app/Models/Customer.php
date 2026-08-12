<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

class Customer extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tax_identifiers' => 'array', 'credit_limit' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function receivableAccount()
    {
        return $this->belongsTo(Account::class);
    }

    public function invoices()
    {
        return $this->hasMany(SalesInvoice::class);
    }

    protected static function booted(): void
    {
        static::deleting(function (self $m) {
            if ($m->invoices()->exists()) {
                throw new LogicException('Customers referenced by invoices cannot be deleted.');
            }
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'credit_limit' => 'decimal:4'];
    }

    public function bills()
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function orders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}

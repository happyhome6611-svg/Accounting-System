<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupplierPaymentAllocation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:4'];
    }

    public function bill()
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }
}

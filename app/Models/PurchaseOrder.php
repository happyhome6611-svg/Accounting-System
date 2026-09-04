<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPostedPurchaseDocument;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use ProtectsPostedPurchaseDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'expected_date' => 'date', 'subtotal' => 'decimal:4', 'total' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function bill()
    {
        return $this->hasOne(SupplierBill::class);
    }
}

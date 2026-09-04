<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPostedPurchaseDocument;
use Illuminate\Database\Eloquent\Model;

class SupplierPayment extends Model
{
    use ProtectsPostedPurchaseDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:4'];
    }

    public function allocations()
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }
}

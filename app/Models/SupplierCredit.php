<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPostedPurchaseDocument;
use Illuminate\Database\Eloquent\Model;

class SupplierCredit extends Model
{
    use ProtectsPostedPurchaseDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['credit_date' => 'date', 'total' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(SupplierCreditLine::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bill()
    {
        return $this->belongsTo(SupplierBill::class, 'supplier_bill_id');
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }
}

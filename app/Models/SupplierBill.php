<?php

namespace App\Models;

use App\Models\Concerns\ProtectsPostedPurchaseDocument;
use Illuminate\Database\Eloquent\Model;

class SupplierBill extends Model
{
    use ProtectsPostedPurchaseDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bill_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:4', 'tax_amount' => 'decimal:4', 'total' => 'decimal:4', 'amount_paid' => 'decimal:4', 'amount_credited' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(SupplierBillLine::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function allocations()
    {
        return $this->hasMany(SupplierPaymentAllocation::class);
    }

    public function credits()
    {
        return $this->hasMany(SupplierCredit::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function getAmountDueAttribute(): string
    {
        return bcsub(bcsub($this->total, $this->amount_paid, 4), $this->amount_credited, 4);
    }
}

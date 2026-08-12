<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerReceipt extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['receipt_date' => 'date', 'amount' => 'decimal:4'];
    }

    public function allocations()
    {
        return $this->hasMany(CustomerReceiptAllocation::class);
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }
}

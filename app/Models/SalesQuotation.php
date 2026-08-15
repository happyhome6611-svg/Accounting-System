<?php

namespace App\Models;

use App\Models\Concerns\ProtectsFinalSalesDocument;
use Illuminate\Database\Eloquent\Model;

class SalesQuotation extends Model
{
    use ProtectsFinalSalesDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quotation_date' => 'date', 'expiry_date' => 'date', 'subtotal' => 'decimal:4', 'total' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(SalesQuotationLine::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function convertedOrder()
    {
        return $this->hasOne(SalesOrder::class);
    }
}

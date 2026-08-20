<?php

namespace App\Models;

use App\Models\Concerns\ProtectsFinalSalesDocument;
use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    use ProtectsFinalSalesDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_date' => 'date', 'subtotal' => 'decimal:4', 'total' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(SalesOrderLine::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function financialYear()
    {
        return $this->belongsTo(FinancialYear::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function quotation()
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    public function convertedInvoice()
    {
        return $this->hasOne(SalesInvoice::class);
    }
}

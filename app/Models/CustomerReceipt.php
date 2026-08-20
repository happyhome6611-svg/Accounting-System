<?php

namespace App\Models;

use App\Models\Concerns\ProtectsFinalSalesDocument;
use Illuminate\Database\Eloquent\Model;

class CustomerReceipt extends Model
{
    use ProtectsFinalSalesDocument;

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

    public function financialYear()
    {
        return $this->belongsTo(FinancialYear::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}

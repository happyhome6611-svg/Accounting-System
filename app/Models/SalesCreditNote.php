<?php

namespace App\Models;

use App\Models\Concerns\ProtectsFinalSalesDocument;
use Illuminate\Database\Eloquent\Model;

class SalesCreditNote extends Model
{
    use ProtectsFinalSalesDocument;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['credit_note_date' => 'date', 'total' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(SalesCreditNoteLine::class);
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function invoice()
    {
        return $this->belongsTo(SalesInvoice::class, 'sales_invoice_id');
    }
}

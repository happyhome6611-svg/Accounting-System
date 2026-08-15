<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class SalesInvoice extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'due_date' => 'date', 'subtotal' => 'decimal:4', 'tax_amount' => 'decimal:4', 'total' => 'decimal:4', 'amount_paid' => 'decimal:4'];
    }

    public function lines()
    {
        return $this->hasMany(SalesInvoiceLine::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function journal()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function allocations()
    {
        return $this->hasMany(CustomerReceiptAllocation::class);
    }

    public function creditNotes()
    {
        return $this->hasMany(SalesCreditNote::class);
    }

    public function accountingPeriod()
    {
        return $this->belongsTo(AccountingPeriod::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function order()
    {
        return $this->belongsTo(SalesOrder::class, 'sales_order_id');
    }

    public function getAmountDueAttribute(): string
    {
        $credited = $this->creditNotes()->where('status', 'posted')->sum('total');

        return bcsub(bcsub($this->total, $this->amount_paid, 4), (string) $credited, 4);
    }

    protected static function booted(): void
    {
        static::updating(function (self $m) {
            if ($m->getOriginal('status') !== 'draft' && ! app()->bound('sales.system-write')) {
                throw new LogicException('Posted invoices are immutable.');
            }
        });
        static::deleting(function (self $m) {
            if ($m->status !== 'draft') {
                throw new LogicException('Posted invoices cannot be deleted.');
            }
        });
    }
}

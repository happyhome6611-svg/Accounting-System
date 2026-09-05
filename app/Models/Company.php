<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Company extends Model
{
    use SoftDeletes;

    protected $fillable = ['entity_type', 'name', 'legal_name', 'individual_name', 'trading_name', 'country_id', 'base_currency_id', 'timezone', 'address', 'email', 'phone', 'registration_identifiers', 'is_active', 'accounting_configuration', 'tax_configuration', 'bookkeeping_lock_date', 'adviser_lock_date', 'retained_earnings_account_id', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['registration_identifiers' => 'array', 'is_active' => 'boolean', 'accounting_configuration' => 'array', 'tax_configuration' => 'array', 'bookkeeping_lock_date' => 'date', 'adviser_lock_date' => 'date'];
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function baseCurrency()
    {
        return $this->belongsTo(Currency::class, 'base_currency_id');
    }

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function financialYears()
    {
        return $this->hasMany(FinancialYear::class);
    }

    public function accounts()
    {
        return $this->hasMany(Account::class);
    }

    public function journals()
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function branches()
    {
        return $this->hasMany(Branch::class);
    }

    public function supportsBranches(): bool
    {
        return in_array($this->entity_type, ['company', 'sole_trader', 'partnership', 'trust', 'other'], true);
    }

    public function getEntityLabelAttribute(): string
    {
        return match ($this->entity_type) {
            'individual' => $this->individual_name ?: $this->name,
            'sole_trader' => $this->trading_name ?: $this->individual_name ?: $this->name,
            default => $this->name,
        };
    }

    public function taxYears()
    {
        return $this->hasMany(TaxYear::class);
    }

    public function salesInvoices()
    {
        return $this->hasMany(SalesInvoice::class);
    }

    public function suppliers()
    {
        return $this->hasMany(Supplier::class);
    }

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function supplierBills()
    {
        return $this->hasMany(SupplierBill::class);
    }

    public function bankAccounts()
    {
        return $this->hasMany(BankAccount::class);
    }

    public function taxRegistrations()
    {
        return $this->hasMany(TaxRegistration::class);
    }

    public function taxCodes()
    {
        return $this->hasMany(TaxCode::class);
    }

    public function taxSetting()
    {
        return $this->hasOne(TaxSetting::class);
    }

    public function transactionTaxLines()
    {
        return $this->hasMany(TransactionTaxLine::class);
    }
}

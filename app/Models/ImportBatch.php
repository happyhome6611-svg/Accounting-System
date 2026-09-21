<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_headers' => 'array', 'mapping' => 'array', 'options' => 'array', 'uploaded_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function rows()
    {
        return $this->hasMany(ImportRow::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function statusLabel(): string
    {
        if ($this->status === 'validated' && $this->total_rows > 0 && $this->invalid_rows === $this->total_rows) {
            return 'Validation Failed';
        }

        if ($this->status === 'validated' && $this->invalid_rows > 0) {
            return 'Validation Errors';
        }

        return str($this->status)->replace('_', ' ')->title()->toString();
    }

    public function documentField(): ?string
    {
        return match ($this->data_type) {
            'sales_invoices' => 'invoice_ref',
            'supplier_bills' => 'bill_ref',
            default => null,
        };
    }

    public function documentCount(): ?int
    {
        $field = $this->documentField();

        return $field ? $this->rows()->pluck('mapped_values')->pluck($field)->filter()->unique()->count() : null;
    }

    public function importedDocumentCount(): ?int
    {
        return $this->documentField() ? $this->rows()->whereNotNull('result_id')->distinct()->count('result_id') : null;
    }
}

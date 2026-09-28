<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ImportBatch extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['source_headers' => 'array', 'mapping' => 'array', 'options' => 'array', 'undo_summary' => 'array', 'uploaded_at' => 'datetime', 'confirmed_at' => 'datetime', 'undone_at' => 'datetime'];
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
        if ($this->undo_status === 'undone') {
            return 'Undone';
        }
        if ($this->undo_status === 'blocked') {
            return 'Undo Blocked';
        }
        if ($this->total_rows > 0 && $this->imported_rows === 0 && $this->duplicate_rows === $this->total_rows) {
            return 'Duplicate Only';
        }
        if ($this->status === 'validated' && $this->total_rows > 0 && $this->invalid_rows === $this->total_rows) {
            return 'Validation Failed';
        }

        if ($this->status === 'validated' && $this->invalid_rows > 0) {
            return 'Validation Errors';
        }

        return str($this->status)->replace('_', ' ')->title()->toString();
    }

    public function hasCreatedRecords(): bool
    {
        return $this->rows()->whereNotNull('result_id')->exists();
    }

    public function originalSuccessfulBatch(): ?self
    {
        $fingerprints = $this->rows()->where('duplicate_status', '!=', 'not_duplicate')->pluck('row_fingerprint')->unique();
        if ($fingerprints->isEmpty()) {
            return null;
        }
        $ids = ImportRow::query()->whereIn('row_fingerprint', $fingerprints)->where('validation_status', 'imported')->whereHas('batch', fn ($query) => $query->where('company_id', $this->company_id)->where('data_type', $this->data_type))->pluck('import_batch_id')->unique();

        return $ids->count() === 1 ? self::find($ids->first()) : null;
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

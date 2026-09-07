<?php

namespace App\ImportExport\Adapters;

use App\ImportExport\Contracts\ImportAdapter;
use App\Models\Company;

abstract class AbstractAdapter implements ImportAdapter
{
    protected function result(array $errors = [], array $warnings = [], string $duplicate = 'not_duplicate'): array
    {
        return compact('errors', 'warnings', 'duplicate');
    }

    protected function required(array $values): array
    {
        return collect($this->fields())->filter(fn ($field) => $field['required'])->keys()->filter(fn ($key) => trim((string) ($values[$key] ?? '')) === '')->map(fn ($key) => $this->fields()[$key]['label'].' is required.')->values()->all();
    }

    protected function account(Company $company, string|int|null $value, ?array $types = null): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $query = $company->accounts()->where('is_active', true)->where(fn ($q) => $q->where('code', (string) $value)->orWhere('id', is_numeric($value) ? (int) $value : 0));
        if ($types) {
            $query->whereIn('type', $types);
        }

        return $query->value('id');
    }

    protected function bool(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(mb_strtolower(trim((string) $value)), ['1', 'yes', 'y', 'true', 'active'], true);
    }
}

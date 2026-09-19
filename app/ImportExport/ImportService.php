<?php

namespace App\ImportExport;

use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\ImportProfile;
use App\Models\ImportRow;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ImportService
{
    public function __construct(private FileParser $parser, private ImportAdapterRegistry $adapters, private HeaderMapper $mapper, private AuditLogger $audit) {}

    public function upload(Company $company, string $dataType, UploadedFile $file, ?int $branchId, User $user): ImportBatch
    {
        $this->authorize($company, $user);
        $this->adapters->get($dataType);
        if (! $company->supportsBranches() && $branchId) {
            abort(422, 'Individual imports cannot specify a branch.');
        }
        if ($branchId) {
            $company->branches()->where('is_active', true)->findOrFail($branchId);
        }
        $format = mb_strtolower($file->getClientOriginalExtension());
        if (! in_array($format, ['csv', 'xlsx'], true)) {
            throw ValidationException::withMessages(['file' => 'Only CSV and XLSX files are supported.']);
        }
        $fingerprint = hash_file('sha256', $file->getRealPath());
        $storedName = bin2hex(random_bytes(20)).'.'.$format;
        $path = $file->storeAs(config('imports.path'), $storedName, config('imports.disk'));
        if (! $path) {
            throw ValidationException::withMessages(['file' => 'The file could not be stored safely.']);
        }
        $batch = $company->importBatches()->create(['country_id' => $company->country_id, 'branch_id' => $branchId, 'data_type' => $dataType, 'original_filename' => basename($file->getClientOriginalName()), 'stored_path' => $path, 'file_format' => $format, 'file_fingerprint' => $fingerprint, 'status' => 'uploaded', 'options' => ['default_branch_id' => $branchId], 'uploaded_by' => $user->id, 'uploaded_at' => now()]);
        $this->audit->log('import.uploaded', $batch, $company->id, $user->id, null, ['filename' => $batch->original_filename, 'fingerprint' => $fingerprint]);
        try {
            $absolute = Storage::disk(config('imports.disk'))->path($path);
            $sheets = $this->parser->worksheets($absolute, $format);
            $reupload = $company->importBatches()->where('file_fingerprint', $fingerprint)->whereIn('status', ['completed', 'completed_with_errors'])->exists();
            $batch->update(['status' => count($sheets) > 1 ? 'uploaded' : 'mapping', 'options' => ['worksheets' => $sheets, 'same_file_warning' => $reupload, 'default_branch_id' => $branchId]]);
            if (count($sheets) === 1) {
                $this->selectWorksheet($company, $batch, $sheets[0], $user);
            }

            return $batch->fresh();
        } catch (\Throwable $exception) {
            $batch->update(['status' => 'failed', 'failed_rows' => 1]);
            $this->audit->log('import.failed', $batch, $company->id, $user->id, null, ['reason' => $exception->getMessage()]);
            throw $exception;
        }
    }

    public function selectWorksheet(Company $company, ImportBatch $batch, string $worksheet, User $user): ImportBatch
    {
        $this->scope($company, $batch, $user);
        abort_unless(in_array($batch->status, ['uploaded', 'mapping'], true), 422);
        $sheets = $batch->options['worksheets'] ?? [];
        if (! in_array($worksheet, $sheets, true)) {
            throw ValidationException::withMessages(['worksheet' => 'Select a worksheet from the uploaded workbook.']);
        }
        $parsed = $this->parser->parse(Storage::disk(config('imports.disk'))->path($batch->stored_path), $batch->file_format, $worksheet);
        $fields = $this->adapters->get($batch->data_type)->fields();
        DB::transaction(function () use ($batch, $worksheet, $parsed, $fields) {
            $batch->rows()->delete();
            foreach ($parsed['rows'] as $row) {
                $batch->rows()->create(['source_row_number' => $row['number'], 'raw_values' => $row['values'], 'row_fingerprint' => hash('sha256', json_encode($this->normalize($row['values']), JSON_UNESCAPED_UNICODE))]);
            }
            $batch->update(['worksheet' => $worksheet, 'source_headers' => $parsed['headers'], 'mapping' => $this->mapper->suggestions($parsed['headers'], $fields), 'status' => 'mapping', 'total_rows' => count($parsed['rows'])]);
        });

        if ($this->mapper->canAutoMap($parsed['headers'], $fields)) {
            return $this->validate($company, $batch->fresh(), $this->mapper->exactSuggestions($parsed['headers'], $fields), ['posting_mode' => 'draft'], $user);
        }

        return $batch->fresh('rows');
    }

    public function validate(Company $company, ImportBatch $batch, array $mapping, array $options, User $user): ImportBatch
    {
        $this->scope($company, $batch, $user);
        abort_unless(in_array($batch->status, ['mapping', 'validated', 'ready'], true), 422);
        $adapter = $this->adapters->get($batch->data_type);
        $mappedTargets = array_values(array_filter($mapping));
        if (count($mappedTargets) !== count(array_unique($mappedTargets))) {
            throw ValidationException::withMessages(['mapping' => 'Each Arua field may be mapped only once.']);
        }
        foreach ($adapter->fields() as $key => $field) {
            if ($field['required'] && ! in_array($key, $mappedTargets, true)) {
                throw ValidationException::withMessages(['mapping' => $field['label'].' must be mapped.']);
            }
        }
        $counts = ['valid' => 0, 'warning' => 0, 'error' => 0, 'duplicate' => 0];
        DB::transaction(function () use ($company, $batch, $mapping, $options, $user, $adapter, &$counts) {
            $seenFingerprints = [];
            foreach ($batch->rows()->lockForUpdate()->get() as $row) {
                $mapped = [];
                foreach ($mapping as $source => $target) {
                    if ($target) {
                        $mapped[$target] = $row->raw_values[$source] ?? null;
                    }
                }
                foreach ($adapter->fields() as $key => $definition) {
                    $mapped[$key] ??= null;
                }
                $result = $adapter->validate($company, $mapped, $options);
                $prior = ImportRow::query()->where('row_fingerprint', $row->row_fingerprint)->where('validation_status', 'imported')->whereHas('batch', fn ($q) => $q->where('company_id', $company->id)->where('data_type', $batch->data_type))->exists();
                $sameBatch = isset($seenFingerprints[$row->row_fingerprint]);
                $seenFingerprints[$row->row_fingerprint] = true;
                $duplicate = ($prior || $sameBatch) ? 'exact_duplicate' : $result['duplicate'];
                $status = $result['errors'] ? 'error' : (($result['warnings'] || $duplicate !== 'not_duplicate') ? 'warning' : 'valid');
                $counts[$status]++;
                if ($duplicate !== 'not_duplicate') {
                    $counts['duplicate']++;
                }
                $row->update(['mapped_values' => $mapped, 'validation_status' => $status, 'warnings' => $result['warnings'], 'errors' => $result['errors'], 'duplicate_status' => $duplicate]);
            }
            $this->validateGroups($batch);
            $counts = [
                'valid' => $batch->rows()->where('validation_status', 'valid')->count(),
                'warning' => $batch->rows()->where('validation_status', 'warning')->count(),
                'error' => $batch->rows()->where('validation_status', 'error')->count(),
                'duplicate' => $batch->rows()->where('duplicate_status', '!=', 'not_duplicate')->count(),
            ];
            $batch->update(['mapping' => $mapping, 'options' => [...$batch->options, ...$options], 'status' => $counts['error'] ? 'validated' : 'ready', 'valid_rows' => $counts['valid'], 'warning_rows' => $counts['warning'], 'invalid_rows' => $counts['error'], 'duplicate_rows' => $counts['duplicate']]);
            $this->audit->log('import.validated', $batch, $company->id, $user->id, null, $counts);
        });

        return $batch->fresh('rows');
    }

    public function confirm(Company $company, ImportBatch $batch, User $user): ImportBatch
    {
        $this->scope($company, $batch, $user);
        if ($batch->data_type === 'opening_balances') {
            throw ValidationException::withMessages(['batch' => 'Opening balances are validated staging only in v0.8; posting is intentionally unavailable.']);
        }
        $batch = DB::transaction(function () use ($company, $batch, $user) {
            $batch = ImportBatch::where('company_id', $company->id)->lockForUpdate()->findOrFail($batch->id);
            if (in_array($batch->status, ['completed', 'completed_with_errors'], true)) {
                return $batch;
            }
            if ($batch->status !== 'ready') {
                throw ValidationException::withMessages(['batch' => 'Validate the batch and resolve all errors before confirming.']);
            }
            $batch->update(['status' => 'importing', 'confirmed_by' => $user->id, 'confirmed_at' => now()]);

            return $batch;
        });
        if (in_array($batch->status, ['completed', 'completed_with_errors'], true)) {
            return $batch;
        }
        $adapter = $this->adapters->get($batch->data_type);
        $rows = $batch->rows()->whereIn('validation_status', ['valid', 'warning'])->where('duplicate_status', 'not_duplicate')->orderBy('source_row_number')->get();
        $imported = 0;
        $failed = 0;
        $skipped = $batch->rows()->count() - $rows->count();
        $groupField = match ($batch->data_type) {
            'sales_invoices' => 'invoice_ref', 'supplier_bills' => 'bill_ref', 'manual_journals' => 'journal_ref', 'opening_balances' => 'opening_ref', default => null
        };
        $groups = $groupField ? $rows->groupBy(fn ($row) => $row->mapped_values[$groupField]) : $rows->mapWithKeys(fn ($row) => [$row->id => collect([$row])]);
        foreach ($groups as $group) {
            try {
                DB::transaction(function () use ($adapter, $company, $group, $batch, $user, &$imported) {
                    $values = $group->pluck('mapped_values')->all();
                    $model = method_exists($adapter, 'importGroup') ? $adapter->importGroup($company, $values, $batch->options, $user) : $adapter->import($company, $values[0], $batch->options, $user);
                    foreach ($group as $row) {
                        $row->update(['validation_status' => 'imported', 'result_model' => $model::class, 'result_id' => $model->getKey()]);
                        $imported++;
                    }
                });
            } catch (\Throwable $exception) {
                foreach ($group as $row) {
                    $row->update(['validation_status' => 'failed', 'errors' => [$exception->getMessage()]]);
                }
                $failed += $group->count();
            }
        }
        $status = $failed ? ($imported ? 'completed_with_errors' : 'failed') : 'completed';
        $batch->update(['status' => $status, 'imported_rows' => $imported, 'failed_rows' => $failed, 'skipped_rows' => $skipped]);
        $this->audit->log($failed ? 'import.completed_with_errors' : 'import.completed', $batch, $company->id, $user->id, null, compact('imported', 'failed', 'skipped'));

        return $batch->fresh('rows');
    }

    public function cancel(Company $company, ImportBatch $batch, User $user): void
    {
        $this->scope($company, $batch, $user);
        if (! in_array($batch->status, ['uploaded', 'mapping', 'validated', 'ready'], true)) {
            abort(422, 'Only an unconfirmed import can be cancelled.');
        }
        $batch->update(['status' => 'cancelled']);
        $this->audit->log('import.cancelled', $batch, $company->id, $user->id);
    }

    public function saveProfile(Company $company, array $data, User $user): ImportProfile
    {
        $this->authorize($company, $user);
        $profile = $company->importProfiles()->updateOrCreate(['data_type' => $data['data_type'], 'name' => $data['name']], ['source_headers' => $data['source_headers'], 'mapping' => $data['mapping'], 'options' => $data['options'] ?? [], 'created_by' => $user->id, 'updated_by' => $user->id]);
        $this->audit->log('import_profile.saved', $profile, $company->id, $user->id);

        return $profile;
    }

    public function updateProfile(Company $company, ImportProfile $profile, array $data, User $user): ImportProfile
    {
        $this->authorize($company, $user);
        abort_unless($profile->company_id === $company->id, 404);
        $before = $profile->toArray();
        $profile->update([...$data, 'updated_by' => $user->id]);
        $this->audit->log('import_profile.updated', $profile, $company->id, $user->id, $before, $profile->fresh()->toArray());

        return $profile->fresh();
    }

    private function scope(Company $company, ImportBatch $batch, User $user): void
    {
        $this->authorize($company, $user);
        abort_unless($batch->company_id === $company->id && $batch->country_id === $company->country_id, 404);
    }

    private function authorize(Company $company, User $user): void
    {
        abort_unless($user->companies()->whereKey($company->id)->exists(), 404);
    }

    private function normalize(array $values): array
    {
        return array_map(fn ($value) => is_string($value) ? trim(mb_strtolower($value)) : $value, $values);
    }

    private function validateGroups(ImportBatch $batch): void
    {
        $groupField = match ($batch->data_type) {
            'sales_invoices' => 'invoice_ref', 'supplier_bills' => 'bill_ref', 'manual_journals' => 'journal_ref', 'opening_balances' => 'opening_ref', default => null
        };
        if (! $groupField) {
            return;
        }
        foreach ($batch->rows()->get()->groupBy(fn ($row) => $row->mapped_values[$groupField] ?? '') as $rows) {
            $values = $rows->pluck('mapped_values');
            $errors = [];
            if (in_array($batch->data_type, ['manual_journals', 'opening_balances'], true)) {
                $debit = $values->reduce(fn ($sum, $row) => bcadd($sum, (string) ($row['debit'] ?: 0), 4), '0.0000');
                $credit = $values->reduce(fn ($sum, $row) => bcadd($sum, (string) ($row['credit'] ?: 0), 4), '0.0000');
                if (bccomp($debit, $credit, 4) !== 0) {
                    $errors[] = 'Grouped document is unbalanced: total Debit must equal total Credit.';
                }
            } else {
                $keys = $batch->data_type === 'sales_invoices' ? ['customer', 'invoice_date', 'due_date', 'branch'] : ['supplier', 'bill_date', 'due_date', 'branch'];
                foreach ($keys as $key) {
                    if ($values->pluck($key)->unique()->count() > 1) {
                        $errors[] = 'All rows in one source document must use the same '.$key.'.';
                    }
                }
            }
            if ($errors) {
                foreach ($rows as $row) {
                    $row->update(['validation_status' => 'error', 'errors' => array_values(array_unique([...$row->errors, ...$errors]))]);
                }
            }
        }
    }
}

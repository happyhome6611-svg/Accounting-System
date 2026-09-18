<?php

namespace App\Services;

use App\ImportExport\FileParser;
use App\ImportExport\HeaderMapper;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EntityImportBatch;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class EntityImportService
{
    public function __construct(private FileParser $parser, private HeaderMapper $mapper, private CompanyCreator $creator, private CountryJurisdictionService $jurisdictions, private AuditLogger $audit) {}

    public function fields(): array
    {
        return [
            'entity_name' => ['label' => 'Entity Name', 'required' => true, 'aliases' => ['name', 'company_name']],
            'entity_type' => ['label' => 'Entity Type', 'required' => true, 'aliases' => ['type']],
            'legal_name' => ['label' => 'Legal Name', 'required' => false, 'aliases' => []],
            'individual_name' => ['label' => 'Individual / Proprietor Name', 'required' => false, 'aliases' => ['proprietor_name']],
            'trading_name' => ['label' => 'Trading Name', 'required' => false, 'aliases' => []],
            'country' => ['label' => 'Country / Jurisdiction', 'required' => false, 'aliases' => ['country_code', 'jurisdiction']],
            'base_currency' => ['label' => 'Base Currency', 'required' => false, 'aliases' => ['currency', 'currency_code']],
            'timezone' => ['label' => 'Timezone', 'required' => false, 'aliases' => []],
            'financial_year_start' => ['label' => 'Financial Year Start', 'required' => true, 'aliases' => ['fy_start']],
            'financial_year_end' => ['label' => 'Financial Year End', 'required' => true, 'aliases' => ['fy_end']],
            'address' => ['label' => 'Address', 'required' => false, 'aliases' => []],
            'email' => ['label' => 'Email', 'required' => false, 'aliases' => []],
            'phone' => ['label' => 'Phone', 'required' => false, 'aliases' => []],
        ];
    }

    public function upload(Country $country, UploadedFile $file, User $user): EntityImportBatch
    {
        $format = strtolower($file->getClientOriginalExtension());
        $path = $file->storeAs('entity-imports', bin2hex(random_bytes(16)).'.'.$format, 'local');
        $absolute = Storage::disk('local')->path($path);
        $worksheets = $this->parser->worksheets($absolute, $format);
        $batch = EntityImportBatch::create(['country_id' => $country->id, 'uploaded_by' => $user->id, 'original_filename' => $file->getClientOriginalName(), 'stored_path' => $path, 'file_format' => $format, 'file_fingerprint' => hash_file('sha256', $absolute), 'status' => count($worksheets) > 1 ? 'uploaded' : 'mapping']);
        if (count($worksheets) > 1) {
            return tap($batch)->update(['warnings' => ['Select one worksheet.'], 'mapped_values' => ['worksheets' => $worksheets]]);
        }

        return $this->inspect($batch, $worksheets[0] === 'CSV' ? null : $worksheets[0]);
    }

    public function selectWorksheet(EntityImportBatch $batch, string $worksheet): EntityImportBatch
    {
        return $this->inspect($batch, $worksheet);
    }

    public function validate(EntityImportBatch $batch, array $mapping, User $user): EntityImportBatch
    {
        $this->authorize($batch, $user);
        $targets = array_filter($mapping);
        if (count($targets) !== count(array_unique($targets))) {
            throw ValidationException::withMessages(['mapping' => 'Each Arua field may only be mapped once.']);
        }
        $values = [];
        foreach ($mapping as $source => $target) {
            if ($target !== '' && isset($this->fields()[$target])) {
                $values[$target] = $batch->raw_values[$source] ?? null;
            }
        }
        $errors = [];
        foreach ($this->fields() as $key => $field) {
            if ($field['required'] && blank($values[$key] ?? null)) {
                $errors[] = $field['label'].' is required.';
            }
        }
        $type = (string) str($values['entity_type'] ?? '')->lower()->replace([' ', '-'], '_');
        if (! in_array($type, ['company', 'sole_trader', 'individual'], true)) {
            $errors[] = 'Entity Type must be Company, Sole Trader, or Individual.';
        }
        $values['entity_type'] = $type;
        $countryValue = trim((string) ($values['country'] ?? ''));
        if ($countryValue !== '' && ! in_array(mb_strtolower($countryValue), [mb_strtolower($batch->country->code), mb_strtolower($batch->country->name)], true)) {
            $errors[] = 'Country / Jurisdiction must match '.$batch->country->name.'.';
        }
        $currencyCode = strtoupper(trim((string) (filled($values['base_currency'] ?? null) ? $values['base_currency'] : $this->jurisdictions->defaultCurrencyCode($batch->country))));
        $currency = Currency::where('code', $currencyCode)->where('is_active', true)->first();
        if (! $currency) {
            $errors[] = 'Base Currency is invalid.';
        }
        $values['base_currency'] = $currencyCode;
        $values['base_currency_id'] = $currency?->id;
        $values['timezone'] = filled($values['timezone'] ?? null) ? $values['timezone'] : $this->jurisdictions->defaultTimezone($batch->country);
        if (! in_array($values['timezone'], timezone_identifiers_list(), true)) {
            $errors[] = 'Timezone is invalid.';
        }
        if (! strtotime((string) ($values['financial_year_start'] ?? '')) || ! strtotime((string) ($values['financial_year_end'] ?? '')) || ($values['financial_year_end'] ?? '') <= ($values['financial_year_start'] ?? '')) {
            $errors[] = 'Financial Year End must be after Financial Year Start.';
        }
        if (($values['email'] ?? '') && ! filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is invalid.';
        }
        $name = trim((string) ($values['entity_name'] ?? ''));
        $exact = $user->companies()->where('country_id', $batch->country_id)->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
        $possible = ! $exact && filled($values['legal_name'] ?? null) && $user->companies()->where('country_id', $batch->country_id)->whereRaw('LOWER(legal_name) = ?', [mb_strtolower($values['legal_name'])])->exists();
        $duplicate = $exact ? 'exact_duplicate' : ($possible ? 'possible_duplicate' : 'not_duplicate');
        if ($exact) {
            $errors[] = 'An Accounting Entity with this name already exists in the selected jurisdiction.';
        }
        $warnings = $possible ? ['A possible duplicate with the same Legal Name exists. Review before confirming.'] : [];
        foreach (array_keys($this->fields()) as $field) {
            $values[$field] = $values[$field] ?? null;
        }
        $batch->update(['mapping' => $mapping, 'mapped_values' => $values, 'errors' => $errors, 'warnings' => $warnings, 'duplicate_status' => $duplicate, 'status' => $errors ? 'mapping' : 'ready']);

        return $batch->fresh(['country']);
    }

    public function confirm(EntityImportBatch $batch, User $user): Company
    {
        $this->authorize($batch, $user);

        return DB::transaction(function () use ($batch, $user) {
            $batch = EntityImportBatch::lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'completed' && $batch->result_company_id) {
                return Company::findOrFail($batch->result_company_id);
            }
            if ($batch->status !== 'ready' || $batch->errors || $batch->duplicate_status === 'exact_duplicate') {
                throw ValidationException::withMessages(['batch' => 'This entity import is not ready for confirmation.']);
            }
            $values = $batch->mapped_values;
            if ($user->companies()->where('country_id', $batch->country_id)->whereRaw('LOWER(name) = ?', [mb_strtolower($values['entity_name'])])->exists()) {
                throw ValidationException::withMessages(['batch' => 'An Accounting Entity with this name already exists in the selected jurisdiction.']);
            }
            $type = $values['entity_type'];
            $data = ['entity_type' => $type, 'name' => $values['entity_name'], 'legal_name' => $values['legal_name'] ?: $values['entity_name'], 'individual_name' => in_array($type, ['individual', 'sole_trader'], true) ? ($values['individual_name'] ?: $values['entity_name']) : null, 'trading_name' => $type === 'sole_trader' ? ($values['trading_name'] ?: $values['entity_name']) : null, 'country_id' => $batch->country_id, 'base_currency_id' => $values['base_currency_id'], 'timezone' => $values['timezone'], 'financial_year_start' => $values['financial_year_start'], 'financial_year_end' => $values['financial_year_end'], 'address' => $values['address'] ?? null, 'email' => $values['email'] ?? null, 'phone' => $values['phone'] ?? null];
            $company = $this->creator->create($data, $user);
            $batch->update(['status' => 'completed', 'result_company_id' => $company->id, 'confirmed_at' => now()]);
            $this->audit->log('accounting_entity.imported', $company, $company->id, $user->id, null, ['entity_import_batch_id' => $batch->id]);

            return $company;
        });
    }

    public function authorize(EntityImportBatch $batch, User $user): void
    {
        abort_unless($batch->uploaded_by === $user->id, 404);
    }

    private function inspect(EntityImportBatch $batch, ?string $worksheet): EntityImportBatch
    {
        $parsed = $this->parser->parse(Storage::disk('local')->path($batch->stored_path), $batch->file_format, $worksheet);
        if (count($parsed['rows']) !== 1) {
            throw ValidationException::withMessages(['file' => 'v0.8 Accounting Entity import accepts exactly one entity per file.']);
        }
        $batch->update(['worksheet' => $worksheet, 'status' => 'mapping', 'source_headers' => $parsed['headers'], 'raw_values' => $parsed['rows'][0]['values'], 'mapping' => $this->mapper->suggestions($parsed['headers'], $this->fields()), 'warnings' => [], 'errors' => []]);

        return $batch->fresh(['country']);
    }
}

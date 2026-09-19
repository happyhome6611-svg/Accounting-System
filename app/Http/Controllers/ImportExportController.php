<?php

namespace App\Http\Controllers;

use App\ImportExport\ExportService;
use App\ImportExport\HeaderMapper;
use App\ImportExport\ImportAdapterRegistry;
use App\ImportExport\ImportService;
use App\Models\Company;
use App\Models\EntityImportBatch;
use App\Models\ImportBatch;
use App\Models\ImportProfile;
use App\Services\CountryJurisdictionService;
use App\Services\EntityImportService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ImportExportController extends Controller
{
    public function index(Request $request, CountryJurisdictionService $jurisdictions)
    {
        return view('import-export.index', [
            'countries' => $jurisdictions->countriesFor($request->user()),
        ]);
    }

    public function country(Request $request, string $country, CountryJurisdictionService $jurisdictions)
    {
        $country = $jurisdictions->country($country);

        return view('import-export.country', ['country' => $country, 'companies' => $jurisdictions->entities($request->user(), $country)]);
    }

    public function entityImportCreate(Request $request, string $country, CountryJurisdictionService $jurisdictions)
    {
        $country = $jurisdictions->country($country);

        return view('import-export.entity-import-create', compact('country'));
    }

    public function entityImportUpload(Request $request, string $country, CountryJurisdictionService $jurisdictions, EntityImportService $service)
    {
        $country = $jurisdictions->country($country);
        $data = $request->validate(['file' => ['required', 'file', 'max:'.config('imports.max_file_kb'), 'extensions:csv,xlsx']]);
        $batch = $service->upload($country, $data['file'], $request->user());

        return redirect()->route('import-export.entity-imports.show', [$country->code, $batch]);
    }

    public function entityImportShow(Request $request, string $country, EntityImportBatch $batch, CountryJurisdictionService $jurisdictions, EntityImportService $service)
    {
        $country = $jurisdictions->country($country);
        $service->authorize($batch, $request->user());
        abort_unless($batch->country_id === $country->id, 404);

        return view('import-export.entity-import-batch', ['country' => $country, 'batch' => $batch->load('resultCompany'), 'fields' => $service->fields()]);
    }

    public function entityImportWorksheet(Request $request, string $country, EntityImportBatch $batch, CountryJurisdictionService $jurisdictions, EntityImportService $service)
    {
        $country = $jurisdictions->country($country);
        $service->authorize($batch, $request->user());
        abort_unless($batch->country_id === $country->id, 404);
        $service->selectWorksheet($batch, $request->validate(['worksheet' => 'required|string'])['worksheet'], $request->user());

        return back()->with('success', 'Worksheet selected. Map the entity fields.');
    }

    public function entityImportValidate(Request $request, string $country, EntityImportBatch $batch, CountryJurisdictionService $jurisdictions, EntityImportService $service)
    {
        $country = $jurisdictions->country($country);
        $service->authorize($batch, $request->user());
        abort_unless($batch->country_id === $country->id, 404);
        $service->validate($batch, $request->validate(['mapping' => 'required|array', 'mapping.*' => 'nullable|string|max:80'])['mapping'], $request->user());

        return back()->with('success', 'Validation completed. Review the preview before confirming.');
    }

    public function entityImportConfirm(Request $request, string $country, EntityImportBatch $batch, CountryJurisdictionService $jurisdictions, EntityImportService $service)
    {
        $country = $jurisdictions->country($country);
        abort_unless($batch->country_id === $country->id, 404);
        $service->confirm($batch, $request->user());

        return back()->with('success', 'Accounting Entity successfully imported.');
    }

    public function workspace(Request $request, string $country, Company $company, ImportAdapterRegistry $imports, ExportService $exports)
    {
        $company = $this->context($request, $country, $company);

        return view('import-export.workspace', ['company' => $company, 'importTypes' => $imports->types(), 'exportTypes' => $exports->types(), 'batches' => $company->importBatches()->with('uploader')->latest()->limit(25)->get(), 'profiles' => $company->importProfiles()->latest()->get(), 'exports' => $company->exportLogs()->latest('generated_at')->limit(25)->get()]);
    }

    public function create(Request $request, string $country, Company $company, ImportAdapterRegistry $registry)
    {
        $company = $this->context($request, $country, $company);

        return view('import-export.import-create', ['company' => $company, 'types' => $registry->types(), 'branches' => $company->supportsBranches() ? $company->branches()->where('is_active', true)->get() : collect()]);
    }

    public function upload(Request $request, string $country, Company $company, ImportService $service)
    {
        $company = $this->context($request, $country, $company);
        $data = $request->validate(['data_type' => 'required|string|max:40', 'branch_id' => 'nullable|integer', 'file' => ['required', 'file', 'max:'.config('imports.max_file_kb'), 'mimes:csv,xlsx']]);
        $batch = $service->upload($company, $data['data_type'], $data['file'], isset($data['branch_id']) ? (int) $data['branch_id'] : null, $request->user());

        return redirect()->route('import-export.batches.show', [$company->country->code, $company, $batch]);
    }

    public function show(Request $request, string $country, Company $company, ImportBatch $batch, ImportAdapterRegistry $registry, HeaderMapper $mapper)
    {
        $company = $this->context($request, $country, $company);
        $this->batch($company, $batch);
        $adapter = $registry->get($batch->data_type);
        $profiles = $company->importProfiles()->where('data_type', $batch->data_type)->get()->filter(fn ($profile) => $mapper->compatible($profile->source_headers, $batch->source_headers));

        return view('import-export.batch', ['company' => $company, 'batch' => $batch->load(['rows', 'uploader']), 'fields' => $adapter->fields(), 'profiles' => $profiles]);
    }

    public function worksheet(Request $request, string $country, Company $company, ImportBatch $batch, ImportService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->selectWorksheet($company, $batch, $request->validate(['worksheet' => 'required|string'])['worksheet'], $request->user());

        return back()->with('success', 'Worksheet selected and columns inspected.');
    }

    public function validateBatch(Request $request, string $country, Company $company, ImportBatch $batch, ImportService $service)
    {
        $company = $this->context($request, $country, $company);
        $data = $request->validate(['mapping' => 'required|array', 'mapping.*' => 'nullable|string|max:80', 'posting_mode' => ['nullable', Rule::in(['draft', 'post'])]]);
        $service->validate($company, $batch, $data['mapping'], ['posting_mode' => $data['posting_mode'] ?? 'draft'], $request->user());

        return back()->with('success', 'Validation completed. Review the preview before confirming.');
    }

    public function confirm(Request $request, string $country, Company $company, ImportBatch $batch, ImportService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->confirm($company, $batch, $request->user());

        return back()->with('success', 'Import processing completed.');
    }

    public function cancel(Request $request, string $country, Company $company, ImportBatch $batch, ImportService $service)
    {
        $company = $this->context($request, $country, $company);
        $service->cancel($company, $batch, $request->user());

        return back()->with('success', 'Import cancelled without creating production records.');
    }

    public function errors(Request $request, string $country, Company $company, ImportBatch $batch)
    {
        $company = $this->context($request, $country, $company);
        $this->batch($company, $batch);

        return response()->streamDownload(function () use ($batch) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Source Row', 'Validation Errors']);
            foreach ($batch->rows()->whereIn('validation_status', ['error', 'failed'])->get() as $row) {
                fputcsv($out, [$row->source_row_number, implode('; ', $row->errors)]);
            } fclose($out);
        }, 'arua-import-errors-'.$batch->id.'.csv', ['Content-Type' => 'text/csv']);
    }

    public function profileCreate(Request $request, string $country, Company $company, ImportAdapterRegistry $registry)
    {
        $company = $this->context($request, $country, $company);

        return view('import-export.profile', ['company' => $company, 'profile' => null, 'types' => $registry->types()]);
    }

    public function profileEdit(Request $request, string $country, Company $company, ImportProfile $profile, ImportAdapterRegistry $registry)
    {
        $company = $this->context($request, $country, $company);
        abort_unless($profile->company_id === $company->id, 404);

        return view('import-export.profile', ['company' => $company, 'profile' => $profile, 'types' => $registry->types()]);
    }

    public function profileStore(Request $request, string $country, Company $company, ImportService $service)
    {
        $company = $this->context($request, $country, $company);
        $data = $this->profileData($request);
        $service->saveProfile($company, $data, $request->user());

        return redirect()->route('import-export.workspace', [$company->country->code, $company])->with('success', 'Import Profile saved.');
    }

    public function profileUpdate(Request $request, string $country, Company $company, ImportProfile $profile, ImportService $service)
    {
        $company = $this->context($request, $country, $company);
        abort_unless($profile->company_id === $company->id, 404);
        $data = $this->profileData($request);
        $service->updateProfile($company, $profile, $data, $request->user());

        return redirect()->route('import-export.workspace', [$company->country->code, $company])->with('success', 'Import Profile updated.');
    }

    public function exportCreate(Request $request, string $country, Company $company, ExportService $service)
    {
        $company = $this->context($request, $country, $company);

        return view('import-export.export', ['company' => $company, 'types' => $service->types(), 'branches' => $company->supportsBranches() ? $company->branches()->where('is_active', true)->get() : collect(), 'years' => $company->financialYears()->get(), 'accounts' => $company->accounts()->orderBy('code')->get(), 'bankAccounts' => $company->bankAccounts()->get()]);
    }

    public function export(Request $request, string $country, Company $company, ExportService $service)
    {
        $company = $this->context($request, $country, $company);
        $data = $request->validate(['data_type' => 'required|string|max:40', 'format' => ['required', Rule::in(['csv', 'xlsx'])], 'branch_id' => 'nullable|integer', 'financial_year_id' => 'nullable|integer', 'account_id' => 'nullable|integer', 'bank_account_id' => 'nullable|integer', 'customer_id' => 'nullable|integer', 'supplier_id' => 'nullable|integer', 'tax_registration_id' => 'nullable|integer', 'tax_period_id' => 'nullable|integer', 'tax_code_id' => 'nullable|integer', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $file = $service->generate($company, $data['data_type'], $data['format'], collect($data)->except(['data_type', 'format'])->all(), $request->user());

        return response()->download($file['path'], $file['filename'])->deleteFileAfterSend(true);
    }

    private function context(Request $request, string $country, Company $company): Company
    {
        $jurisdictions = app(CountryJurisdictionService::class);

        return $jurisdictions->entity($request->user(), $jurisdictions->country($country), $company->id)->load(['country', 'baseCurrency']);
    }

    private function batch(Company $company, ImportBatch $batch): void
    {
        abort_unless($batch->company_id === $company->id && $batch->country_id === $company->country_id, 404);
    }

    private function profileData(Request $request): array
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'data_type' => 'required|string|max:40', 'source_headers' => 'nullable|array', 'mapping' => 'nullable|array', 'source_headers_json' => 'nullable|string', 'mapping_json' => 'nullable|string', 'options' => 'nullable|array']);
        try {
            $headers = $data['source_headers'] ?? json_decode($data['source_headers_json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
            $mapping = $data['mapping'] ?? json_decode($data['mapping_json'] ?? '', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['mapping_json' => 'Headers and mapping must be valid JSON.']);
        }
        if (! is_array($headers) || ! is_array($mapping) || ! $headers || ! $mapping) {
            throw ValidationException::withMessages(['mapping_json' => 'Headers and mapping are required.']);
        }

        return ['name' => $data['name'], 'data_type' => $data['data_type'], 'source_headers' => array_values($headers), 'mapping' => $mapping, 'options' => $data['options'] ?? []];
    }
}

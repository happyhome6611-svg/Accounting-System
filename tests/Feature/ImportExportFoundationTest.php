<?php

namespace Tests\Feature;

use App\ImportExport\ExportService;
use App\ImportExport\FileParser;
use App\ImportExport\HeaderMapper;
use App\ImportExport\ImportAdapterRegistry;
use App\ImportExport\ImportService;
use App\Models\Company;
use App\Models\Country;
use App\Models\Currency;
use App\Models\EntityImportBatch;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\BranchService;
use App\Services\CompanyCreator;
use App\Services\EntityImportService;
use App\Services\JournalService;
use App\Services\SalesService;
use App\Services\SupplierMaintenanceService;
use App\Services\TaxConfigurationService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportExportFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->user = User::factory()->create();
        $this->company = $this->entity('company', 'Import Company');
    }

    public function test_csv_customer_import_preview_confirmation_duplicate_and_profile_are_safe(): void
    {
        $csv = $this->fixture('customers-valid.csv');
        $mapping = ['Customer Code' => 'code', 'Customer Name' => 'name', 'Legal Name' => 'legal_name', 'Email' => 'email', 'Phone' => 'phone', 'Billing Address' => 'billing_address', 'Payment Terms Days' => 'payment_terms_days', 'Credit Limit' => 'credit_limit', 'Active' => 'active'];
        $batch = $this->batch('customers', $csv, $mapping);
        $this->assertSame('ready', $batch->status);
        $this->assertSame(3, $batch->valid_rows);
        $completed = app(ImportService::class)->confirm($this->company, $batch, $this->user);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(3, $completed->imported_rows);
        $this->assertDatabaseHas('customers', ['company_id' => $this->company->id, 'code' => 'CUST001', 'name' => 'Alpha Services Ltd']);
        app(ImportService::class)->confirm($this->company, $completed, $this->user);
        $this->assertSame(3, $this->company->customers()->whereIn('code', ['CUST001', 'CUST002', 'CUST003'])->count());

        $repeat = $this->batch('customers', $csv, $mapping);
        $this->assertTrue($repeat->options['same_file_warning']);
        $this->assertSame(3, $repeat->duplicate_rows);
        $this->assertSame('completed', app(ImportService::class)->confirm($this->company, $repeat, $this->user)->status);
        $this->assertSame(3, $this->company->customers()->whereIn('code', ['CUST001', 'CUST002', 'CUST003'])->count());

        $profile = app(ImportService::class)->saveProfile($this->company, ['data_type' => 'customers', 'name' => 'Customer CSV', 'source_headers' => array_keys($mapping), 'mapping' => $mapping], $this->user);
        $this->assertSame($this->company->id, $profile->company_id);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'import.completed']);
        $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'import_profile.saved']);
    }

    public function test_xlsx_parser_requires_explicit_sheet_and_rejects_malformed_files(): void
    {
        $path = $this->fixturePath('xlsx-multi-sheet.xlsx');
        $parser = app(FileParser::class);
        $this->assertSame(['Customers', 'Suppliers', 'Products'], $parser->worksheets($path, 'xlsx'));
        $parsed = $parser->parse($path, 'xlsx', 'Suppliers');
        $this->assertSame(['Supplier Code', 'Supplier Name', 'Legal Name', 'Email', 'Phone', 'Address', 'Payment Terms Days', 'Credit Limit', 'Active'], $parsed['headers']);
        $this->assertSame('SUP001', $parsed['rows'][0]['values']['Supplier Code']);
        $this->assertCount(3, $parsed['rows']);

        $uploaded = new UploadedFile($path, 'multi.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $batch = app(ImportService::class)->upload($this->company, 'customers', $uploaded, $this->company->branches()->value('id'), $this->user);
        $this->assertSame('uploaded', $batch->status);
        $this->assertNull($batch->worksheet);
        app(ImportService::class)->selectWorksheet($this->company, $batch, 'Customers', $this->user);
        $this->assertSame('Customers', $batch->fresh()->worksheet);

        $bad = storage_path('framework/testing/bad.xlsx');
        file_put_contents($bad, 'not a workbook');
        $this->expectException(ValidationException::class);
        $parser->parse($bad, 'xlsx');
    }

    public function test_synthetic_edge_fixtures_match_parser_mapping_and_untrusted_text_rules(): void
    {
        $parser = app(FileParser::class);
        $blankRows = $parser->parse($this->fixturePath('blank-rows.csv'), 'csv');
        $this->assertCount(2, $blankRows['rows']);
        $this->assertSame([2, 4], array_column($blankRows['rows'], 'number'));

        $formulaText = $parser->parse($this->fixturePath('formula-injection-text.csv'), 'csv');
        $this->assertSame('=SUM(1,1)', $formulaText['rows'][0]['values']['Customer Name']);
        $this->assertSame('+TEST', $formulaText['rows'][0]['values']['Legal Name']);
        $this->assertSame('@VALUE', $formulaText['rows'][0]['values']['Billing Address']);

        $alternate = $parser->parse($this->fixturePath('customers-alternate-headers.csv'), 'csv');
        $fields = app(ImportAdapterRegistry::class)->get('customers')->fields();
        $suggestions = app(HeaderMapper::class)->suggestions($alternate['headers'], $fields);
        $this->assertSame(['CustCode' => 'code', 'CustomerName' => 'name', 'Telephone' => 'phone', 'EmailAddress' => 'email'], $suggestions);

        foreach (['empty.csv', 'headers-only.csv', 'duplicate-headers.csv'] as $fixture) {
            $this->assertThrows(fn () => $parser->parse($this->fixturePath($fixture), 'csv'), ValidationException::class);
        }

        $repeated = $this->batch('customers', $this->fixture('repeated-customers.csv'), ['Customer Code' => 'code', 'Customer Name' => 'name']);
        $this->assertSame(1, $repeated->duplicate_rows);
        $this->assertSame(1, $repeated->valid_rows);
    }

    public function test_master_adapters_and_csv_xlsx_exports_are_entity_scoped_and_formula_safe(): void
    {
        $this->batch('chart_of_accounts', "Account Code,Account Name,Account Type\n6100,Imported Expense,expense\n", ['Account Code' => 'code', 'Account Name' => 'name', 'Account Type' => 'type'], true);
        $this->batch('suppliers', "Supplier Code,Supplier Name,Email\nSUP-X,Imported Supplier,s@example.com\n", ['Supplier Code' => 'code', 'Supplier Name' => 'name', 'Email' => 'email'], true);
        $this->batch('products', "Code,Name,Type,Sales Price,Description\nITEM-X,Imported Service,service,100,=unsafe\n", ['Code' => 'code', 'Name' => 'name', 'Type' => 'type', 'Sales Price' => 'sales_price', 'Description' => 'description'], true);
        $this->assertDatabaseHas('accounts', ['company_id' => $this->company->id, 'code' => '6100']);
        $this->assertDatabaseHas('suppliers', ['company_id' => $this->company->id, 'code' => 'SUP-X']);
        $this->assertDatabaseHas('items', ['company_id' => $this->company->id, 'code' => 'ITEM-X']);

        $exports = app(ExportService::class);
        $csv = $exports->generate($this->company, 'products', 'csv', [], $this->user);
        $content = file_get_contents($csv['path']);
        $this->assertStringContainsString("'=unsafe", $content);
        $this->assertStringContainsString('100', $content);
        $xlsx = $exports->generate($this->company, 'customers', 'xlsx', [], $this->user);
        $this->assertFileExists($xlsx['path']);
        $this->assertGreaterThan(0, filesize($xlsx['path']));
        $this->assertDatabaseCount('export_logs', 2);

        $foreign = $this->entity('company', 'Foreign Entity');
        $this->assertThrows(fn () => $exports->generate($this->company, 'general_ledger', 'csv', ['account_id' => $foreign->accounts()->value('id')], $this->user));
        @unlink($csv['path']);
        @unlink($xlsx['path']);
    }

    public function test_grouped_sales_purchase_and_journal_imports_are_atomic_and_use_existing_services(): void
    {
        $sales = app(SalesService::class);
        $sales->createCustomer($this->company, ['code' => 'CUST001', 'name' => 'Alpha', 'type' => 'business', 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 30, 'credit_limit' => 1000, 'receivable_account_id' => $this->account('1100')], $this->user);
        app(SupplierMaintenanceService::class)->create($this->company, ['code' => 'SUP001', 'name' => 'Supplier', 'type' => 'business', 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 30, 'credit_limit' => 1000, 'payable_account_id' => $this->account('2000'), 'is_active' => true], $this->user);
        $branch = $this->company->branches()->value('id');

        $invoiceCsv = "Invoice,Customer,Invoice Date,Due Date,Revenue Account,Description,Quantity,Unit Price\nINV001,CUST001,2027-01-10,2027-02-10,4000,Line one,1,100\nINV001,CUST001,2027-01-10,2027-02-10,4000,Line two,1,50\n";
        $invoice = $this->batch('sales_invoices', $invoiceCsv, ['Invoice' => 'invoice_ref', 'Customer' => 'customer', 'Invoice Date' => 'invoice_date', 'Due Date' => 'due_date', 'Revenue Account' => 'revenue_account', 'Description' => 'description', 'Quantity' => 'quantity', 'Unit Price' => 'unit_price'], true, $branch);
        $this->assertSame(1, $this->company->salesInvoices()->where('customer_reference', 'INV001')->count());
        $this->assertSame(2, $this->company->salesInvoices()->where('customer_reference', 'INV001')->first()->lines()->count());
        $this->assertSame('150.0000', $this->company->salesInvoices()->where('customer_reference', 'INV001')->value('total'));

        $billCsv = "Bill,Supplier,Bill Date,Due Date,Expense Account,Description,Quantity,Unit Price\nBILL001,SUP001,2027-01-10,2027-02-10,5000,Expense one,1,200\nBILL001,SUP001,2027-01-10,2027-02-10,5000,Expense two,1,50\n";
        $this->batch('supplier_bills', $billCsv, ['Bill' => 'bill_ref', 'Supplier' => 'supplier', 'Bill Date' => 'bill_date', 'Due Date' => 'due_date', 'Expense Account' => 'expense_account', 'Description' => 'description', 'Quantity' => 'quantity', 'Unit Price' => 'unit_price'], true, $branch);
        $this->assertSame(1, $this->company->supplierBills()->where('supplier_reference', 'BILL001')->count());
        $this->assertSame('250.0000', $this->company->supplierBills()->where('supplier_reference', 'BILL001')->value('total'));

        $badJournal = "Reference,Date,Account,Debit,Credit,Description\nJRN001,2027-01-10,1000,1000,0,Opening\nJRN001,2027-01-10,3000,0,900,Opening\n";
        $batch = $this->batch('manual_journals', $badJournal, ['Reference' => 'journal_ref', 'Date' => 'date', 'Account' => 'account', 'Debit' => 'debit', 'Credit' => 'credit', 'Description' => 'description']);
        $this->assertSame('validated', $batch->status);
        $this->assertSame(2, $batch->invalid_rows);
        $this->assertSame(0, $this->company->journals()->where('reference', 'JRN001')->count());
    }

    public function test_company_sole_trader_individual_routes_profiles_and_tampering_are_scoped(): void
    {
        $sole = $this->entity('sole_trader', 'Sole Trader');
        $individual = $this->entity('individual', 'Individual');
        $this->actingAs($this->user)->get(route('import-export'))->assertOk()->assertSee('Import &amp; Export', false)->assertDontSee('@yield');
        $this->get(route('import-export.country', 'NZ'))->assertOk()->assertSee($this->company->name)->assertSee($sole->name)->assertSee($individual->name)->assertSee('Manage Import & Export', false);
        foreach ([$this->company, $sole, $individual] as $entity) {
            $this->get(route('import-export.workspace', ['NZ', $entity]))->assertOk()->assertSee($entity->entity_label.' – Import & Export', false)->assertSee('Start New Import')->assertSee('View Import History')->assertSee('View Import Profiles')->assertSee('New Export')->assertSee('Bank Statement Evidence')->assertSee('Customer Receipts and Supplier Payments are not direct import types.');
            $importPage = $this->get(route('import-export.imports.create', ['NZ', $entity]))->assertOk()->assertSee('New Import')->assertSee('Upload / Continue')->assertSee('CSV / XLSX');
            $this->get(route('import-export.exports.create', ['NZ', $entity]))->assertOk()->assertSee('New Export');
            if ($entity->entity_type === 'individual') {
                $importPage->assertSee('Not applicable for Individual')->assertDontSee('name="branch_id"', false);
            } else {
                $importPage->assertSee('name="branch_id"', false);
            }
        }
        $this->get(route('import-export.workspace', ['IN', $this->company]))->assertNotFound();
        $batch = $this->batch('customers', "Code,Name\nI1,Isolated\n", ['Code' => 'code', 'Name' => 'name']);
        $this->get(route('import-export.batches.show', ['NZ', $individual, $batch]))->assertNotFound();
        $this->assertThrows(fn () => app(ImportService::class)->upload($individual, 'customers', UploadedFile::fake()->createWithContent('branch.csv', "Code,Name\nI1,Person\n"), $this->company->branches()->value('id'), $this->user));
    }

    public function test_authorized_user_can_navigate_complete_browser_import_workflow(): void
    {
        $this->actingAs($this->user)
            ->get(route('import-export.country', 'NZ'))
            ->assertSee(route('import-export.workspace', ['NZ', $this->company]), false);

        $this->get(route('import-export.workspace', ['NZ', $this->company]))
            ->assertOk()
            ->assertSee(route('import-export.imports.create', ['NZ', $this->company]), false)
            ->assertSee(route('import-export.profiles.create', ['NZ', $this->company]), false)
            ->assertSee(route('import-export.exports.create', ['NZ', $this->company]), false);

        $this->get(route('import-export.imports.create', ['NZ', $this->company], false))
            ->assertOk()
            ->assertSee('Chart of Accounts')
            ->assertSee('Opening Balances (staging only)')
            ->assertDontSee('Customer Receipts</option>', false)
            ->assertDontSee('Supplier Payments</option>', false);

        $upload = $this->post(route('import-export.imports.upload', ['NZ', $this->company]), [
            'data_type' => 'customers',
            'file' => UploadedFile::fake()->createWithContent('browser-customers.csv', "Customer Code,Customer Name\nWEB001,Browser Customer\n"),
        ]);
        $batch = $this->company->importBatches()->latest('id')->firstOrFail();
        $upload->assertRedirect(route('import-export.batches.show', ['NZ', $this->company, $batch]));

        $this->get(route('import-export.batches.show', ['NZ', $this->company, $batch]))
            ->assertOk()
            ->assertDontSee('Column Mapping and Options')
            ->assertSee('WEB001')
            ->assertSee('Browser Customer')
            ->assertSee('Change Mapping');

        $this->get(route('import-export.batches.show', ['NZ', $this->company, $batch->fresh()]))
            ->assertOk()
            ->assertSee('Preview filter:')
            ->assertSee('Valid')
            ->assertSee('Warnings')
            ->assertSee('Errors')
            ->assertSee('Duplicates')
            ->assertSee('data-duplicate="0"', false)
            ->assertSee('Confirm Import');

        $this->post(route('import-export.batches.confirm', ['NZ', $this->company, $batch]))->assertRedirect();
        $this->get(route('import-export.batches.show', ['NZ', $this->company, $batch->fresh()]))
            ->assertOk()
            ->assertSee('Import Result')
            ->assertSee('Import Another File')
            ->assertSee('Return to Import & Export', false);
        $this->assertDatabaseHas('customers', ['company_id' => $this->company->id, 'code' => 'WEB001']);
    }

    public function test_new_accounting_entity_import_workflow_uses_normal_creation_rules_and_is_idempotent(): void
    {
        $service = app(EntityImportService::class);
        $country = Country::where('code', 'NZ')->firstOrFail();
        $this->actingAs($this->user)->get(route('import-export'))->assertSee('Import New Accounting Entity')->assertSee('Import / Export Existing Entity Data')->assertDontSee('Choose Jurisdiction')->assertDontSee('Choose Existing Entity');
        $this->get(route('import-export.country', 'NZ'))->assertSee('+ Import New Accounting Entity')->assertSee('Existing Accounting Entities');

        foreach ([['Company', 'Arua Demo Trading Ltd', 1], ['Sole Trader', 'Imported Trader', 1], ['Individual', 'Imported Person', 0]] as [$type, $name, $branches]) {
            $csv = "Entity Name,Entity Type,Country,Base Currency,Timezone,Financial Year Start,Financial Year End\n{$name},{$type},NZ,NZD,Pacific/Auckland,2025-04-01,2026-03-31\n";
            $batch = $service->upload($country, UploadedFile::fake()->createWithContent(str($name)->slug().'.csv', $csv), $this->user);
            $this->assertSame('ready', $batch->status);
            $this->assertSame('not_duplicate', $batch->duplicate_status);
            $entity = $service->confirm($batch, $this->user);
            $this->assertSame($entity->id, $service->confirm($batch->fresh(), $this->user)->id);
            $this->assertSame($branches, $entity->branches()->count());
            $this->assertTrue($this->user->companies()->whereKey($entity->id)->exists());
            $this->get(route('import-export.workspace', ['NZ', $entity]))->assertOk()->assertSee($name);
            $this->get(route('import-export.entity-imports.show', ['NZ', $batch->fresh()]))->assertOk()->assertSee('Accounting Entity successfully imported.')->assertSee('Import Data Into This Entity')->assertSee(route('import-export.workspace', ['NZ', $entity]), false);
        }

        $this->assertDatabaseHas('audit_logs', ['event' => 'accounting_entity.imported']);
    }

    public function test_import_export_navigation_and_exact_generated_entity_csv_complete_the_real_http_workflow(): void
    {
        $this->actingAs($this->user)->get(route('import-export'))
            ->assertOk()
            ->assertDontSee('Choose Jurisdiction')
            ->assertDontSee('Choose Existing Entity')
            ->assertSee('href="'.route('import-export.country', 'NZ').'"', false);

        $this->get(route('import-export.entity-imports.create', 'NZ'))
            ->assertOk()
            ->assertSee('Selected jurisdiction:')
            ->assertSee('Upload / Continue');

        $this->get(route('import-export.country', 'NZ'))
            ->assertOk()
            ->assertSee('href="'.route('import-export.workspace', ['NZ', $this->company]).'"', false);
        $this->get(route('import-export.workspace', ['NZ', $this->company]))
            ->assertOk()
            ->assertSee('href="'.route('import-export.imports.create', ['NZ', $this->company]).'"', false)
            ->assertSee('href="'.route('import-export.exports.create', ['NZ', $this->company]).'"', false);
        $this->get(route('import-export.imports.create', ['NZ', $this->company]))
            ->assertOk()
            ->assertSee('Upload / Continue');
        $this->get(route('import-export.exports.create', ['NZ', $this->company]))
            ->assertOk()
            ->assertSee('Generate Download');

        $samplePath = base_path('tests/SampleBusinessData/AruaDemoTrading/00_accounting_entity_import.csv');
        $parsed = app(FileParser::class)->parse($samplePath, 'csv');
        $this->assertCount(1, $parsed['rows']);
        $this->assertSame('Arua Demo Trading Ltd', $parsed['rows'][0]['values']['Entity Name']);
        $this->assertSame('Company', $parsed['rows'][0]['values']['Entity Type']);
        $this->assertSame('NZ', $parsed['rows'][0]['values']['Country / Jurisdiction']);
        $this->assertSame('NZD', $parsed['rows'][0]['values']['Base Currency']);

        $upload = $this->post(route('import-export.entity-imports.upload', 'NZ'), [
            'file' => new UploadedFile($samplePath, '00_accounting_entity_import.csv', 'text/csv', null, true),
        ]);
        $upload->assertSessionHasNoErrors();
        $batch = EntityImportBatch::latest('id')->firstOrFail();
        $upload->assertRedirect(route('import-export.entity-imports.show', ['NZ', $batch]));
        $this->assertSame('ready', $batch->status);
        $this->assertSame('entity_name', $batch->mapping['Entity Name']);
        $this->assertSame('country', $batch->mapping['Country / Jurisdiction']);
        $this->get(route('import-export.entity-imports.show', ['NZ', $batch]))
            ->assertOk()
            ->assertDontSee('Column Mapping')
            ->assertSee('Arua Demo Trading Ltd')
            ->assertSee('Company')
            ->assertSee('New Zealand')
            ->assertSee('NZD')
            ->assertSee('Confirm Import')
            ->assertSee('Change Mapping');

        $this->post(route('import-export.entity-imports.confirm', ['NZ', $batch]))->assertRedirect();
        $batch->refresh();
        $entity = $batch->resultCompany()->firstOrFail();
        $this->assertSame(1, $this->user->companies()->where('name', 'Arua Demo Trading Ltd')->count());
        $this->get(route('companies.show', $entity))->assertOk()->assertSee('Arua Demo Trading Ltd');
        $this->get(route('import-export.country', 'NZ'))->assertOk()->assertSee('Arua Demo Trading Ltd');
        $this->get(route('import-export.workspace', ['NZ', $entity]))
            ->assertOk()
            ->assertSee('Arua Demo Trading Ltd')
            ->assertSee('Start New Import')
            ->assertSee('New Export');
    }

    public function test_entity_xlsx_ignores_blank_trailing_rows_and_rejects_two_populated_entities(): void
    {
        $country = Country::where('code', 'NZ')->firstOrFail();
        $service = app(EntityImportService::class);
        $headers = ['Entity Name', 'Entity Type', 'Country / Jurisdiction', 'Base Currency', 'Timezone', 'Financial Year Start', 'Financial Year End'];
        $validPath = storage_path('framework/testing/entity-one-row.xlsx');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray(['XLSX Entity', 'Company', 'NZ', 'NZD', 'Pacific/Auckland', '2025-04-01', '2026-03-31'], null, 'A2');
        $sheet->getStyle('A6:G6')->getFont()->setBold(true);
        (new Xlsx($spreadsheet))->save($validPath);
        $spreadsheet->disconnectWorksheets();

        $batch = $service->upload($country, new UploadedFile($validPath, 'entity-one-row.xlsx', null, null, true), $this->user);
        $this->assertSame('ready', $batch->status);
        $this->assertSame('XLSX Entity', $batch->raw_values['Entity Name']);

        $invalidPath = storage_path('framework/testing/entity-two-rows.xlsx');
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray(['Company A', 'Company', 'NZ', 'NZD', 'Pacific/Auckland', '2025-04-01', '2026-03-31'], null, 'A2');
        $sheet->fromArray(['Company B', 'Company', 'NZ', 'NZD', 'Pacific/Auckland', '2025-04-01', '2026-03-31'], null, 'A3');
        (new Xlsx($spreadsheet))->save($invalidPath);
        $spreadsheet->disconnectWorksheets();

        try {
            $service->upload($country, new UploadedFile($invalidPath, 'entity-two-rows.xlsx', null, null, true), $this->user);
            $this->fail('Two populated Accounting Entities must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'This file contains 2 Accounting Entities. v0.8 supports one Accounting Entity per import. Please upload a file containing one entity.',
                $exception->errors()['file'][0]
            );
        } finally {
            @unlink($validPath);
            @unlink($invalidPath);
        }
    }

    public function test_exact_customer_headers_auto_preview_and_external_headers_require_mapping_with_samples(): void
    {
        $samplePath = base_path('tests/SampleBusinessData/AruaDemoTrading/04_customers.csv');
        $upload = $this->actingAs($this->user)->post(route('import-export.imports.upload', ['NZ', $this->company]), [
            'data_type' => 'customers',
            'file' => new UploadedFile($samplePath, '04_customers.csv', 'text/csv', null, true),
        ]);
        $upload->assertSessionHasNoErrors();
        $batch = $this->company->importBatches()->latest('id')->firstOrFail();
        $upload->assertRedirect(route('import-export.batches.show', ['NZ', $this->company, $batch]));
        $this->assertSame('ready', $batch->status);
        $this->get(route('import-export.batches.show', ['NZ', $this->company, $batch]))
            ->assertOk()
            ->assertDontSee('Column Mapping and Options')
            ->assertSee('CUST001')
            ->assertSee('Kauri Design 001 Ltd')
            ->assertSee('Confirm Import')
            ->assertSee('Change Mapping');
        $this->post(route('import-export.batches.confirm', ['NZ', $this->company, $batch]))->assertRedirect();
        $this->assertDatabaseHas('customers', ['company_id' => $this->company->id, 'code' => 'CUST001', 'name' => 'Kauri Design 001 Ltd']);
        $this->assertDatabaseMissing('customers', ['company_id' => $this->company->id, 'code' => 'Customer Code']);

        $external = $this->post(route('import-export.imports.upload', ['NZ', $this->company]), [
            'data_type' => 'customers',
            'file' => UploadedFile::fake()->createWithContent('external-customers.csv', "CustCode,CustomerName,Telephone\nCUST999,Mapping Test Customer,0210000000\n"),
        ]);
        $externalBatch = $this->company->importBatches()->latest('id')->firstOrFail();
        $external->assertRedirect(route('import-export.batches.show', ['NZ', $this->company, $externalBatch]));
        $this->assertSame('mapping', $externalBatch->status);
        $this->get(route('import-export.batches.show', ['NZ', $this->company, $externalBatch]))
            ->assertOk()
            ->assertSee('Source Column')
            ->assertSee('Sample Value')
            ->assertSee('Arua Field')
            ->assertSee('CustCode')
            ->assertSee('CUST999')
            ->assertSee('CustomerName')
            ->assertSee('Mapping Test Customer')
            ->assertSee('Telephone')
            ->assertSee('0210000000');
        $this->post(route('import-export.batches.validate', ['NZ', $this->company, $externalBatch]), [
            'mapping' => $externalBatch->mapping,
            'posting_mode' => 'draft',
        ])->assertRedirect();
        $this->assertSame('ready', $externalBatch->fresh()->status);
    }

    public function test_parser_keeps_headers_separate_from_csv_and_xlsx_records_for_supported_imports(): void
    {
        $parser = app(FileParser::class);
        foreach (['04_customers.csv', '05_suppliers.csv', '06_products_services.csv', '07_sales_invoices.csv', '09_supplier_bills.csv', '11_manual_journals.csv'] as $filename) {
            $parsed = $parser->parse(base_path('tests/SampleBusinessData/AruaDemoTrading/'.$filename), 'csv');
            $this->assertNotEmpty($parsed['headers']);
            $this->assertNotEmpty($parsed['rows']);
            $this->assertSame(2, $parsed['rows'][0]['number']);
            foreach ($parsed['headers'] as $header) {
                $this->assertArrayHasKey($header, $parsed['rows'][0]['values']);
                $this->assertNotSame($header, $parsed['rows'][0]['values'][$header], $filename.' must not treat its header as production data.');
            }
        }

        $path = storage_path('framework/testing/parser-contract.xlsx');
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Customer Code', 'Customer Name'],
            ['XLSX001', 'XLSX Customer'],
        ]);
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        try {
            $parsed = $parser->parse($path, 'xlsx');
            $this->assertSame(['Customer Code', 'Customer Name'], $parsed['headers']);
            $this->assertSame(['Customer Code' => 'XLSX001', 'Customer Name' => 'XLSX Customer'], $parsed['rows'][0]['values']);
            $this->assertSame(2, $parsed['rows'][0]['number']);
        } finally {
            @unlink($path);
        }
    }

    public function test_exact_sample_imports_validate_after_documented_branch_account_and_tax_prerequisites(): void
    {
        $company = app(CompanyCreator::class)->create([
            'entity_type' => 'company',
            'name' => 'Sample Prerequisite Company',
            'legal_name' => 'Sample Prerequisite Company',
            'country_id' => Country::where('code', 'NZ')->value('id'),
            'base_currency_id' => Currency::where('code', 'NZD')->value('id'),
            'timezone' => 'Pacific/Auckland',
            'financial_year_start' => '2025-04-01',
            'financial_year_end' => '2026-03-31',
        ], $this->user);

        $accounts = $this->sampleBatch($company, 'chart_of_accounts', '03_chart_of_accounts.csv', true);
        $this->assertSame('completed', $accounts->status);
        $this->assertSame('Input Tax Recoverable', $company->accounts()->where('code', '1200')->value('name'));
        $this->assertSame('Output Tax Payable', $company->accounts()->where('code', '2100')->value('name'));

        $branches = app(BranchService::class);
        foreach ([['code' => 'AKL', 'name' => 'Auckland'], ['code' => 'WLG', 'name' => 'Wellington']] as $branch) {
            $branches->create($company, $branch + ['timezone' => 'Pacific/Auckland', 'is_active' => true, 'is_main_branch' => false], $this->user);
        }

        $beforeTax = $this->sampleBatch($company, 'products', '06_products_services.csv');
        $this->assertSame('validated', $beforeTax->status);
        $this->assertSame(40, $beforeTax->total_rows);
        $this->assertSame(40, $beforeTax->invalid_rows);
        $this->assertStringContainsString(
            'Configure it under Tax before importing this product.',
            implode(' ', $beforeTax->rows()->firstOrFail()->errors),
        );
        $this->actingAs($this->user)->get(route('import-export.workspace', ['NZ', $company]))
            ->assertOk()
            ->assertSee('Validation Failed')
            ->assertSee('40');

        $tax = app(TaxConfigurationService::class);
        $registration = $tax->registration($company, [
            'tax_type' => 'GST', 'name' => 'Generic GST', 'registration_number' => 'DEMO-NZ-001',
            'registration_name' => 'Arua Demo Trading Ltd', 'effective_from' => '2025-04-01',
            'effective_to' => '2026-03-31', 'filing_frequency' => 'two_monthly',
            'accounting_basis' => 'accrual', 'status' => 'active',
        ], $this->user);
        $standard = $tax->code($company, [
            'tax_registration_id' => $registration->id, 'tax_type' => 'GST', 'code' => 'STANDARD',
            'name' => 'Standard', 'treatment' => 'taxable', 'recoverability_type' => 'full',
            'effective_from' => '2025-04-01', 'effective_to' => '2026-03-31', 'is_active' => true,
        ], $this->user);
        $tax->code($company, [
            'tax_registration_id' => $registration->id, 'tax_type' => 'GST', 'code' => 'ZERO',
            'name' => 'Zero-rated', 'treatment' => 'zero_rated', 'recoverability_type' => 'full',
            'effective_from' => '2025-04-01', 'effective_to' => '2026-03-31', 'is_active' => true,
        ], $this->user);
        $tax->rate($company, $standard, ['rate' => '15.00', 'effective_from' => '2025-04-01', 'effective_to' => '2026-03-31', 'is_active' => true], $this->user);
        $tax->settings($company, [
            'output_tax_account_id' => $company->accounts()->where('code', '2100')->value('id'),
            'input_tax_account_id' => $company->accounts()->where('code', '1200')->value('id'),
            'rounding_method' => 'per_line',
        ], $this->user);
        $tax->generatePeriods($company, $registration, $this->user);

        $this->sampleBatch($company, 'customers', '04_customers.csv', true);
        $this->sampleBatch($company, 'suppliers', '05_suppliers.csv', true);
        $products = $this->sampleBatch($company, 'products', '06_products_services.csv', true);
        $this->assertSame(40, $products->total_rows);
        $this->assertSame(40, $products->valid_rows);
        $this->assertSame(0, $products->invalid_rows);
        $this->assertSame(40, $products->imported_rows);
        $this->assertSame(40, $company->items()->count());

        $sales = $this->sampleBatch($company, 'sales_invoices', '07_sales_invoices.csv');
        $this->assertSame('ready', $sales->status);
        $this->assertSame(900, $sales->total_rows);
        $this->assertSame(0, $sales->invalid_rows);

        $bills = $this->sampleBatch($company, 'supplier_bills', '09_supplier_bills.csv');
        $this->assertSame('ready', $bills->status);
        $this->assertSame(381, $bills->total_rows);
        $this->assertSame(0, $bills->invalid_rows);
    }

    public function test_entity_import_rejects_jurisdiction_type_duplicates_and_foreign_batches_and_exports_setup(): void
    {
        $service = app(EntityImportService::class);
        $country = Country::where('code', 'NZ')->firstOrFail();
        $mapping = ['Entity Name' => 'entity_name', 'Entity Type' => 'entity_type', 'Country' => 'country', 'Financial Year Start' => 'financial_year_start', 'Financial Year End' => 'financial_year_end'];
        foreach ([
            "Entity Name,Entity Type,Country,Financial Year Start,Financial Year End\nWrong Country,Company,AU,2025-04-01,2026-03-31\n",
            "Entity Name,Entity Type,Country,Financial Year Start,Financial Year End\nWrong Type,Trust,NZ,2025-04-01,2026-03-31\n",
            "Entity Name,Entity Type,Country,Financial Year Start,Financial Year End\nImport Company,Company,NZ,2025-04-01,2026-03-31\n",
        ] as $index => $csv) {
            $batch = $service->upload($country, UploadedFile::fake()->createWithContent("invalid-{$index}.csv", $csv), $this->user);
            $batch = $service->validate($batch, $mapping, $this->user);
            $this->assertSame('mapping', $batch->status);
            $this->assertNotEmpty($batch->errors);
        }
        $this->assertSame('exact_duplicate', $batch->duplicate_status);
        $foreign = User::factory()->create();
        $this->actingAs($foreign)->get(route('import-export.entity-imports.show', ['NZ', $batch]))->assertNotFound();

        $file = app(ExportService::class)->generate($this->company, 'accounting_entity', 'csv', [], $this->user);
        $contents = file_get_contents($file['path']);
        $this->assertStringContainsString('Entity Name', $contents);
        $this->assertStringContainsString($this->company->entity_label, $contents);
        @unlink($file['path']);
    }

    public function test_balanced_journal_import_financial_exports_and_opening_balance_staging_obey_ledger_controls(): void
    {
        $branch = $this->company->branches()->value('id');
        $journalCsv = "Reference,Date,Account,Debit,Credit,Description\nJRN001,2027-01-10,1000,1000,0,Capital introduced\nJRN001,2027-01-10,3000,0,1000,Capital introduced\n";
        $batch = $this->batch('manual_journals', $journalCsv, ['Reference' => 'journal_ref', 'Date' => 'date', 'Account' => 'account', 'Debit' => 'debit', 'Credit' => 'credit', 'Description' => 'description'], true, $branch);
        $this->assertSame('completed', $batch->status);
        $journal = $this->company->journals()->where('reference', 'JRN001')->firstOrFail();
        $this->assertSame('draft', $journal->status);
        $this->assertSame(0, bccomp((string) $journal->lines()->sum('debit'), '1000.0000', 4));
        $this->assertSame(0, bccomp((string) $journal->lines()->sum('credit'), '1000.0000', 4));
        app(JournalService::class)->post($journal, $this->user);

        $exports = app(ExportService::class);
        foreach (['trial_balance', 'profit_loss', 'balance_sheet', 'journal_entries'] as $type) {
            $file = $exports->generate($this->company, $type, 'csv', ['financial_year_id' => $this->company->financialYears()->value('id')], $this->user);
            $this->assertFileExists($file['path']);
            $this->assertGreaterThan(0, filesize($file['path']));
            @unlink($file['path']);
        }

        $openingCsv = "Reference,Date,Account,Debit,Credit,Description\nOPEN1,2027-01-01,1000,500,0,Opening\nOPEN1,2027-01-01,3000,0,500,Opening\n";
        $opening = $this->batch('opening_balances', $openingCsv, ['Reference' => 'opening_ref', 'Date' => 'date', 'Account' => 'account', 'Debit' => 'debit', 'Credit' => 'credit', 'Description' => 'description']);
        $this->assertSame('ready', $opening->status);
        $this->assertSame(2, $opening->warning_rows);
        $this->assertThrows(fn () => app(ImportService::class)->confirm($this->company, $opening, $this->user), ValidationException::class);
        $this->assertSame(1, $this->company->journals()->count());
    }

    public function test_failed_upload_cancellation_mapping_rules_and_foreign_profiles_are_protected(): void
    {
        $service = app(ImportService::class);
        try {
            $service->upload($this->company, 'customers', UploadedFile::fake()->createWithContent('broken.xlsx', 'not an xlsx archive'), null, $this->user);
            $this->fail('Malformed workbook should fail.');
        } catch (ValidationException) {
            $this->assertDatabaseHas('import_batches', ['company_id' => $this->company->id, 'original_filename' => 'broken.xlsx', 'status' => 'failed']);
            $this->assertDatabaseHas('audit_logs', ['company_id' => $this->company->id, 'event' => 'import.failed']);
        }

        $batch = $service->upload($this->company, 'customers', UploadedFile::fake()->createWithContent('cancel.csv', "Code,Name\nC1,Cancelled\n"), null, $this->user);
        $this->assertThrows(fn () => $service->validate($this->company, $batch, ['Code' => 'code', 'Name' => 'code'], [], $this->user), ValidationException::class);
        $service->cancel($this->company, $batch, $this->user);
        $this->assertSame('cancelled', $batch->fresh()->status);
        $this->assertDatabaseMissing('customers', ['company_id' => $this->company->id, 'code' => 'C1']);

        $profile = $service->saveProfile($this->company, ['data_type' => 'customers', 'name' => 'Private', 'source_headers' => ['Code'], 'mapping' => ['Code' => 'code']], $this->user);
        $foreign = $this->entity('company', 'Other Profile Entity');
        $this->actingAs($this->user)->get(route('import-export.profiles.edit', ['NZ', $foreign, $profile]))->assertNotFound();
    }

    public function test_taxable_sales_and_purchase_imports_match_manual_accounting_results(): void
    {
        $this->company->accounts()->create(['code' => '1150', 'name' => 'Input Tax', 'type' => 'asset', 'normal_balance' => 'debit', 'is_active' => true, 'is_system' => false, 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $this->company->accounts()->create(['code' => '2100', 'name' => 'Output Tax', 'type' => 'liability', 'normal_balance' => 'credit', 'is_active' => true, 'is_system' => false, 'created_by' => $this->user->id, 'updated_by' => $this->user->id]);
        $tax = app(TaxConfigurationService::class);
        $registration = $tax->registration($this->company, ['tax_type' => 'GST', 'name' => 'Generic Tax', 'registration_number' => 'REG-IMPORT', 'registration_name' => 'Import Company', 'effective_from' => '2027-01-01', 'effective_to' => '2027-12-31', 'filing_frequency' => 'quarterly', 'accounting_basis' => 'accrual', 'status' => 'active'], $this->user);
        $standard = $tax->code($this->company, ['tax_registration_id' => $registration->id, 'tax_type' => 'GST', 'code' => 'STANDARD', 'name' => 'Standard', 'treatment' => 'taxable', 'recoverability_type' => 'full', 'effective_from' => '2027-01-01', 'is_active' => true], $this->user);
        $zero = $tax->code($this->company, ['tax_registration_id' => $registration->id, 'tax_type' => 'GST', 'code' => 'ZERO', 'name' => 'Zero', 'treatment' => 'zero_rated', 'recoverability_type' => 'full', 'effective_from' => '2027-01-01', 'is_active' => true], $this->user);
        $tax->rate($this->company, $standard, ['rate' => '10', 'effective_from' => '2027-01-01', 'is_active' => true], $this->user);
        $tax->generatePeriods($this->company, $registration, $this->user);
        $tax->settings($this->company, ['output_tax_account_id' => $this->account('2100'), 'input_tax_account_id' => $this->account('1150'), 'rounding_method' => 'per_line'], $this->user);
        app(SalesService::class)->createCustomer($this->company, ['code' => 'TAX-C', 'name' => 'Tax Customer', 'type' => 'business', 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 30, 'credit_limit' => 1000, 'receivable_account_id' => $this->account('1100')], $this->user);
        app(SupplierMaintenanceService::class)->create($this->company, ['code' => 'TAX-S', 'name' => 'Tax Supplier', 'type' => 'business', 'currency_id' => $this->company->base_currency_id, 'payment_terms_days' => 30, 'credit_limit' => 1000, 'payable_account_id' => $this->account('2000'), 'is_active' => true], $this->user);
        $branch = $this->company->branches()->value('id');
        $mapping = ['Document' => 'invoice_ref', 'Party' => 'customer', 'Date' => 'invoice_date', 'Due' => 'due_date', 'Account' => 'revenue_account', 'Description' => 'description', 'Qty' => 'quantity', 'Price' => 'unit_price', 'Tax Code' => 'tax_code', 'Source Tax' => 'source_tax'];
        $salesCsv = "Document,Party,Date,Due,Account,Description,Qty,Price,Tax Code,Source Tax\nINV-TAX,TAX-C,2027-03-01,2027-03-31,4000,Taxable,1,100,STANDARD,10\nINV-TAX,TAX-C,2027-03-01,2027-03-31,4000,Zero,1,50,ZERO,0\n";
        $this->batch('sales_invoices', $salesCsv, $mapping, true, $branch, 'post');
        $invoice = $this->company->salesInvoices()->where('customer_reference', 'INV-TAX')->firstOrFail();
        $this->assertSame('150.0000', $invoice->subtotal);
        $this->assertSame('10.0000', $invoice->tax_amount);
        $this->assertSame('160.0000', $invoice->total);
        $this->assertSame('posted', $invoice->status);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $invoice->journal_entry_id, 'account_id' => $this->account('1100'), 'debit' => '160.0000']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $invoice->journal_entry_id, 'account_id' => $this->account('2100'), 'credit' => '10.0000']);

        $purchaseMapping = ['Document' => 'bill_ref', 'Party' => 'supplier', 'Date' => 'bill_date', 'Due' => 'due_date', 'Account' => 'expense_account', 'Description' => 'description', 'Qty' => 'quantity', 'Price' => 'unit_price', 'Tax Code' => 'tax_code', 'Source Tax' => 'source_tax'];
        $purchaseCsv = "Document,Party,Date,Due,Account,Description,Qty,Price,Tax Code,Source Tax\nBILL-TAX,TAX-S,2027-03-01,2027-03-31,5000,Taxable,1,200,STANDARD,20\nBILL-TAX,TAX-S,2027-03-01,2027-03-31,5000,Zero,1,50,ZERO,0\n";
        $this->batch('supplier_bills', $purchaseCsv, $purchaseMapping, true, $branch, 'post');
        $bill = $this->company->supplierBills()->where('supplier_reference', 'BILL-TAX')->firstOrFail();
        $this->assertSame('250.0000', $bill->subtotal);
        $this->assertSame('20.0000', $bill->tax_amount);
        $this->assertSame('270.0000', $bill->total);
        $this->assertSame('posted', $bill->status);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $bill->journal_entry_id, 'account_id' => $this->account('1150'), 'debit' => '20.0000']);
        $this->assertDatabaseHas('journal_lines', ['journal_entry_id' => $bill->journal_entry_id, 'account_id' => $this->account('2000'), 'credit' => '270.0000']);
        $this->assertDatabaseCount('transaction_tax_lines', 4);
    }

    private function batch(string $type, string $csv, array $mapping, bool $confirm = false, ?int $branchId = null, string $postingMode = 'draft'): ImportBatch
    {
        $service = app(ImportService::class);
        $batch = $service->upload($this->company, $type, UploadedFile::fake()->createWithContent($type.'.csv', $csv), $branchId, $this->user);
        $batch = $service->validate($this->company, $batch, $mapping, ['posting_mode' => $postingMode], $this->user);

        return $confirm ? $service->confirm($this->company, $batch, $this->user) : $batch;
    }

    private function sampleBatch(Company $company, string $type, string $filename, bool $confirm = false): ImportBatch
    {
        $path = base_path('tests/SampleBusinessData/AruaDemoTrading/'.$filename);
        $file = new UploadedFile($path, $filename, 'text/csv', null, true);
        $service = app(ImportService::class);
        $batch = $service->upload($company, $type, $file, null, $this->user);

        return $confirm ? $service->confirm($company, $batch, $this->user) : $batch;
    }

    private function entity(string $type, string $name): Company
    {
        return app(CompanyCreator::class)->create(['entity_type' => $type, 'name' => $name, 'legal_name' => $name, 'individual_name' => $type === 'individual' ? $name : null, 'trading_name' => $type === 'sole_trader' ? $name : null, 'country_id' => Country::where('code', 'NZ')->value('id'), 'base_currency_id' => Currency::where('code', 'NZD')->value('id'), 'timezone' => 'Pacific/Auckland', 'financial_year_start' => '2027-01-01', 'financial_year_end' => '2027-12-31'], $this->user);
    }

    private function account(string $code): int
    {
        return $this->company->accounts()->where('code', $code)->value('id');
    }

    private function fixture(string $filename): string
    {
        return file_get_contents($this->fixturePath($filename));
    }

    private function fixturePath(string $filename): string
    {
        return base_path('tests/Fixtures/ImportExport/'.$filename);
    }
}

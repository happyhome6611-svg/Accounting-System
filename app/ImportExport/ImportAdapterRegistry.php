<?php

namespace App\ImportExport;

use App\ImportExport\Adapters\ChartOfAccountsImportAdapter;
use App\ImportExport\Adapters\CustomerImportAdapter;
use App\ImportExport\Adapters\ManualJournalImportAdapter;
use App\ImportExport\Adapters\OpeningBalanceImportAdapter;
use App\ImportExport\Adapters\ProductImportAdapter;
use App\ImportExport\Adapters\SalesInvoiceImportAdapter;
use App\ImportExport\Adapters\SupplierBillImportAdapter;
use App\ImportExport\Adapters\SupplierImportAdapter;
use App\ImportExport\Contracts\ImportAdapter;

final class ImportAdapterRegistry
{
    private const ADAPTERS = [
        'chart_of_accounts' => ChartOfAccountsImportAdapter::class,
        'customers' => CustomerImportAdapter::class,
        'suppliers' => SupplierImportAdapter::class,
        'products' => ProductImportAdapter::class,
        'sales_invoices' => SalesInvoiceImportAdapter::class,
        'supplier_bills' => SupplierBillImportAdapter::class,
        'manual_journals' => ManualJournalImportAdapter::class,
        'opening_balances' => OpeningBalanceImportAdapter::class,
    ];

    public function get(string $type): ImportAdapter
    {
        abort_unless(isset(self::ADAPTERS[$type]), 404);

        return app(self::ADAPTERS[$type]);
    }

    public function types(): array
    {
        return ['chart_of_accounts' => 'Chart of Accounts', 'customers' => 'Customers', 'suppliers' => 'Suppliers', 'products' => 'Products / Services', 'sales_invoices' => 'Sales Invoices', 'supplier_bills' => 'Supplier Bills', 'manual_journals' => 'Manual Journals', 'opening_balances' => 'Opening Balances (staging only)'];
    }
}

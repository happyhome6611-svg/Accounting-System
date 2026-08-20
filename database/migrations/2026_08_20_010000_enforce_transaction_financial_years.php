<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = ['sales_quotations', 'sales_orders', 'sales_invoices', 'sales_credit_notes', 'customer_receipts'];

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('UPDATE sales_orders orders SET financial_year_id = quotation.financial_year_id FROM sales_quotations quotation WHERE orders.financial_year_id IS NULL AND quotation.id = orders.sales_quotation_id AND (orders.converted_invoice_id IS NULL OR EXISTS (SELECT 1 FROM sales_invoices invoice WHERE invoice.id = orders.converted_invoice_id AND invoice.financial_year_id = quotation.financial_year_id))');
        }
        foreach (self::TABLES as $table) {
            if (DB::table($table)->whereNull('financial_year_id')->exists()) {
                throw new RuntimeException("{$table} contains transactions whose Financial Year could not be resolved safely.");
            }
            if (DB::getDriverName() === 'pgsql') {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN financial_year_id SET NOT NULL");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN financial_year_id DROP NOT NULL");
            }
        }
    }
};

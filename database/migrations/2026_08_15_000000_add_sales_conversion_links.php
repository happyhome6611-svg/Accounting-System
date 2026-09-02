<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->foreignId('sales_quotation_id')->nullable()->unique()->after('branch_id')->constrained()->restrictOnDelete();
        });
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->foreignId('sales_order_id')->nullable()->unique()->after('branch_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_order_id'));
        Schema::table('sales_orders', fn (Blueprint $table) => $table->dropConstrainedForeignId('sales_quotation_id'));
    }
};

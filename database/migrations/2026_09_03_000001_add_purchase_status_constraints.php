<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private array $constraints = [
        'purchase_orders_status_check' => ['purchase_orders', "status IN ('draft', 'billed', 'cancelled')"],
        'supplier_bills_status_check' => ['supplier_bills', "status IN ('draft', 'posted', 'partially_paid', 'paid')"],
        'supplier_credits_status_check' => ['supplier_credits', "status IN ('draft', 'posted')"],
        'supplier_payments_status_check' => ['supplier_payments', "status IN ('draft', 'posted')"],
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->constraints as $name => [$table, $expression]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$expression})");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->constraints as $name => [$table]) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
        }
    }
};

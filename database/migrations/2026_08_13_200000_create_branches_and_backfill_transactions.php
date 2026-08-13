<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $transactions = ['journal_entries', 'sales_quotations', 'sales_orders', 'sales_invoices', 'sales_credit_notes', 'customer_receipts'];

    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->text('address')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_main_branch')->default(false);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
        foreach (DB::table('companies')->orderBy('id')->get() as $company) {
            DB::table('branches')->insert(['company_id' => $company->id, 'code' => 'HO', 'name' => 'Head Office', 'timezone' => $company->timezone, 'is_active' => true, 'is_main_branch' => true, 'created_by' => $company->created_by, 'updated_by' => $company->updated_by, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($this->transactions as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('branch_id')->nullable()->after('company_id')->constrained('branches')->restrictOnDelete());
            foreach (DB::table('branches')->where('is_main_branch', true)->get() as $branch) {
                DB::table($table)->where('company_id', $branch->company_id)->whereNull('branch_id')->update(['branch_id' => $branch->id]);
            }
            Schema::table($table, fn (Blueprint $t) => $t->index(['company_id', 'branch_id']));
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE UNIQUE INDEX branches_one_main_per_company ON branches (company_id) WHERE is_main_branch = true');
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->transactions) as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
        }
        Schema::dropIfExists('branches');
    }
};

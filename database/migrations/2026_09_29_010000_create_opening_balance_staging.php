<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('opening_balance_stagings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('financial_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_batch_id')->unique()->constrained()->restrictOnDelete();
            $table->date('balance_date');
            $table->string('opening_reference');
            $table->string('status', 24)->default('staged');
            $table->foreignId('converted_journal_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'balance_date']);
        });
        Schema::create('opening_balance_staging_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('opening_balance_staging_id')->constrained()->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->text('description');
            $table->decimal('debit', 20, 4)->default(0);
            $table->decimal('credit', 20, 4)->default(0);
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batch_status');
            DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batch_status CHECK (status IN ('uploaded','mapping','validated','ready','importing','completed','completed_with_errors','failed','cancelled','staged'))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE import_batches DROP CONSTRAINT import_batch_status');
            DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batch_status CHECK (status IN ('uploaded','mapping','validated','ready','importing','completed','completed_with_errors','failed','cancelled'))");
        }
        Schema::dropIfExists('opening_balance_staging_lines');
        Schema::dropIfExists('opening_balance_stagings');
    }
};

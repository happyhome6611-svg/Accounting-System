<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('data_type', 40);
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('file_format', 8);
            $table->string('worksheet')->nullable();
            $table->char('file_fingerprint', 64);
            $table->string('status', 32)->default('uploaded');
            $table->jsonb('source_headers')->default('[]');
            $table->jsonb('mapping')->default('{}');
            $table->jsonb('options')->default('{}');
            foreach (['total_rows', 'valid_rows', 'warning_rows', 'invalid_rows', 'duplicate_rows', 'imported_rows', 'skipped_rows', 'failed_rows'] as $column) {
                $table->unsignedInteger($column)->default(0);
            }
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('uploaded_at');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'data_type', 'status']);
            $table->index(['company_id', 'file_fingerprint']);
        });

        Schema::create('import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('source_row_number');
            $table->jsonb('raw_values');
            $table->jsonb('mapped_values')->default('{}');
            $table->char('row_fingerprint', 64);
            $table->string('validation_status', 20)->default('pending');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('errors')->default('[]');
            $table->string('duplicate_status', 20)->default('not_duplicate');
            $table->string('result_model')->nullable();
            $table->unsignedBigInteger('result_id')->nullable();
            $table->timestamps();
            $table->unique(['import_batch_id', 'source_row_number']);
            $table->index(['import_batch_id', 'validation_status']);
            $table->index(['row_fingerprint']);
        });

        Schema::create('import_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('data_type', 40);
            $table->string('name');
            $table->jsonb('source_headers');
            $table->jsonb('mapping');
            $table->jsonb('options')->default('{}');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'data_type', 'name']);
        });

        Schema::create('export_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('data_type', 40);
            $table->string('format', 8);
            $table->jsonb('filters')->default('{}');
            $table->string('filename');
            $table->unsignedInteger('row_count')->default(0);
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('generated_at');
            $table->timestamps();
            $table->index(['company_id', 'generated_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batch_status CHECK (status IN ('uploaded','mapping','validated','ready','importing','completed','completed_with_errors','failed','cancelled'))");
            DB::statement("ALTER TABLE import_batches ADD CONSTRAINT import_batch_format CHECK (file_format IN ('csv','xlsx'))");
            DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_row_validation_status CHECK (validation_status IN ('pending','valid','warning','error','imported','skipped','failed'))");
            DB::statement("ALTER TABLE import_rows ADD CONSTRAINT import_row_duplicate_status CHECK (duplicate_status IN ('not_duplicate','possible_duplicate','exact_duplicate'))");
            DB::statement("ALTER TABLE export_logs ADD CONSTRAINT export_log_format CHECK (format IN ('csv','xlsx'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('export_logs');
        Schema::dropIfExists('import_profiles');
        Schema::dropIfExists('import_rows');
        Schema::dropIfExists('import_batches');
    }
};

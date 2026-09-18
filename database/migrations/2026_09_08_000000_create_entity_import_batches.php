<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entity_import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('file_format', 8);
            $table->string('worksheet')->nullable();
            $table->char('file_fingerprint', 64);
            $table->string('status', 24)->default('uploaded');
            $table->jsonb('source_headers')->default('[]');
            $table->jsonb('mapping')->default('{}');
            $table->jsonb('raw_values')->default('{}');
            $table->jsonb('mapped_values')->default('{}');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('errors')->default('[]');
            $table->string('duplicate_status', 20)->default('not_duplicate');
            $table->foreignId('result_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->index(['uploaded_by', 'country_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE entity_import_batches ADD CONSTRAINT entity_import_status CHECK (status IN ('uploaded','mapping','ready','completed','failed','cancelled'))");
            DB::statement("ALTER TABLE entity_import_batches ADD CONSTRAINT entity_import_format CHECK (file_format IN ('csv','xlsx'))");
            DB::statement("ALTER TABLE entity_import_batches ADD CONSTRAINT entity_import_duplicate CHECK (duplicate_status IN ('not_duplicate','possible_duplicate','exact_duplicate'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_import_batches');
    }
};

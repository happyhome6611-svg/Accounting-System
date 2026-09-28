<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->string('undo_status', 24)->nullable()->index();
            $table->jsonb('undo_summary')->default('{}');
            $table->foreignId('undone_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->softDeletes();
        });
        Schema::table('import_rows', function (Blueprint $table) {
            $table->timestamp('undone_at')->nullable();
            $table->string('undo_result_model')->nullable();
            $table->unsignedBigInteger('undo_result_id')->nullable();
        });
        Schema::table('entity_import_batches', function (Blueprint $table) {
            $table->string('undo_status', 24)->nullable()->index();
            $table->jsonb('undo_summary')->default('{}');
            $table->foreignId('undone_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->softDeletes();
        });
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->string('undo_status', 24)->nullable()->index();
            $table->jsonb('undo_summary')->default('{}');
            $table->foreignId('undone_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('undone_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('bank_statement_imports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('undone_by');
            $table->dropColumn(['undo_status', 'undo_summary', 'undone_at']);
        });
        Schema::table('entity_import_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('undone_by');
            $table->dropColumn(['undo_status', 'undo_summary', 'undone_at', 'deleted_at']);
        });
        Schema::table('import_rows', fn (Blueprint $table) => $table->dropColumn(['undone_at', 'undo_result_model', 'undo_result_id']));
        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('undone_by');
            $table->dropColumn(['undo_status', 'undo_summary', 'undone_at', 'deleted_at']);
        });
    }
};

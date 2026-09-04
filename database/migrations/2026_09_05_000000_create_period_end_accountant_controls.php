<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->date('bookkeeping_lock_date')->nullable();
            $table->date('adviser_lock_date')->nullable();
            $table->foreignId('retained_earnings_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
        });
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->string('journal_type', 32)->default('standard');
            $table->text('reason')->nullable();
            $table->text('supporting_notes')->nullable();
            $table->index(['company_id', 'journal_type', 'financial_year_id']);
        });
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('closure_reason')->nullable();
            $table->json('closure_snapshot')->nullable();
        });
        Schema::create('period_close_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $table->string('key', 64);
            $table->string('label');
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_system_check')->default(false);
            $table->string('status', 24)->default('not_started');
            $table->text('notes')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['accounting_period_id', 'key']);
        });
        Schema::create('adjustment_reversal_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('adjustment_journal_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->date('reversal_date');
            $table->string('status', 20)->default('scheduled');
            $table->foreignId('reversal_journal_id')->nullable()->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('year_end_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_year_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('retained_earnings_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('closing_journal_id')->unique()->constrained('journal_entries')->restrictOnDelete();
            $table->decimal('net_result', 20, 4);
            $table->string('status', 20)->default('completed');
            $table->text('notes')->nullable();
            $table->foreignId('completed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('completed_at');
            $table->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE journal_entries ADD CONSTRAINT journal_entries_type_check CHECK (journal_type IN ('standard','adjusting','reversing','opening_balance','closing','prior_period_adjustment','system_generated'))");
            DB::statement("ALTER TABLE period_close_checklist_items ADD CONSTRAINT period_checklist_status_check CHECK (status IN ('not_started','in_progress','completed','not_applicable'))");
            DB::statement("ALTER TABLE adjustment_reversal_schedules ADD CONSTRAINT reversal_schedule_status_check CHECK (status IN ('scheduled','posted','cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('year_end_closures');
        Schema::dropIfExists('adjustment_reversal_schedules');
        Schema::dropIfExists('period_close_checklist_items');
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn(['closure_reason', 'closure_snapshot']);
        });
        Schema::table('journal_entries', fn (Blueprint $table) => $table->dropColumn(['journal_type', 'reason', 'supporting_notes']));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('retained_earnings_account_id');
            $table->dropColumn(['bookkeeping_lock_date', 'adviser_lock_date']);
        });
    }
};

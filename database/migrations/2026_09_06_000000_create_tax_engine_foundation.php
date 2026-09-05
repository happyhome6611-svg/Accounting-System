<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tax_obligations', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'tax_type']);
            $table->string('registration_number')->nullable();
            $table->string('registration_name')->nullable();
            $table->date('effective_from')->nullable();
            $table->date('effective_to')->nullable();
            $table->string('filing_frequency', 24)->default('quarterly');
            $table->string('accounting_basis', 24)->default('accrual');
            $table->text('notes')->nullable();
            $table->index(['company_id', 'status', 'effective_from', 'effective_to'], 'tax_registration_effective_index');
            $table->unique(['company_id', 'tax_type', 'registration_number', 'effective_from'], 'tax_registration_identity_unique');
        });

        Schema::create('tax_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_registration_id')->nullable()->constrained('tax_obligations')->restrictOnDelete();
            $table->string('tax_type', 40);
            $table->string('code', 40);
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('treatment', 24);
            $table->string('recoverability_type', 24)->default('full');
            $table->boolean('is_active')->default(true);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
            $table->index(['country_id', 'tax_type', 'is_active']);
        });

        Schema::create('tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_code_id')->constrained()->restrictOnDelete();
            $table->decimal('rate', 9, 6);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tax_code_id', 'effective_from']);
            $table->index(['tax_code_id', 'is_active', 'effective_from', 'effective_to']);
        });

        Schema::create('tax_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('output_tax_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('input_tax_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('rounding_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('default_sales_tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->foreignId('default_purchase_tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            $table->string('rounding_method', 24)->default('per_line');
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::table('tax_filing_periods', function (Blueprint $table) {
            $table->json('prepared_snapshot')->nullable();
            $table->foreignId('prepared_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('filed_at')->nullable();
        });

        Schema::create('transaction_tax_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_registration_id')->constrained('tax_obligations')->restrictOnDelete();
            $table->foreignId('tax_period_id')->nullable()->constrained('tax_filing_periods')->restrictOnDelete();
            $table->foreignId('tax_code_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('journal_line_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('source_type', 80);
            $table->unsignedBigInteger('source_id');
            $table->string('source_line_type', 80);
            $table->unsignedBigInteger('source_line_id');
            $table->string('direction', 16);
            $table->date('transaction_date');
            $table->string('tax_code_snapshot', 40);
            $table->string('tax_type_snapshot', 40);
            $table->string('treatment_snapshot', 24);
            $table->string('registration_number_snapshot')->nullable();
            $table->decimal('rate_snapshot', 9, 6);
            $table->decimal('net_amount', 20, 4);
            $table->decimal('tax_amount', 20, 4);
            $table->decimal('gross_amount', 20, 4);
            $table->timestamps();
            $table->unique(['source_line_type', 'source_line_id']);
            $table->index(['company_id', 'tax_period_id', 'direction', 'transaction_date'], 'tax_register_filter_index');
        });

        Schema::create('tax_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_registration_id')->constrained('tax_obligations')->restrictOnDelete();
            $table->foreignId('tax_period_id')->nullable()->constrained('tax_filing_periods')->restrictOnDelete();
            $table->foreignId('tax_code_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('adjustment_date');
            $table->decimal('amount', 20, 4);
            $table->text('reason');
            $table->string('reference')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        foreach (['items', 'customers', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('default_sales_tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
                $table->foreignId('default_purchase_tax_code_id')->nullable()->constrained('tax_codes')->restrictOnDelete();
            });
        }
        foreach (['sales_invoice_lines', 'sales_credit_note_lines', 'supplier_bill_lines', 'supplier_credit_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('tax_code_id')->nullable()->constrained()->restrictOnDelete();
                $table->boolean('tax_inclusive')->default(false);
            });
        }
        Schema::table('sales_credit_note_lines', fn (Blueprint $table) => $table->decimal('tax_amount', 20, 4)->default(0));
        Schema::table('sales_credit_notes', fn (Blueprint $table) => $table->decimal('tax_amount', 20, 4)->default(0));
        Schema::table('supplier_credit_lines', fn (Blueprint $table) => $table->decimal('tax_amount', 20, 4)->default(0));
        Schema::table('supplier_credits', fn (Blueprint $table) => $table->decimal('tax_amount', 20, 4)->default(0));

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE tax_obligations ADD CONSTRAINT tax_registration_dates CHECK (effective_to IS NULL OR effective_from IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE tax_obligations ADD CONSTRAINT tax_registration_status CHECK (status IN ('draft','active','inactive','cancelled'))");
            DB::statement('ALTER TABLE tax_codes ADD CONSTRAINT tax_code_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement("ALTER TABLE tax_codes ADD CONSTRAINT tax_code_treatment CHECK (treatment IN ('taxable','zero_rated','exempt','out_of_scope'))");
            DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rate_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE tax_rates ADD CONSTRAINT tax_rate_nonnegative CHECK (rate >= 0)');
            DB::statement("ALTER TABLE transaction_tax_lines ADD CONSTRAINT tax_line_direction CHECK (direction IN ('output','input','adjustment'))");
            DB::statement("ALTER TABLE tax_adjustments ADD CONSTRAINT tax_adjustment_status CHECK (status IN ('draft','posted','cancelled'))");
        }
    }

    public function down(): void
    {
        Schema::table('supplier_credits', fn (Blueprint $table) => $table->dropColumn('tax_amount'));
        Schema::table('sales_credit_notes', fn (Blueprint $table) => $table->dropColumn('tax_amount'));
        foreach (['supplier_credit_lines', 'sales_credit_note_lines'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropColumn('tax_amount'));
        }
        foreach (['sales_invoice_lines', 'sales_credit_note_lines', 'supplier_bill_lines', 'supplier_credit_lines'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tax_code_id');
                $table->dropColumn('tax_inclusive');
            });
        }
        foreach (['items', 'customers', 'suppliers'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('default_sales_tax_code_id');
                $table->dropConstrainedForeignId('default_purchase_tax_code_id');
            });
        }
        Schema::dropIfExists('tax_adjustments');
        Schema::dropIfExists('transaction_tax_lines');
        Schema::table('tax_filing_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prepared_by');
            $table->dropConstrainedForeignId('filed_by');
            $table->dropColumn(['prepared_snapshot', 'prepared_at', 'filed_at']);
        });
        Schema::dropIfExists('tax_settings');
        Schema::dropIfExists('tax_rates');
        Schema::dropIfExists('tax_codes');
        Schema::table('tax_obligations', function (Blueprint $table) {
            $table->dropUnique('tax_registration_identity_unique');
            $table->dropIndex('tax_registration_effective_index');
            $table->dropColumn(['registration_number', 'registration_name', 'effective_from', 'effective_to', 'filing_frequency', 'accounting_basis', 'notes']);
            $table->unique(['company_id', 'tax_type']);
        });
    }
};

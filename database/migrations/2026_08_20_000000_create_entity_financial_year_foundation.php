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
            $table->string('entity_type', 24)->default('company')->after('id');
            $table->string('individual_name')->nullable()->after('legal_name');
            $table->string('trading_name')->nullable()->after('individual_name');
            $table->index(['entity_type', 'country_id']);
        });

        Schema::table('financial_years', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('filed_at')->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->timestamp('reopened_at')->nullable();
            $table->foreignId('reopened_by')->nullable()->constrained('users')->restrictOnDelete();
        });

        foreach (['sales_quotations', 'sales_orders', 'sales_invoices', 'sales_credit_notes', 'customer_receipts'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->foreignId('financial_year_id')->nullable()->constrained()->restrictOnDelete();
                $table->index(['company_id', 'financial_year_id']);
            });
        }

        foreach (['sales_invoices', 'sales_credit_notes', 'customer_receipts'] as $table) {
            DB::table($table)->update(['financial_year_id' => DB::raw("(SELECT financial_year_id FROM accounting_periods WHERE accounting_periods.id = {$table}.accounting_period_id)")]);
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach (['sales_quotations' => 'quotation_date', 'sales_orders' => 'order_date'] as $table => $date) {
                DB::statement("UPDATE {$table} d SET financial_year_id = y.id FROM financial_years y WHERE y.company_id = d.company_id AND d.{$date} BETWEEN y.starts_on AND y.ends_on AND (SELECT COUNT(*) FROM financial_years matches WHERE matches.company_id = d.company_id AND d.{$date} BETWEEN matches.starts_on AND matches.ends_on) = 1");
            }
            DB::statement('UPDATE sales_orders orders SET financial_year_id = quotation.financial_year_id FROM sales_quotations quotation WHERE orders.financial_year_id IS NULL AND quotation.id = orders.sales_quotation_id AND (orders.converted_invoice_id IS NULL OR EXISTS (SELECT 1 FROM sales_invoices invoice WHERE invoice.id = orders.converted_invoice_id AND invoice.financial_year_id = quotation.financial_year_id))');
        }

        foreach (['sales_quotations', 'sales_orders', 'sales_invoices', 'sales_credit_notes', 'customer_receipts'] as $table) {
            if (DB::table($table)->whereNull('financial_year_id')->exists()) {
                throw new RuntimeException("Unable to safely backfill financial year for {$table}.");
            }
        }

        Schema::create('tax_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_year_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('tax_obligations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('tax_type', 40);
            $table->string('name');
            $table->string('status', 16)->default('inactive');
            $table->json('configuration')->default('{}');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'tax_type']);
        });

        Schema::create('tax_filing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_year_id')->constrained()->restrictOnDelete();
            $table->foreignId('tax_obligation_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->date('due_on')->nullable();
            $table->string('status', 16)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['tax_obligation_id', 'starts_on', 'ends_on']);
        });

        Schema::create('prior_period_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('origin_financial_year_id')->constrained('financial_years')->restrictOnDelete();
            $table->foreignId('adjustment_financial_year_id')->constrained('financial_years')->restrictOnDelete();
            $table->string('adjustment_type', 40);
            $table->text('reason');
            $table->string('status', 20)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('document_type')->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('carry_forwards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('source_financial_year_id')->nullable()->constrained('financial_years')->restrictOnDelete();
            $table->foreignId('destination_financial_year_id')->nullable()->constrained('financial_years')->restrictOnDelete();
            $table->foreignId('source_tax_year_id')->nullable()->constrained('tax_years')->restrictOnDelete();
            $table->foreignId('destination_tax_year_id')->nullable()->constrained('tax_years')->restrictOnDelete();
            $table->string('type', 40);
            $table->decimal('original_amount', 20, 4);
            $table->decimal('amount_used', 20, 4)->default(0);
            $table->decimal('amount_remaining', 20, 4);
            $table->string('status', 20)->default('available');
            $table->text('notes')->nullable();
            $table->string('reference')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['sales_quotations', 'sales_orders', 'sales_invoices', 'sales_credit_notes', 'customer_receipts'] as $table) {
                DB::statement("ALTER TABLE {$table} ALTER COLUMN financial_year_id SET NOT NULL");
            }
            DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_entity_type_check CHECK (entity_type IN ('company','individual','sole_trader','partnership','trust','other'))");
            DB::statement('ALTER TABLE financial_years DROP CONSTRAINT IF EXISTS financial_years_status_check');
            DB::statement("ALTER TABLE financial_years ADD CONSTRAINT financial_years_status_check CHECK (status IN ('open','closing','closed','filed'))");
            DB::statement('ALTER TABLE tax_years ADD CONSTRAINT tax_year_dates CHECK (ends_on >= starts_on)');
            DB::statement('ALTER TABLE tax_filing_periods ADD CONSTRAINT tax_filing_period_dates CHECK (ends_on >= starts_on)');
            DB::statement('ALTER TABLE carry_forwards ADD CONSTRAINT carry_forward_amounts CHECK (original_amount >= 0 AND amount_used >= 0 AND amount_remaining >= 0 AND amount_used + amount_remaining = original_amount)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carry_forwards');
        Schema::dropIfExists('prior_period_adjustments');
        Schema::dropIfExists('tax_filing_periods');
        Schema::dropIfExists('tax_obligations');
        Schema::dropIfExists('tax_years');
        foreach (['customer_receipts', 'sales_credit_notes', 'sales_invoices', 'sales_orders', 'sales_quotations'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->dropConstrainedForeignId('financial_year_id'));
        }
        Schema::table('accounting_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropColumn('reopened_at');
        });
        Schema::table('financial_years', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropConstrainedForeignId('reopened_by');
            $table->dropConstrainedForeignId('filed_by');
            $table->dropColumn(['closed_at', 'reopened_at', 'filed_at']);
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['entity_type', 'country_id']);
            $table->dropColumn(['entity_type', 'individual_name', 'trading_name']);
        });
    }
};

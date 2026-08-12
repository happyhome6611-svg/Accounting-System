<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->char('code', 2)->unique();
            $table->string('name')->unique();
            $table->string('provider_key')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->char('code', 3)->unique();
            $table->string('name');
            $table->string('symbol', 8);
            $table->unsignedSmallInteger('decimal_places')->default(2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name');
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->foreignId('base_currency_id')->constrained('currencies')->restrictOnDelete();
            $table->string('timezone', 64);
            $table->json('accounting_configuration')->default('{}');
            $table->json('tax_configuration')->default('{}');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('company_user', function (Blueprint $table) {
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 32)->default('member');
            $table->timestamps();
            $table->primary(['company_id', 'user_id']);
        });
        Schema::create('financial_years', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false);
            $table->string('status', 16)->default('open');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('financial_year_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('open');
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 24);
            $table->string('normal_balance', 6);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 50);
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'auditable_type', 'auditable_id']);
        });
        Schema::create('country_tax_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->restrictOnDelete();
            $table->string('key');
            $table->string('name');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->json('configuration')->default('{}');
            $table->timestamps();
            $table->unique(['country_id', 'key', 'effective_from']);
        });
        Schema::create('country_tax_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_tax_rule_id')->constrained()->restrictOnDelete();
            $table->string('code');
            $table->decimal('rate', 9, 6);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['country_tax_rule_id', 'code', 'effective_from']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE financial_years ADD CONSTRAINT financial_year_dates CHECK (ends_on >= starts_on)');
            DB::statement('ALTER TABLE accounting_periods ADD CONSTRAINT accounting_period_dates CHECK (ends_on >= starts_on)');
            DB::statement("ALTER TABLE accounts ADD CONSTRAINT account_normal_balance CHECK (normal_balance IN ('debit','credit'))");
            DB::statement('ALTER TABLE country_tax_rules ADD CONSTRAINT country_tax_rule_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)');
            DB::statement('ALTER TABLE country_tax_rates ADD CONSTRAINT country_tax_rate_dates CHECK (effective_to IS NULL OR effective_to >= effective_from)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('country_tax_rates');
        Schema::dropIfExists('country_tax_rules');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('financial_years');
        Schema::dropIfExists('company_user');
        Schema::dropIfExists('companies');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('countries');
    }
};

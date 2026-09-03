<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->decimal('purchase_price', 20, 4)->nullable();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->text('purchase_description')->nullable();
        });
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('type', 24)->default('business');
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('address')->nullable();
            $table->foreignId('country_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->unsignedInteger('payment_terms_days')->default(0);
            $table->decimal('credit_limit', 20, 4)->default(0);
            $table->foreignId('payable_account_id')->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active']);
        });
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $this->documentContext($table);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('purchase_order_number', 40);
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->string('supplier_reference')->nullable();
            $table->string('status', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 20, 4);
            $table->decimal('total', 20, 4);
            $this->audit($table);
            $table->unique(['company_id', 'purchase_order_number']);
            $table->index(['company_id', 'supplier_id', 'status']);
        });
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $this->line($table);
            $table->timestamps();
        });
        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $this->documentContext($table, true);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('bill_number', 40);
            $table->date('bill_date');
            $table->date('due_date');
            $table->string('supplier_reference')->nullable();
            $table->string('status', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 20, 4);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->decimal('total', 20, 4);
            $table->decimal('amount_paid', 20, 4)->default(0);
            $table->decimal('amount_credited', 20, 4)->default(0);
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $this->audit($table);
            $table->unique(['company_id', 'bill_number']);
            $table->index(['company_id', 'supplier_id', 'status', 'due_date']);
        });
        Schema::create('supplier_bill_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $this->line($table);
            $table->decimal('tax_amount', 20, 4)->default(0);
            $table->timestamps();
        });
        Schema::create('supplier_credits', function (Blueprint $table) {
            $table->id();
            $this->documentContext($table, true);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->string('credit_number', 40);
            $table->date('credit_date');
            $table->string('supplier_reference')->nullable();
            $table->string('status', 24)->default('draft');
            $table->text('notes')->nullable();
            $table->decimal('total', 20, 4);
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $this->audit($table);
            $table->unique(['company_id', 'credit_number']);
        });
        Schema::create('supplier_credit_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_credit_id')->constrained()->restrictOnDelete();
            $this->line($table);
            $table->timestamps();
        });
        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $this->documentContext($table, true);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('payment_number', 40);
            $table->date('payment_date');
            $table->foreignId('payment_account_id')->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('draft');
            $table->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $this->audit($table);
            $table->unique(['company_id', 'payment_number']);
        });
        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payment_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 20, 4);
            $table->timestamps();
            $table->unique(['supplier_payment_id', 'supplier_bill_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['suppliers.credit_limit', 'purchase_orders.total', 'supplier_bills.total', 'supplier_bills.amount_paid', 'supplier_bills.amount_credited', 'supplier_credits.total', 'supplier_payments.amount', 'supplier_payment_allocations.amount'] as $field) {
                [$table, $column] = explode('.', $field);
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_nonnegative CHECK ({$column} >= 0)");
            }
        }
    }

    private function documentContext(Blueprint $table, bool $period = false): void
    {
        $table->foreignId('company_id')->constrained()->restrictOnDelete();
        $table->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
        $table->foreignId('financial_year_id')->constrained()->restrictOnDelete();
        if ($period) {
            $table->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
        }
        $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
    }

    private function line(Blueprint $table): void
    {
        $table->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
        $table->foreignId('expense_account_id')->constrained('accounts')->restrictOnDelete();
        $table->text('description');
        $table->decimal('quantity', 20, 4);
        $table->decimal('unit_price', 20, 4);
        $table->decimal('discount', 20, 4)->default(0);
        $table->decimal('line_amount', 20, 4);
    }

    private function audit(Blueprint $table): void
    {
        $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
        $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
        $table->timestamps();
    }

    public function down(): void
    {
        foreach (['supplier_payment_allocations', 'supplier_payments', 'supplier_credit_lines', 'supplier_credits', 'supplier_bill_lines', 'supplier_bills', 'purchase_order_lines', 'purchase_orders', 'suppliers'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('items', fn (Blueprint $table) => $table->dropConstrainedForeignId('expense_account_id'));
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn(['purchase_price', 'purchase_description']));
    }
};

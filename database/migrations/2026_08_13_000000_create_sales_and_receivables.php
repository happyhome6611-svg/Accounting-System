<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->cascadeOnDelete();
            $t->string('document_type', 32);
            $t->string('prefix', 12);
            $t->unsignedBigInteger('next_number')->default(1);
            $t->timestamps();
            $t->unique(['company_id', 'document_type']);
        });
        Schema::create('customers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name');
            $t->string('legal_name')->nullable();
            $t->string('type', 24)->default('business');
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->text('billing_address')->nullable();
            $t->text('shipping_address')->nullable();
            $t->foreignId('country_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $t->json('tax_identifiers')->default('{}');
            $t->unsignedInteger('payment_terms_days')->default(0);
            $t->decimal('credit_limit', 20, 4)->default(0);
            $t->foreignId('receivable_account_id')->constrained('accounts')->restrictOnDelete();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'code']);
            $t->index(['company_id', 'is_active']);
        });
        Schema::create('items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('code', 40);
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('type', 16);
            $t->string('unit', 20);
            $t->decimal('sales_price', 20, 4);
            $t->foreignId('revenue_account_id')->constrained('accounts')->restrictOnDelete();
            $t->string('tax_category')->nullable();
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['company_id', 'code']);
        });
        foreach (['sales_quotations' => ['quotation_number', 'quotation_date', 'expiry_date'], 'sales_orders' => ['order_number', 'order_date', null]] as $table => $fields) {
            Schema::create($table, function (Blueprint $t) use ($fields) {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->foreignId('customer_id')->constrained()->restrictOnDelete();
                $t->string($fields[0], 40);
                $t->date($fields[1]);
                if ($fields[2]) {
                    $t->date($fields[2])->nullable();
                }
                $t->string('status', 24)->default('draft');
                $t->string('customer_reference')->nullable();
                $t->text('notes')->nullable();
                $t->decimal('subtotal', 20, 4);
                $t->decimal('total', 20, 4);
                $t->foreignId('converted_invoice_id')->nullable();
                $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
                $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
                $t->timestamps();
                $t->unique(['company_id', $fields[0]]);
                $t->index(['company_id', 'customer_id', 'status']);
            });
        }
        foreach (['sales_quotation_lines' => 'sales_quotation_id', 'sales_order_lines' => 'sales_order_id'] as $table => $parent) {
            Schema::create($table, function (Blueprint $t) use ($parent) {
                $t->id();
                $t->foreignId($parent)->constrained()->cascadeOnDelete();
                $t->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
                $t->foreignId('revenue_account_id')->constrained('accounts')->restrictOnDelete();
                $t->text('description');
                $t->decimal('quantity', 20, 4);
                $t->decimal('unit_price', 20, 4);
                $t->decimal('discount', 20, 4)->default(0);
                $t->decimal('line_amount', 20, 4);
                $t->timestamps();
            });
        }
        Schema::create('sales_invoices', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $t->string('invoice_number', 40);
            $t->date('invoice_date');
            $t->date('due_date');
            $t->string('customer_reference')->nullable();
            $t->string('status', 24)->default('draft');
            $t->text('notes')->nullable();
            $t->decimal('subtotal', 20, 4);
            $t->decimal('tax_amount', 20, 4)->default(0);
            $t->decimal('total', 20, 4);
            $t->decimal('amount_paid', 20, 4)->default(0);
            $t->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'invoice_number']);
            $t->index(['company_id', 'customer_id', 'status', 'due_date']);
        });
        Schema::create('sales_invoice_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('item_id')->nullable()->constrained('items')->restrictOnDelete();
            $t->foreignId('revenue_account_id')->constrained('accounts')->restrictOnDelete();
            $t->text('description');
            $t->decimal('quantity', 20, 4);
            $t->decimal('unit_price', 20, 4);
            $t->decimal('discount', 20, 4)->default(0);
            $t->decimal('tax_amount', 20, 4)->default(0);
            $t->decimal('line_amount', 20, 4);
            $t->timestamps();
        });
        Schema::create('sales_credit_notes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('sales_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $t->string('credit_note_number', 40);
            $t->date('credit_note_date');
            $t->string('status', 16)->default('draft');
            $t->text('notes')->nullable();
            $t->decimal('total', 20, 4);
            $t->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'credit_note_number']);
        });
        Schema::create('sales_credit_note_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sales_credit_note_id')->constrained()->restrictOnDelete();
            $t->foreignId('revenue_account_id')->constrained('accounts')->restrictOnDelete();
            $t->text('description');
            $t->decimal('quantity', 20, 4);
            $t->decimal('unit_price', 20, 4);
            $t->decimal('line_amount', 20, 4);
            $t->timestamps();
        });
        Schema::create('customer_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('customer_id')->constrained()->restrictOnDelete();
            $t->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $t->string('receipt_number', 40);
            $t->date('receipt_date');
            $t->decimal('amount', 20, 4);
            $t->string('payment_method', 32);
            $t->string('reference')->nullable();
            $t->foreignId('receiving_account_id')->constrained('accounts')->restrictOnDelete();
            $t->string('status', 16)->default('draft');
            $t->foreignId('journal_entry_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'receipt_number']);
        });
        Schema::create('customer_receipt_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('customer_receipt_id')->constrained()->restrictOnDelete();
            $t->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 20, 4);
            $t->timestamps();
            $t->unique(['customer_receipt_id', 'sales_invoice_id']);
        });
        if (DB::getDriverName() === 'pgsql') {
            foreach (['customers.credit_limit', 'items.sales_price', 'sales_invoices.total', 'sales_invoices.amount_paid', 'sales_credit_notes.total', 'customer_receipts.amount'] as $field) {
                [$table, $column] = explode('.', $field);
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_nonnegative CHECK ({$column} >= 0)");
            }
        }
    }

    public function down(): void
    {
        foreach (['customer_receipt_allocations', 'customer_receipts', 'sales_credit_note_lines', 'sales_credit_notes', 'sales_invoice_lines', 'sales_invoices', 'sales_order_lines', 'sales_quotation_lines', 'sales_orders', 'sales_quotations', 'items', 'customers', 'document_sequences'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

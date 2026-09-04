<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('ledger_account_id')->constrained('accounts')->restrictOnDelete();
            $t->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $t->string('name');
            $t->string('type', 24);
            $t->string('bank_name')->nullable();
            $t->string('account_identifier')->nullable();
            $t->string('bank_branch')->nullable();
            $t->date('opening_date')->nullable();
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->unique(['company_id', 'ledger_account_id']);
            $t->index(['company_id', 'type', 'is_active']);
        });
        Schema::create('banking_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('branch_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('financial_year_id')->constrained()->restrictOnDelete();
            $t->foreignId('accounting_period_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('destination_bank_account_id')->nullable()->constrained('bank_accounts')->restrictOnDelete();
            $t->foreignId('counterparty_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $t->foreignId('journal_entry_id')->unique()->constrained()->restrictOnDelete();
            $t->string('type', 32);
            $t->date('transaction_date');
            $t->decimal('amount', 20, 4);
            $t->string('reference')->nullable();
            $t->text('description');
            $t->string('status', 16)->default('posted');
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['company_id', 'bank_account_id', 'transaction_date']);
        });
        Schema::create('bank_statement_imports', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $t->string('file_name');
            $t->string('file_hash', 64);
            $t->unsignedInteger('row_count')->default(0);
            $t->unsignedInteger('imported_count')->default(0);
            $t->unsignedInteger('duplicate_count')->default(0);
            $t->unsignedInteger('error_count')->default(0);
            $t->string('status', 16);
            $t->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('imported_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'bank_account_id']);
        });
        Schema::create('bank_statement_transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_statement_import_id')->constrained()->restrictOnDelete();
            $t->date('transaction_date');
            $t->text('description');
            $t->string('reference')->nullable();
            $t->decimal('money_in', 20, 4)->default(0);
            $t->decimal('money_out', 20, 4)->default(0);
            $t->string('fingerprint', 64);
            $t->string('status', 16)->default('unmatched');
            $t->timestamps();
            $t->index(['company_id', 'bank_account_id', 'transaction_date']);
            $t->unique(['bank_account_id', 'fingerprint']);
        });
        Schema::create('bank_transaction_matches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_statement_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('journal_line_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignId('matched_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('matched_at');
            $t->timestamps();
        });
        Schema::create('bank_reconciliations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_account_id')->constrained()->restrictOnDelete();
            $t->date('statement_start_date')->nullable();
            $t->date('statement_end_date');
            $t->decimal('statement_closing_balance', 20, 4);
            $t->decimal('book_balance', 20, 4);
            $t->decimal('reconciled_balance', 20, 4);
            $t->decimal('difference', 20, 4);
            $t->string('status', 16)->default('draft');
            $t->foreignId('prepared_by')->constrained('users')->restrictOnDelete();
            $t->timestamp('prepared_at');
            $t->timestamp('completed_at')->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'bank_account_id', 'statement_end_date']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE bank_accounts ADD CONSTRAINT bank_accounts_type_check CHECK (type IN ('bank','cash','credit_card','other_cash_equivalent'))");
            DB::statement("ALTER TABLE banking_transactions ADD CONSTRAINT banking_transactions_type_check CHECK (type IN ('transfer','bank_fee','interest_received','interest_paid','direct_expense','direct_income'))");
            DB::statement('ALTER TABLE banking_transactions ADD CONSTRAINT banking_transactions_amount_check CHECK (amount > 0)');
            DB::statement("ALTER TABLE bank_statement_imports ADD CONSTRAINT bank_statement_imports_status_check CHECK (status IN ('preview','imported','failed'))");
            DB::statement("ALTER TABLE bank_statement_transactions ADD CONSTRAINT bank_statement_transactions_status_check CHECK (status IN ('unmatched','matched','excluded'))");
            DB::statement('ALTER TABLE bank_statement_transactions ADD CONSTRAINT bank_statement_amount_check CHECK ((money_in > 0 AND money_out = 0) OR (money_out > 0 AND money_in = 0))');
            DB::statement("ALTER TABLE bank_reconciliations ADD CONSTRAINT bank_reconciliations_status_check CHECK (status IN ('draft','completed'))");
        }
    }

    public function down(): void
    {
        foreach (['bank_reconciliations', 'bank_transaction_matches', 'bank_statement_transactions', 'bank_statement_imports', 'banking_transactions', 'bank_accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

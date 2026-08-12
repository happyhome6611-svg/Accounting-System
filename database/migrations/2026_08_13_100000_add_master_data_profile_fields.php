<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->text('address')->nullable()->after('timezone');
            $table->string('email')->nullable()->after('address');
            $table->string('phone', 40)->nullable()->after('email');
            $table->json('registration_identifiers')->default('{}')->after('phone');
            $table->boolean('is_active')->default(true)->after('registration_identifiers');
        });
        Schema::table('sales_credit_note_lines', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->after('sales_credit_note_id')->constrained('items')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales_credit_note_lines', fn (Blueprint $table) => $table->dropConstrainedForeignId('item_id'));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['address', 'email', 'phone', 'registration_identifiers', 'is_active']));
    }
};

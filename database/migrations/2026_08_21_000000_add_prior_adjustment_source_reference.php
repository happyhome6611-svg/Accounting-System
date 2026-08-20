<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prior_period_adjustments', function (Blueprint $table) {
            $table->string('source_reference')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('prior_period_adjustments', function (Blueprint $table) {
            $table->dropColumn('source_reference');
        });
    }
};

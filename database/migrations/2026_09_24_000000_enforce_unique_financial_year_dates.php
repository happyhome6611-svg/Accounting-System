<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('financial_years')
            ->select('company_id', 'starts_on', 'ends_on', DB::raw('COUNT(*) AS duplicate_count'))
            ->groupBy('company_id', 'starts_on', 'ends_on')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($duplicates) {
            throw new RuntimeException('Duplicate Financial Year date ranges exist. Review their accounting dependencies before applying this migration.');
        }

        Schema::table('financial_years', function (Blueprint $table) {
            $table->unique(['company_id', 'starts_on', 'ends_on'], 'financial_years_company_dates_unique');
        });
    }

    public function down(): void
    {
        Schema::table('financial_years', function (Blueprint $table) {
            $table->dropUnique('financial_years_company_dates_unique');
        });
    }
};

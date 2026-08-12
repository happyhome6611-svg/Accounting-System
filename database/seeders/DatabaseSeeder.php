<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        DB::table('countries')->upsert([
            ['code' => 'IN', 'name' => 'India', 'provider_key' => 'india', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], ['code' => 'NZ', 'name' => 'New Zealand', 'provider_key' => 'new-zealand', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], ['code' => 'AU', 'name' => 'Australia', 'provider_key' => 'australia', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], ['code' => 'GB', 'name' => 'United Kingdom', 'provider_key' => 'united-kingdom', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], ['code' => 'SG', 'name' => 'Singapore', 'provider_key' => 'singapore', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]], ['code'], ['name', 'provider_key', 'is_active', 'updated_at']);
        DB::table('currencies')->upsert(array_map(fn ($c) => ['code' => $c[0], 'name' => $c[1], 'symbol' => $c[2], 'decimal_places' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], [['INR', 'Indian Rupee', '₹'], ['NZD', 'New Zealand Dollar', '$'], ['AUD', 'Australian Dollar', '$'], ['GBP', 'Pound Sterling', '£'], ['SGD', 'Singapore Dollar', '$'], ['USD', 'US Dollar', '$'], ['EUR', 'Euro', '€']]), ['code'], ['name', 'symbol', 'decimal_places', 'is_active', 'updated_at']);
    }
}

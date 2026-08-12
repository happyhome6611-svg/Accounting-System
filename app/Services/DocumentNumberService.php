<?php

namespace App\Services;

use App\Models\Company;
use Illuminate\Support\Facades\DB;

final class DocumentNumberService
{
    public function next(Company $company, string $type, string $prefix): string
    {
        return DB::transaction(function () use ($company, $type, $prefix) {
            $row = DB::table('document_sequences')->where('company_id', $company->id)->where('document_type', $type)->lockForUpdate()->first();
            if (! $row) {
                DB::table('document_sequences')->insert(['company_id' => $company->id, 'document_type' => $type, 'prefix' => $prefix, 'next_number' => 2, 'created_at' => now(), 'updated_at' => now()]);
                $number = 1;
            } else {
                $number = $row->next_number;
                DB::table('document_sequences')->where('id', $row->id)->update(['next_number' => $number + 1, 'updated_at' => now()]);
            }

return $prefix.'-'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
        });
    }
}

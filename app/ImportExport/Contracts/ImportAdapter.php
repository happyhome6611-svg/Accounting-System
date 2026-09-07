<?php

namespace App\ImportExport\Contracts;

use App\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

interface ImportAdapter
{
    public function type(): string;

    /** @return array<string, array{label:string,required:bool,aliases:array}> */
    public function fields(): array;

    /** @return array{errors:array,warnings:array,duplicate:string} */
    public function validate(Company $company, array $values, array $options): array;

    public function import(Company $company, array $values, array $options, User $user): Model;
}

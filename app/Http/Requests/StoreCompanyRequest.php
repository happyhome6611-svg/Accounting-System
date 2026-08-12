<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:255'], 'legal_name' => ['required', 'string', 'max:255'], 'country_id' => ['required', 'exists:countries,id'], 'base_currency_id' => ['required', 'exists:currencies,id'], 'timezone' => ['required', 'timezone:all'], 'financial_year_start' => ['required', 'date'], 'financial_year_end' => ['required', 'date', 'after:financial_year_start']];
    }
}

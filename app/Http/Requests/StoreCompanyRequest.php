<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['entity_type' => $this->input('entity_type', 'company')]);
        if (in_array($this->input('entity_type'), ['individual', 'sole_trader'], true)) {
            $this->merge(['name' => $this->input('trading_name') ?: $this->input('individual_name'), 'legal_name' => $this->input('individual_name')]);
        }
    }

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['entity_type' => ['required', 'in:company,individual,sole_trader'], 'name' => ['required', 'string', 'max:255'], 'legal_name' => ['nullable', 'required_if:entity_type,company', 'string', 'max:255'], 'individual_name' => ['nullable', 'required_if:entity_type,individual,sole_trader', 'string', 'max:255'], 'trading_name' => ['nullable', 'required_if:entity_type,sole_trader', 'string', 'max:255'], 'country_id' => ['required', 'exists:countries,id'], 'base_currency_id' => ['required', 'exists:currencies,id'], 'timezone' => ['required', 'timezone:all'], 'financial_year_start' => ['required', 'date'], 'financial_year_end' => ['required', 'date', 'after:financial_year_start']];
    }
}

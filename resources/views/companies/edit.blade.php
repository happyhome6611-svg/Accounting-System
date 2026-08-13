@extends('layouts.app')
@section('content')
<h1>Edit Company</h1>
<div class="card p-4"><form method="post" action="{{ route('companies.update', $company) }}" class="row g-3">@csrf @method('PUT')
@foreach(['name' => 'Company Name', 'legal_name' => 'Legal / Business Name', 'email' => 'Email', 'phone' => 'Phone', 'timezone' => 'Timezone'] as $field => $label)<div class="col-md-6"><label class="form-label">{{ $label }}</label><input class="form-control" name="{{ $field }}" value="{{ old($field, $company->{$field}) }}" @required(in_array($field, ['name', 'legal_name', 'timezone']))></div>@endforeach
<div class="col-12"><label class="form-label">Address</label><textarea class="form-control" name="address">{{ old('address', $company->address) }}</textarea></div>
<div class="col-md-6"><label class="form-label">Country</label><select class="form-select" name="country_id">@foreach($countries as $country)<option value="{{ $country->id }}" @selected($company->country_id === $country->id)>{{ $country->name }}</option>@endforeach</select></div>
<div class="col-md-6"><label class="form-label">Base Currency</label><select class="form-select" name="base_currency_id">@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected($company->base_currency_id === $currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</select></div>
<div class="col-12 alert alert-info">Country and base currency cannot be changed after accounting or business activity exists.</div>
<div class="d-flex gap-2"><a class="btn btn-secondary" href="{{ route('companies.show', $company) }}">Cancel</a><button class="btn btn-primary">Save Changes</button></div>
</form></div>
@endsection

@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center"><div><h1>Accounting Jurisdictions</h1><p class="text-muted mb-0">Select a country or tax jurisdiction to manage its accounting entities.</p></div></div>
@if(session('success'))<div class="alert alert-success mt-3">{{ session('success') }}</div>@endif
<div class="row mt-3 g-3">
@foreach($countries as $country)
<div class="col-md-6 col-xl-4"><div class="card p-4 h-100"><h4>{{ $country->name }}</h4><div class="text-muted">{{ $country->accessible_entities_count }} {{ Str::plural('Accounting Entity', $country->accessible_entities_count) }}</div>@if($country->default_currency_code)<div class="mt-2">Common currency: <strong>{{ $country->default_currency_code }}</strong></div>@endif<div class="mt-4 d-flex gap-2"><a class="btn btn-primary" href="{{ route('companies.country', $country->code) }}">View Entities</a>@if($country->accessible_entities_count===0)<a class="btn btn-outline-primary" href="{{ route('companies.country.create', $country->code) }}">Create first entity</a>@endif</div></div></div>
@endforeach
</div>
@endsection

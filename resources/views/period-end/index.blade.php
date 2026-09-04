@extends('layouts.app')
@section('content')
<h1>Period-End & Accountant Controls</h1><p class="text-muted">Select a Country / Tax Jurisdiction.</p>
<div class="row g-3">@forelse($countries as $country)<div class="col-md-4"><div class="card p-4 h-100"><h4>{{ $country->name }}</h4><p>{{ $country->accessible_entities_count }} accounting {{ Str::plural('entity', $country->accessible_entities_count) }}</p><a class="btn btn-primary" href="{{ route('period-end.country', $country->code) }}">Open Period-End</a></div></div>@empty<div class="alert alert-info">No accessible jurisdictions.</div>@endforelse</div>
@endsection

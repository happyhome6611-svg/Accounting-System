@extends('layouts.app')
@section('content')
<nav class="mb-3"><a href="{{ route('period-end') }}">Period-End</a> &gt; {{ $country->name }}</nav><h1>Period-End — {{ $country->name }}</h1>
<div class="row g-3">@forelse($companies as $company)<div class="col-md-6"><div class="card p-4"><h4>{{ $company->entity_label }}</h4><a class="btn btn-primary" href="{{ route('period-end.entity', [$country->code, $company]) }}">Select Financial Year</a></div></div>@empty<div class="alert alert-info">No accessible entities.</div>@endforelse</div>
@endsection

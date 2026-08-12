@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center"><h1>Companies</h1><a class="btn btn-primary" href="{{ route('companies.create') }}">New company</a></div>
@if(session('success'))<div class="alert alert-success mt-3">{{ session('success') }}</div>@endif
<div class="row mt-3 g-3">@forelse($companies as $company)<div class="col-lg-4"><div class="card p-4 h-100"><h4><a class="text-decoration-none" href="{{ route('companies.show',$company) }}">{{ $company->name }}</a></h4><div>{{ $company->country->name }} · {{ $company->baseCurrency->code }}</div>@if($deletable[$company->id])<div class="border-top mt-4 pt-3"><a class="btn btn-sm btn-outline-danger" href="{{ route('companies.delete',$company) }}">Delete Company</a></div>@endif</div></div>@empty<p>No companies yet.</p>@endforelse</div>
@endsection

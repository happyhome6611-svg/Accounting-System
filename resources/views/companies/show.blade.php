@extends('layouts.app')

@section('content')
    <h1>{{ config('app.name') }}</h1>
    <div class="card p-4">
        <h2>{{ $company->entity_label }}</h2>
        <dl class="row mb-0">
            <dt class="col-sm-3">Entity Type</dt><dd class="col-sm-9">{{ ucfirst(str_replace('_', ' ', $company->entity_type)) }}</dd>
            <dt class="col-sm-3">Country</dt><dd class="col-sm-9">{{ $company->country->name }}</dd>
            <dt class="col-sm-3">Base Currency</dt><dd class="col-sm-9">{{ $company->baseCurrency->code }}</dd>
            <dt class="col-sm-3">Financial Year</dt>
            <dd class="col-sm-9">
                @if ($company->financialYears->first())
                    {{ $company->financialYears->first()->starts_on->format('d M Y') }} – {{ $company->financialYears->first()->ends_on->format('d M Y') }}
                @endif
            </dd>
            <dt class="col-sm-3">Chart of Accounts</dt><dd class="col-sm-9">{{ $company->accounts->count() }} foundation accounts</dd>
        </dl>
    </div>
    <div class="mt-3 d-flex gap-2">@if($company->supportsBranches())<a class="btn btn-outline-primary" href="{{ route('companies.branches.index', $company) }}">Manage Branches</a>@endif<a class="btn btn-outline-primary" href="{{ route('companies.financial-years.index', $company) }}">Manage Financial Years</a></div>
@endsection

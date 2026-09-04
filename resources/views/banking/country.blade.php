@extends('layouts.app')
@section('content')
<nav class="mb-3"><a href="{{route('banking')}}">Banking</a> &gt; {{$country->name}}</nav><h1>Banking — {{$country->name}}</h1><div class="row g-3">@forelse($companies as $company)<div class="col-md-6"><div class="card p-4"><h4>{{$company->entity_label}}</h4><div class="text-muted mb-3">{{$company->country->name}} · {{$company->baseCurrency->code}}</div><a class="btn btn-primary" href="{{route('banking.accounts',[$country->code,$company])}}">Bank & Cash Accounts</a></div></div>@empty<div class="alert alert-info">No accessible accounting entities in this jurisdiction.</div>@endforelse</div>
@endsection

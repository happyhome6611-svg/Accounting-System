@extends('layouts.app')
@section('content')
<h1>Banking & Cash Management</h1><p class="text-muted">Select a Country / Tax Jurisdiction to manage banking.</p><div class="row g-3">@forelse($countries as $country)<div class="col-md-6 col-xl-4"><div class="card p-4 h-100"><h4>{{$country->name}}</h4><p class="text-muted">{{$country->accessible_entities_count}} accessible accounting {{Str::plural('entity',$country->accessible_entities_count)}}</p><a class="btn btn-primary" href="{{route('banking.country',$country->code)}}">Open Banking</a></div></div>@empty<div class="alert alert-info">No accessible accounting jurisdictions.</div>@endforelse</div>
@endsection

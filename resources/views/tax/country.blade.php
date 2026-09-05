@extends('layouts.app')
@section('content')
<nav><a href="{{route('tax')}}">Tax</a> &gt; {{$country->name}}</nav><h1 class="mt-3">{{$country->name}} Tax Entities</h1>
<div class="row g-3">@foreach($companies as $company)<div class="col-md-4"><div class="card p-4"><h4>{{$company->entity_label}}</h4><a class="btn btn-primary" href="{{route('tax.workspace',[$country->code,$company])}}">Open Tax Workspace</a></div></div>@endforeach</div>
@endsection

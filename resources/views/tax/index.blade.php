@extends('layouts.app')
@section('content')
<h1>Tax</h1><p class="text-muted">Select a Country / Tax Jurisdiction. Tax rates and treatments are configuration-driven; this is not a country tax return.</p>
<div class="row g-3">@foreach($countries as $country)<div class="col-md-4"><div class="card p-4"><h4>{{$country->name}}</h4><a class="btn btn-primary" href="{{route('tax.country',$country->code)}}">Open Tax</a></div></div>@endforeach</div>
@endsection

@extends('layouts.app')
@section('title', 'Import & Export')
@section('content')
<h1>Import & Export</h1>
<p class="text-muted">Choose a Country / Tax Jurisdiction. Import and export access remains entity-scoped.</p>
<div class="row g-3">@foreach($countries as $country)<div class="col-md-4"><a class="card card-body text-decoration-none" href="{{ route('import-export.country', $country->code) }}"><strong>{{ $country->name }}</strong><span>{{ $country->accessible_entities_count }} Accounting Entities</span></a></div>@endforeach</div>
@endsection

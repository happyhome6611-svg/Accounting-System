@extends('layouts.app')
@section('title', 'Import & Export')
@section('content')
<h1>Import & Export</h1>
<div class="row g-3 mb-4"><div class="col-md-6"><div class="card card-body h-100"><h2 class="h5">Import New Accounting Entity</h2><p class="mb-0">Create a Company, Sole Trader, or Individual from CSV or XLSX.</p></div></div><div class="col-md-6"><div class="card card-body h-100"><h2 class="h5">Import / Export Existing Entity Data</h2><p class="mb-0">Manage data belonging to an entity already in Arua.</p></div></div></div>
<h2 class="h4">Country / Tax Jurisdiction</h2>
<div class="row g-3">@foreach($countries as $country)<div class="col-md-4"><a class="card card-body text-decoration-none" href="{{ route('import-export.country', $country->code) }}"><strong>{{ $country->name }}</strong><span>{{ $country->accessible_entities_count }} Accounting Entities</span></a></div>@endforeach</div>
@endsection

@extends('layouts.app')
@section('title', 'Import & Export')
@section('content')
<h1>Import & Export</h1>
<p class="text-muted">Create an Accounting Entity from an external file, or manage data belonging to an existing entity.</p>
<div class="row g-3 mb-4"><div class="col-md-6"><div class="card card-body h-100 border-primary"><h2 class="h5">Import New Accounting Entity</h2><p>Create a Company, Sole Trader, or Individual that does not yet exist. Choose its jurisdiction below to begin.</p><a class="btn btn-primary align-self-start" href="#jurisdictions">Choose Jurisdiction</a></div></div><div class="col-md-6"><div class="card card-body h-100"><h2 class="h5">Import Data Into Existing Entity</h2><p>Select a jurisdiction and use <strong>Open Import & Export</strong> on an existing entity.</p><a class="btn btn-outline-primary align-self-start" href="#jurisdictions">Choose Existing Entity</a></div></div></div>
<h2 id="jurisdictions" class="h4">Country / Tax Jurisdiction</h2>
<div class="row g-3">@foreach($countries as $country)<div class="col-md-4"><a class="card card-body text-decoration-none" href="{{ route('import-export.country', $country->code) }}"><strong>{{ $country->name }}</strong><span>{{ $country->accessible_entities_count }} Accounting Entities</span></a></div>@endforeach</div>
@endsection

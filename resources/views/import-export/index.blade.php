@extends('layouts.app')
@section('title', 'Import & Export')
@section('content')
<h1>Import & Export</h1>
<p class="text-muted">Create an Accounting Entity from an external file, or manage data belonging to an existing entity.</p>
<div class="row g-3 mb-4"><div class="col-md-6"><div class="card card-body h-100 border-primary"><h2 class="h5">Import New Accounting Entity</h2><p>Create a Company, Sole Trader, or Individual that does not yet exist.</p><a class="btn btn-primary align-self-start" href="{{ route('import-export', ['mode' => 'new']) }}">Choose Jurisdiction</a></div></div><div class="col-md-6"><div class="card card-body h-100"><h2 class="h5">Import Data Into Existing Entity</h2><p>Choose a jurisdiction, then select an existing entity.</p><a class="btn btn-outline-primary align-self-start" href="{{ route('import-export', ['mode' => 'existing']) }}">Choose Existing Entity</a></div></div></div>
@if($mode)
<h2 id="jurisdictions" class="h4">{{ $mode === 'new' ? 'Choose Jurisdiction for New Entity' : 'Choose Jurisdiction for Existing Entity' }}</h2>
<div class="row g-3">@foreach($countries as $country)<div class="col-md-4"><a class="card card-body text-decoration-none" href="{{ $mode === 'new' ? route('import-export.entity-imports.create', $country->code) : route('import-export.country', $country->code) }}"><strong>{{ $country->name }}</strong>@if($mode === 'existing')<span>{{ $country->accessible_entities_count }} Accounting Entities</span>@else<span>Upload Accounting Entity file</span>@endif</a></div>@endforeach</div>
@endif
@endsection

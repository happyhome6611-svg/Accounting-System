@extends('layouts.app')
@section('title', 'Import & Export – '.$country->name)
@section('content')
<h1>{{ $country->name }} Import & Export</h1>
<p class="text-muted">Select the Accounting Entity whose data you want to manage.</p>
<div class="row g-3">@forelse($companies as $company)<div class="col-md-4"><a class="card card-body text-decoration-none" href="{{ route('import-export.workspace', [$country->code, $company]) }}"><strong>{{ $company->entity_label }}</strong><span>{{ str($company->entity_type)->replace('_', ' ')->title() }}</span></a></div>@empty<p>No accessible Accounting Entities.</p>@endforelse</div>
@endsection

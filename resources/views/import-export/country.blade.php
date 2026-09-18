@extends('layouts.app')
@section('title', 'Import & Export – '.$country->name)
@section('content')
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="{{ route('import-export') }}">Import & Export</a></li><li class="breadcrumb-item active">{{ $country->name }}</li></ol></nav>
<h1>{{ $country->name }} Import & Export</h1>
<p class="text-muted">Select the Accounting Entity whose data you want to import or export.</p>
<div class="row g-3">
    @forelse($companies as $company)
        <div class="col-md-6 col-xl-4">
            <div class="card h-100 shadow-sm">
                <div class="card-body d-flex flex-column">
                    <h2 class="h5">{{ $company->entity_label }}</h2>
                    <p class="text-muted mb-4">{{ str($company->entity_type)->replace('_', ' ')->title() }} · {{ $country->name }}</p>
                    <a class="btn btn-primary mt-auto stretched-link" href="{{ route('import-export.workspace', [$country->code, $company]) }}" aria-label="Open Import and Export for {{ $company->entity_label }}">Open Import & Export</a>
                </div>
            </div>
        </div>
    @empty
        <div class="col"><div class="alert alert-info">No accessible Accounting Entities.</div></div>
    @endforelse
</div>
@endsection

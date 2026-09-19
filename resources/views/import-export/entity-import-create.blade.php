@extends('layouts.app')
@section('title', 'Import New Accounting Entity')
@section('content')
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="{{ route('import-export') }}">Import & Export</a></li><li class="breadcrumb-item"><a href="{{ route('import-export.country', $country->code) }}">{{ $country->name }}</a></li><li class="breadcrumb-item active">Import New Accounting Entity</li></ol></nav>
<h1>Import New Accounting Entity</h1><p class="text-muted">Selected jurisdiction: <strong>{{ $country->name }}</strong>. The file may contain exactly one Company, Sole Trader, or Individual.</p>
<div class="alert alert-info">Upload a CSV or XLSX file containing the Accounting Entity details. Arua-formatted files go directly to preview.</div>
<div class="card card-body"><form method="post" enctype="multipart/form-data" action="{{ route('import-export.entity-imports.upload', $country->code) }}">@csrf<label class="form-label" for="entity-file">Accounting Entity File</label><input id="entity-file" class="form-control" type="file" name="file" accept=".csv,.xlsx" required><div class="form-text">Supported formats: CSV / XLSX. One entity row per file in v0.8.</div><div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Upload / Continue</button><a class="btn btn-outline-secondary" href="{{ route('import-export.country', $country->code) }}">Cancel</a></div></form></div>
@endsection

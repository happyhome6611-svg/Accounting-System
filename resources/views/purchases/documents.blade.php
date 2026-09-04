@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between"><h1>{{ $title }} — {{ $company->entity_label }}</h1><a class="btn btn-primary" href="{{ route('purchases.documents.create', [$company, $type]) }}">New</a></div>
    <div class="card table-responsive mt-3"><table class="table mb-0"><thead><tr><th>Number</th><th>Date</th><th>Supplier</th><th>Total</th><th>Status</th></tr></thead><tbody>
        @foreach ($documents as $document)
            <tr><td><a href="{{ route('purchases.documents.show', [$company, $type, $document]) }}">{{ $document->getAttribute($number) }}</a></td><td>{{ $document->getAttribute($date)->format('d M Y') }}</td><td>{{ $document->supplier->name }}</td><td>{{ $money->format($document->total ?? $document->amount, $company->baseCurrency) }}</td><td>{{ ucfirst(str_replace('_', ' ', $document->status)) }}</td></tr>
        @endforeach
    </tbody></table></div>
@endsection

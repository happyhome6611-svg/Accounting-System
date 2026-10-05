@extends('layouts.app')

@section('content')
    <div class="d-flex justify-content-between"><div><h1>{{ $title }} — {{ $company->entity_label }}</h1><div class="text-muted">{{ $documents->count() }} documents</div></div><a class="btn btn-primary align-self-start" href="{{ route('purchases.documents.create', [$company, $type]) }}">New</a></div>
    <div class="card table-responsive mt-3"><table class="table mb-0"><thead><tr><th>Number</th>@if(in_array($type, ['orders','bills']))<th>Source Reference</th>@endif<th>Date</th><th>Supplier</th><th>Total</th><th>Status</th></tr></thead><tbody>
        @foreach ($documents as $document)
            <tr><td><a href="{{ route('purchases.documents.show', [$company, $type, $document]) }}">{{ $document->getAttribute($number) }}</a></td>@if(in_array($type, ['orders','bills']))<td>{{ $document->supplier_reference ?: '—' }}</td>@endif<td>{{ $document->getAttribute($date)->format('d M Y') }}</td><td>{{ $document->supplier->name }}</td><td>{{ $money->format($document->total ?? $document->amount, $company->baseCurrency) }}</td><td>{{ ucfirst(str_replace('_', ' ', $document->status)) }}</td></tr>
        @endforeach
    </tbody></table></div>
@endsection

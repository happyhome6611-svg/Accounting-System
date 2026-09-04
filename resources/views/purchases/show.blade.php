@extends('layouts.app')

@section('content')
    <h1>{{ Str::singular($title) }} {{ $document->getAttribute($number) }}</h1>
    @if (session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
    <div class="card p-4">
        <dl class="row"><dt class="col-3">Supplier</dt><dd class="col-9">{{ $document->supplier->name }}</dd><dt class="col-3">Date</dt><dd class="col-9">{{ $document->getAttribute($date)->format('d M Y') }}</dd><dt class="col-3">Currency</dt><dd class="col-9">{{ $money->label($company->baseCurrency) }}</dd><dt class="col-3">Status</dt><dd class="col-9">{{ ucfirst($document->status) }}</dd><dt class="col-3">Total</dt><dd class="col-9">{{ $money->format($document->total ?? $document->amount, $company->baseCurrency) }}</dd></dl>
        @if (method_exists($document, 'lines'))
            <table class="table"><thead><tr><th>Description</th><th>Quantity</th><th>Unit Price</th><th>Discount</th><th>Amount</th></tr></thead><tbody>@foreach ($document->lines as $line)<tr><td>{{ $line->description }}</td><td>{{ $line->quantity }}</td><td>{{ $line->unit_price }}</td><td>{{ $line->discount }}</td><td>{{ $line->line_amount }}</td></tr>@endforeach</tbody></table>
        @endif
        <div class="d-flex gap-2">
            @if ($document->status === 'draft')
                <a class="btn btn-outline-primary" href="{{ route('purchases.documents.edit', [$company, $type, $document]) }}">Edit Draft</a>
                @if (in_array($type, ['bills', 'credits', 'payments']))<form method="post" action="{{ route('purchases.documents.post', [$company, $type, $document]) }}">@csrf<button class="btn btn-primary">Post</button></form>@endif
                @if ($type === 'orders')<form method="post" action="{{ route('purchases.orders.convert', [$company, $document]) }}">@csrf<input type="hidden" name="bill_date" value="{{ today()->toDateString() }}"><input type="hidden" name="due_date" value="{{ today()->toDateString() }}"><button class="btn btn-primary">Convert to Supplier Bill</button></form>@endif
                <form method="post" action="{{ route('purchases.documents.destroy', [$company, $type, $document]) }}">@csrf @method('DELETE')<input type="hidden" name="confirmation" value="{{ $document->getAttribute($number) }}"><button class="btn btn-outline-danger">Delete Draft</button></form>
            @endif
        </div>
    </div>
@endsection

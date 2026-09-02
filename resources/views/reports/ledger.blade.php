@extends('layouts.app')
@section('content')
<h1 class="mb-3">General Ledger</h1>
@include('reports._navigation')
<p class="fs-5"><strong>Account:</strong> {{ $account->code }} — {{ $account->name }}</p>
<div class="table-responsive card">
<table class="table table-striped table-hover align-middle mb-0">
<thead class="table-light"><tr><th style="min-width:110px">Date</th><th style="min-width:130px">Journal</th><th style="min-width:130px">Reference</th><th style="min-width:260px">Description</th><th class="text-end" style="min-width:130px">Debit</th><th class="text-end" style="min-width:130px">Credit</th><th class="text-end" style="min-width:140px">Balance</th></tr></thead>
<tbody>@forelse($rows as $r)<tr><td>{{ \Carbon\CarbonImmutable::parse($r->transaction_date)->format('d M Y') }}</td><td>{{ $r->journal_number }}</td><td>{{ $r->reference ?: '—' }}</td><td>{{ $r->description }}</td><td class="text-end text-nowrap">{{ $money->format($r->debit,$currency) }}</td><td class="text-end text-nowrap">{{ $money->format($r->credit,$currency) }}</td><td class="text-end text-nowrap fw-semibold">{{ $money->format($r->running_balance,$currency) }}</td></tr>@empty<tr><td colspan="7" class="text-center text-muted py-4">No posted transactions for this period.</td></tr>@endforelse</tbody>
</table></div>
@endsection

@extends('layouts.app')

@section('content')
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="mb-1">Opening Balances</h1>
        <p class="text-muted mb-0">{{ $company->name }} · {{ $company->country->name }} · {{ $company->baseCurrency->code }}</p>
    </div>
    <a class="btn btn-outline-secondary" href="{{ route('accounting', ['country_id' => $company->country_id, 'company_id' => $company->id]) }}">Back to Accounting</a>
</div>

<div class="alert alert-info">These balances are staged only. They have not created General Ledger entries.</div>

<div class="card p-3 mb-3">
    <div class="row g-3 text-center">
        <div class="col-md-4"><div class="text-muted">Total Debit</div><strong>{{ $company->baseCurrency->code }} {{ number_format((float) $totalDebit, 2) }}</strong></div>
        <div class="col-md-4"><div class="text-muted">Total Credit</div><strong>{{ $company->baseCurrency->code }} {{ number_format((float) $totalCredit, 2) }}</strong></div>
        <div class="col-md-4"><div class="text-muted">Difference</div><strong>{{ $company->baseCurrency->code }} {{ number_format((float) bcsub($totalDebit, $totalCredit, 4), 2) }}</strong></div>
    </div>
</div>

<div class="card p-3">
    @forelse($stagings as $staging)
        <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
            <h2 class="h5 mb-0">{{ $staging->opening_reference }}</h2>
            <span class="badge text-bg-info">{{ strtoupper($staging->status) }}</span>
        </div>
        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead><tr><th>Date</th><th>Account</th><th>Account Name</th><th>Description</th><th>Branch</th><th>Import Batch</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                <tbody>
                    @foreach($staging->lines as $line)
                    <tr>
                        <td>{{ $staging->balance_date->format('d M Y') }}</td>
                        <td>{{ $line->account->code }}</td>
                        <td>{{ $line->account->name }}</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ $staging->branch?->name ?? 'Not applicable' }}</td>
                        <td>#{{ $staging->import_batch_id }} · {{ $staging->importBatch->original_filename }}</td>
                        <td class="text-end">{{ number_format((float) $line->debit, 2) }}</td>
                        <td class="text-end">{{ number_format((float) $line->credit, 2) }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @empty
        <p class="text-muted mb-0">No opening balances have been staged for this Accounting Entity.</p>
    @endforelse
</div>
@endsection

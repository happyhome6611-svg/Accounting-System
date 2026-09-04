@extends('layouts.app')

@section('content')
<nav class="mb-3">
    <a href="{{ route('banking') }}">Banking</a> &gt;
    <a href="{{ route('banking.country', $company->country->code) }}">{{ $company->country->name }}</a> &gt;
    <a href="{{ route('banking.accounts', [$company->country->code, $company]) }}">{{ $company->entity_label }}</a> &gt;
    {{ $account->name }} Register
</nav>
<h1>{{ $account->name }} Register</h1>
<div class="alert alert-info">Book transactions below come from the General Ledger. Imported bank statement rows do not change this balance.</div>
<div class="table-responsive">
    <table class="table">
        <thead><tr><th>Date</th><th>Reference</th><th>Description</th><th>Source</th><th>Money In</th><th>Money Out</th><th>Book / Ledger Balance</th><th>Statement Match</th></tr></thead>
        <tbody>
        @forelse($rows as $row)
            <tr><td>{{ $row->transaction_date }}</td><td>{{ $row->reference }}</td><td>{{ $row->description }}</td><td>{{ $row->source_type }}</td><td>{{ $money->format($row->money_in, $company->baseCurrency) }}</td><td>{{ $money->format($row->money_out, $company->baseCurrency) }}</td><td>{{ $money->format($row->balance, $company->baseCurrency) }}</td><td>{{ $row->reconciliation_status }}</td></tr>
        @empty
            <tr><td colspan="8" class="text-muted text-center">No posted book transactions.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection

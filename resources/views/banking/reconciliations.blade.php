@extends('layouts.app')

@section('content')
<h1>Reconciliation — {{ $account->name }}</h1>
<div class="alert alert-info">Statement Balance is entered from bank evidence. Book / Ledger Balance is derived from posted journals. Difference must be zero before completion.</div>
<form method="post" action="{{ route('banking.reconciliations.store', [$company->country->code, $company, $account]) }}" class="card p-3 mb-3">
    <div class="row g-2">@csrf
        <div class="col"><label class="form-label">Statement Start Date</label><input class="form-control" type="date" name="statement_start_date"></div>
        <div class="col"><label class="form-label">Statement End Date</label><input class="form-control" type="date" name="statement_end_date" required></div>
        <div class="col"><label class="form-label">Statement Closing Balance</label><input class="form-control" inputmode="decimal" name="statement_closing_balance" required></div>
        <div class="col align-self-end"><button class="btn btn-primary">Prepare</button></div>
    </div>
</form>
<div class="table-responsive"><table class="table"><thead><tr><th>Statement Date</th><th>Statement Balance</th><th>Book / Ledger Balance</th><th>Reconciled Balance</th><th>Difference</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($reconciliations as $reconciliation)
    <tr><td>{{ $reconciliation->statement_end_date->format('d M Y') }}</td><td>{{ $reconciliation->statement_closing_balance }}</td><td>{{ $reconciliation->book_balance }}</td><td>{{ $reconciliation->reconciled_balance }}</td><td>{{ $reconciliation->difference }}</td><td>{{ ucfirst($reconciliation->status) }}</td><td>@if($reconciliation->status === 'draft')<form method="post" action="{{ route('banking.reconciliations.complete', [$company->country->code, $company, $account, $reconciliation]) }}">@csrf<button class="btn btn-sm btn-primary">Complete</button></form>@endif</td></tr>
@empty
    <tr><td colspan="7" class="text-muted text-center">No reconciliations prepared.</td></tr>
@endforelse
</tbody></table></div>
@endsection

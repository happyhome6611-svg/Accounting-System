@extends('layouts.app')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="mb-0">{{ $journal->journal_number }} <span class="badge bg-secondary">{{ ucfirst($journal->status) }}</span></h1>
    @if($journal->status === 'draft' && ! $journal->reversal_of_id)<a class="btn btn-primary" href="{{ route('journals.edit', [$company, $journal]) }}">Edit Draft</a>@endif
</div>
@if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach @if($journal->status === 'draft')<li><a href="{{ route('journals.edit', [$company, $journal]) }}">Edit this Draft to correct it</a></li>@endif</ul></div>@endif
<div class="card p-4">
    <p>{{ $journal->transaction_date->format('d M Y') }} · {{ $journal->description }}</p>
    <table class="table"><thead><tr><th>Account</th><th>Description</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead><tbody>@foreach($journal->lines as $line)<tr><td>{{ $line->account->code }} — {{ $line->account->name }}</td><td>{{ $line->description }}</td><td class="text-end">{{ number_format((float) $line->debit, 2) }}</td><td class="text-end">{{ number_format((float) $line->credit, 2) }}</td></tr>@endforeach</tbody></table>
    @if($journal->status === 'draft')
        <form method="post" action="{{ route('journals.post', [$company, $journal]) }}" class="mb-3">@csrf<button class="btn btn-success">Post Journal</button></form>
        @if(! $journal->reversal_of_id)<form method="post" action="{{ route('journals.destroy', [$company, $journal]) }}" onsubmit="return confirm('Permanently delete this Draft journal and its lines?')">@csrf @method('DELETE')<label class="form-label">Type <strong>{{ $journal->journal_number }}</strong> to delete this Draft permanently</label><div class="d-flex gap-2"><input class="form-control" style="max-width:260px" name="confirmation" autocomplete="off" required><button class="btn btn-outline-danger">Delete Draft</button></div></form>@endif
    @elseif($journal->status === 'posted')
        <form method="post" action="{{ route('journals.reverse', [$company, $journal]) }}" class="row g-2 mt-2">@csrf<div class="col"><select name="accounting_period_id" class="form-select">@foreach($company->financialYears()->with('periods')->get() as $year) @foreach($year->periods->where('status', 'open') as $period)<option value="{{ $period->id }}">{{ $period->name }}</option>@endforeach @endforeach</select></div><div class="col"><input type="date" name="transaction_date" class="form-control" required></div><div class="col"><button class="btn btn-warning">Reverse Journal</button></div></form>
    @endif
</div>
@endsection

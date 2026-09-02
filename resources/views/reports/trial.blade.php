@extends('layouts.app')
@section('content')
<h1 class="mb-3">Trial Balance</h1>
@include('reports._navigation')
<div class="table-responsive card"><table class="table table-striped align-middle mb-0"><thead class="table-light"><tr><th style="width:15%">Code</th><th style="width:45%">Account</th><th class="text-end" style="width:20%">Debit</th><th class="text-end" style="width:20%">Credit</th></tr></thead><tbody>@foreach($report['rows'] as $r)<tr><td>{{ $r->code }}</td><td>{{ $r->name }}</td><td class="text-end text-nowrap">{{ $money->format($r->debit_balance,$currency) }}</td><td class="text-end text-nowrap">{{ $money->format($r->credit_balance,$currency) }}</td></tr>@endforeach</tbody><tfoot class="table-group-divider"><tr class="fw-bold"><th colspan="2">Total</th><th class="text-end text-nowrap">{{ $money->format($report['debit'],$currency) }}</th><th class="text-end text-nowrap">{{ $money->format($report['credit'],$currency) }}</th></tr></tfoot></table></div>
@endsection

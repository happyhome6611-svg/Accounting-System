@extends('layouts.app')
@section('content')
<h1 class="mb-3">Balance Sheet</h1>
@include('reports._navigation')
<div class="card p-4"><dl class="row mb-0 fs-5"><dt class="col-8 py-2">Assets</dt><dd class="col-4 text-end py-2">{{ $money->format($report['assets'],$currency) }}</dd><dt class="col-8 py-2">Liabilities</dt><dd class="col-4 text-end py-2">{{ $money->format($report['liabilities'],$currency) }}</dd><dt class="col-8 py-2">Equity</dt><dd class="col-4 text-end py-2">{{ $money->format($report['equity'],$currency) }}</dd><dt class="col-8 py-2">Current Earnings</dt><dd class="col-4 text-end py-2">{{ $money->format($report['earnings'],$currency) }}</dd><dt class="col-8 py-3 border-top">Liabilities & Equity</dt><dd class="col-4 text-end py-3 border-top fw-bold">{{ $money->format($report['liabilities_and_equity'],$currency) }}</dd></dl></div>
@endsection

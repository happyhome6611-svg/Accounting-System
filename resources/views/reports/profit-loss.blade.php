@extends('layouts.app')
@section('content')
<h1 class="mb-3">Profit & Loss</h1>
@include('reports._navigation')
@php($isLoss = bccomp($report['net'],'0',4) < 0)
<div class="card p-4"><dl class="row mb-0 fs-5"><dt class="col-8 py-2">Total Revenue</dt><dd class="col-4 text-end py-2">{{ $money->format($report['revenue'],$currency) }}</dd><dt class="col-8 py-2">Total Expenses</dt><dd class="col-4 text-end py-2">{{ $money->format($report['expenses'],$currency) }}</dd><dt class="col-8 py-3 border-top">{{ $isLoss ? 'Net Loss' : 'Net Profit' }}</dt><dd class="col-4 text-end py-3 border-top fw-bold {{ $isLoss ? 'text-danger' : 'text-success' }}">{{ $money->format($report['net'],$currency,2,$isLoss) }}</dd></dl></div>
@endsection

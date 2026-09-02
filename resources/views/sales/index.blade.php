@extends('layouts.app')
@section('content')
<h1>Sales & Accounts Receivable{{ $country ? ' — '.$country->name : '' }}</h1>
<form class="mb-3"><label class="form-label">Country / Jurisdiction</label><select class="form-select" name="country_id" onchange="this.form.submit()">@foreach($countries as $item)<option value="{{ $item->id }}" @selected($country?->id===$item->id)>{{ $item->name }}</option>@endforeach</select></form>
<div class="row g-3">@foreach($companies as $company)<div class="col-md-6"><div class="card p-4"><h4>{{ $company->name }}</h4><div class="d-flex flex-wrap gap-2"><a class="btn btn-primary" href="{{ route('sales.customers', $company) }}">Customers</a><a class="btn btn-primary" href="{{ route('sales.items', $company) }}">Products & Services</a>@foreach(['quotations'=>'Quotations','orders'=>'Sales Orders','invoices'=>'Sales Invoices','credit-notes'=>'Credit Notes','receipts'=>'Customer Receipts'] as $type=>$label)<a class="btn btn-primary" href="{{ route('sales.transactions.index', [$company, $type]) }}">{{ $label }}</a>@endforeach</div></div></div>@endforeach</div>
@endsection

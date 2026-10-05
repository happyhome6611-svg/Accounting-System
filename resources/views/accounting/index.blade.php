@extends('layouts.app')

@section('content')
<h1>Journal Entries{{ $country ? ' — '.$country->name : '' }}</h1>
<form class="row g-2 mb-3">
    <div class="col-md-4">
        <label class="form-label">Country / Jurisdiction</label>
        <select name="country_id" class="form-select" onchange="this.form.querySelector('[name=company_id]').remove();this.form.submit()">
            @foreach($countries as $item)
                <option value="{{ $item->id }}" @selected($country?->id === $item->id)>{{ $item->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Accounting Entity</label>
        <select name="company_id" class="form-select" onchange="this.form.submit()">
            @foreach($companies as $item)
                <option value="{{ $item->id }}" @selected($company?->id === $item->id)>{{ $item->name }}</option>
            @endforeach
        </select>
    </div>
    @if($company)
        <div class="col align-self-end d-flex gap-2">
            <a class="btn btn-primary" href="{{ route('journals.create', $company) }}">Create Journal</a>
            <a class="btn btn-outline-primary" href="{{ route('accounting.opening-balances', [$company->country->code, $company]) }}">Opening Balances</a>
        </div>
    @endif
</form>

@php
    $sortLabels = ['number' => 'Number', 'date' => 'Date', 'description' => 'Description', 'status' => 'Status'];
    $context = array_merge(request()->query(), ['country_id' => $country?->id, 'company_id' => $company?->id]);
@endphp
<div class="card p-3">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    @foreach($sortLabels as $field => $label)
                        @php
                            $active = $sort === $field;
                            $nextDirection = $active && $direction === 'asc' ? 'desc' : 'asc';
                            $indicator = $active ? ($direction === 'asc' ? '▲' : '▼') : '';
                        @endphp
                        <th scope="col" aria-sort="{{ $active ? ($direction === 'asc' ? 'ascending' : 'descending') : 'none' }}">
                            <a class="link-dark text-decoration-none" href="{{ route('accounting', array_merge($context, ['sort' => $field, 'direction' => $nextDirection])) }}" title="Sort by {{ $label }}" aria-label="Sort by {{ $label }}{{ $active ? ', currently '.$direction.'ending' : '' }}">
                                {{ $label }} <span aria-hidden="true">{{ $indicator }}</span>
                            </a>
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($journals as $journal)
                    <tr>
                        <td><a href="{{ route('journals.show', [$company, $journal]) }}">{{ $journal->journal_number }}</a></td>
                        <td>{{ $journal->transaction_date->format('d M Y') }}</td>
                        <td>{{ $journal->description }}</td>
                        <td>{{ ucfirst($journal->status) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endsection

@extends('layouts.app')
@section('content')
<h1>Edit Draft — {{ $journal->journal_number }}</h1>
@include('accounting.partials.form', ['action' => route('journals.update', [$company, $journal]), 'method' => 'PUT'])
@endsection

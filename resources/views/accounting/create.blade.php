@extends('layouts.app')
@section('content')
<h1>Create Journal — {{ $company->name }}</h1>
@include('accounting.partials.form', ['action' => route('journals.store', $company), 'method' => 'POST', 'journal' => null])
@endsection

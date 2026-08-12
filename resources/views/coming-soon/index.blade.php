@extends('layouts.app')

@section('content')
    <div class="card p-5 text-center">
        <h1>{{ $module }}</h1>
        <p class="lead text-muted">Coming Soon</p>
        <p class="text-muted">This module is planned for a later {{ config('app.name') }} version.</p>
    </div>
@endsection

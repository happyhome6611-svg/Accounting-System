<div class="d-flex flex-wrap gap-2 mb-4" aria-label="Accounting report navigation">
    <a class="btn btn-outline-secondary" href="{{ route('reports', $filters) }}">Back to Reports</a>
    @foreach (['reports.ledger' => 'General Ledger', 'reports.trial' => 'Trial Balance', 'reports.profit-loss' => 'Profit & Loss', 'reports.balance-sheet' => 'Balance Sheet'] as $route => $label)
        @if ($route !== 'reports.ledger' || isset($filters['account_id']))
            <a class="btn {{ request()->routeIs($route) ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route($route, $filters) }}">{{ $label }}</a>
        @endif
    @endforeach
</div>

<div class="card bg-light p-3 mb-4">
    <div class="row g-2">
        <div class="col-md-6 col-xl-3"><strong>Company:</strong> {{ $c->name }}</div>
        <div class="col-md-6 col-xl-3"><strong>Branch:</strong> {{ $branchLabel }}</div>
        <div class="col-md-6 col-xl-3"><strong>Financial Year:</strong> {{ $financialYearLabel }}</div>
        <div class="col-md-6 col-xl-3"><strong>Currency:</strong> {{ $money->label($currency) }}</div>
        <div class="col-md-6 col-xl-3"><strong>Period:</strong> {{ $period }}</div>
    </div>
</div>
